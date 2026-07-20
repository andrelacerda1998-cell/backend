<?php

namespace App\Console\Commands;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\Service;
use App\Models\VoucherUsage;
use App\Notifications\Customer\Mbway\PaymentExpiredNotification;
use App\Notifications\Customer\Mbway\PaymentSuccessNotification;
use App\Services\Common\Services\MaterializePendingSchedule;
use App\Services\Common\Services\MbwayPaymentResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RwInteractive\PayshopSdk\Enums\Payment\Status;

/**
 * Reaper de serviços de cartão presos em PENDING_3DS (o callback do 3DS nunca chegou).
 *
 * Espelha o caminho feliz (SuccessController) e o padrão do MbwayPaymentCheckJob:
 *  - lê o estado da order no Payshop (details() via updateData() — read-only);
 *  - se afinal ficou pago, RESGATA (markPaid + materializar) para não expirar um serviço pago;
 *  - senão, expira LOCALMENTE (EXPIRED_3DS) e reembolsa a parte de carteira + liberta o voucher.
 *
 * NÃO chama cancel()/refund() no Payshop — EXPIRED_3DS está de propósito FORA da lista do
 * ServiceObserver, por isso não há chamada remota. O cativo remoto (se existir) expira sozinho.
 * O cancel() proativo do cativo fica como melhoria futura (precisa de sandbox — item 15).
 */
class ExpirePending3dsCommand extends Command
{
    protected $signature = 'services:expire-pending-3ds {--minutes=10 : Idade mínima em minutos para tratar}';

    protected $description = 'Resolve serviços presos em PENDING_3DS: confirma os pagos tardiamente, expira os não confirmados.';

    /** Estados do Payshop que contam como pagamento confirmado (mesma whitelist do SuccessController). */
    private const PAID_STATUSES = [Status::SUCCESS, Status::PENDING_CONFIRMATION];

    public function handle(MbwayPaymentResolver $resolver, MaterializePendingSchedule $materializer): int
    {
        $cutoff = now()->subMinutes((int) $this->option('minutes'));

        Service::query()
            ->where('status', ServiceStatus::PENDING_3DS)
            ->where('payment_status', PaymentStatus::PENDING)
            ->where('created_at', '<', $cutoff)
            ->each(function (Service $service) use ($resolver, $materializer) {
                try {
                    $this->process($service, $resolver, $materializer);
                } catch (\Throwable $e) {
                    // Um serviço bloqueado não deve abortar o resto do lote.
                    Log::warning('services:expire-pending-3ds: skipped service', [
                        'service_id' => $service->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });

        return self::SUCCESS;
    }

    private function process(Service $service, MbwayPaymentResolver $resolver, MaterializePendingSchedule $materializer): void
    {
        // 1) Consultar o Payshop FORA da transação (details() é HTTP — não segurar o lock na rede).
        $order = $service->paymentOrder;

        try {
            $order = $order?->updateData(); // sincroniza o status local a partir do Payshop
        } catch (\Throwable $e) {
            // Payshop indisponível: não decidir agora; tenta no próximo tick.
            report($e);

            return;
        }

        $paidLate = $order && in_array($order->status, self::PAID_STATUSES, true);

        // 2) Transição sob lock (evita corrida com um callback tardio do SuccessController).
        $outcome = DB::transaction(function () use ($service, $paidLate, $resolver) {
            $locked = Service::whereKey($service->getKey())->lockForUpdate()->first();

            if (! $locked || $locked->payment_status === PaymentStatus::PAID) {
                return 'already_paid';
            }

            if ($locked->status !== ServiceStatus::PENDING_3DS) {
                return 'not_pending';
            }

            if ($paidLate) {
                // Resgatar: PAID + PENDING_3DS -> PENDING (materialização fica para depois do commit).
                $resolver->markPaid($locked);

                return 'confirmed';
            }

            // Não confirmado após o timeout → expirar. Reembolso LOCAL apenas (sem Payshop):
            // a carteira (se foi usada no checkout) e o voucher. O card nunca foi capturado.
            if ($locked->credit_used > 0) {
                $locked->customer->deposit($locked->credit_used, [
                    'description' => 'internal/services.refunds.refused',
                    'type' => 'internal/services.transactions_type.refund',
                    'class' => 'App\\Models\\User',
                    'id' => $locked->customer_id,
                    'admin_description' => 'internal/services.refunds.refused',
                ]);
                $locked->payment_status = PaymentStatus::REFUNDED;
            } else {
                $locked->payment_status = PaymentStatus::CANCELED;
            }

            $locked->status = ServiceStatus::EXPIRED_3DS;
            $locked->status_justification = 'internal/services.threeds.expired';
            $locked->pending_schedule_data = null;
            $locked->save();

            VoucherUsage::where('service_id', $locked->id)->delete();

            return 'expired';
        });

        // 3) Efeitos externos DEPOIS do commit (materialização/eventos/pushes).
        $service->refresh();

        if ($outcome === 'confirmed') {
            $materializer->handle($service);
            $service->customer->notify(new PaymentSuccessNotification($service));
        } elseif ($outcome === 'expired' && Cache::add('payment-expired-notified:'.$service->id, true, now()->addHours(6))) {
            // Guard atómico: notifica uma só vez por serviço (mesma chave do MbwayPaymentCheckJob),
            // para o mesmo push não ir duas vezes numa corrida job-vs-reaper.
            $service->customer->notify(new PaymentExpiredNotification($service));
        }
    }
}
