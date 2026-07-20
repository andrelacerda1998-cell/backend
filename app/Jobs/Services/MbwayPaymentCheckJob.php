<?php

namespace App\Jobs\Services;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\Service;
use App\Notifications\Customer\Mbway\PaymentExpiredNotification;
use App\Notifications\Customer\Mbway\PaymentSuccessNotification;
use App\Services\Common\Services\MaterializePendingSchedule;
use App\Services\Common\Services\MbwayPaymentResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RwInteractive\PayshopSdk\Enums\Payment\Status;

class MbwayPaymentCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Uma falha transitória (Paylands lento, blip de rede/DB, restart do worker) deixava de
    // quebrar a cadeia de verificação e prender o serviço em PENDING com dinheiro cativado.
    // O job é idempotente (guards de PAID e estado terminal no handle), por isso repetir é seguro.
    public int $tries = 3;

    private int $attempts;

    public function __construct(private readonly Service $service, int $attempts = 1)
    {
        $this->attempts = $attempts;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function failed(\Throwable $e): void
    {
        Log::error('MbwayPaymentCheckJob falhou após retries — serviço pode ficar preso em PENDING', [
            'service_id' => $this->service->id,
            'attempts' => $this->attempts,
            'error' => $e->getMessage(),
        ]);
    }

    public function handle(): void
    {
        $this->service->refresh();

        // Idempotency guard: if the payment was already confirmed (e.g. this job was re-run by a
        // queue retry, or the status endpoint already processed it), do not notify the vendor again.
        if ($this->service->payment_status === PaymentStatus::PAID) {
            return;
        }

        // Terminal-state guard: if the service already reached a terminal state (e.g. the customer
        // canceled before confirming the MBWay push in their bank app), never capture/confirm it or
        // materialize its schedule — that would resurrect a dead service and charge the customer.
        if (MbwayPaymentResolver::isTerminal($this->service)) {
            return;
        }

        $paymentOrder = $this->service->paymentOrder;
        $paymentOrder = $paymentOrder?->updateData();

        if (! $paymentOrder) {
            $this->confirmPayment();

            return;
        }

        if (! in_array($paymentOrder->status, [Status::SUCCESS, Status::PENDING_CONFIRMATION, Status::REFUNDED])) {
            // 24 attempts * 10 seconds = 240 seconds = 4 minutes (max time to wait for payment)
            if ($this->attempts <= 24) {
                MbwayPaymentCheckJob::dispatch($this->service, $this->attempts + 1)->delay(
                    now()->addSeconds(config('services.request.mbway_payment_check_timeout'))
                );

                return;
            }

            // Payment never confirmed within the window — expire the service. A transição corre sob
            // lockForUpdate (igual ao poll checkPaymentStatus) e re-verifica os guards: se o poll já
            // terminou/pagou este serviço na janela do updateData() HTTP, o job não o volta a mutar
            // (evita o reembolso de wallet EM DOBRO). O save do estado terminal dispara o
            // ServiceObserver, que liberta o hold MBWay e reembolsa — agora serializado pelo lock.
            $expired = DB::transaction(function () {
                $locked = Service::whereKey($this->service->id)->lockForUpdate()->first();
                if ($locked->payment_status === PaymentStatus::PAID || MbwayPaymentResolver::isTerminal($locked)) {
                    return false;
                }

                $locked->status = ServiceStatus::EXPIRED_MBWAY;
                $locked->status_justification = 'internal/services.mbway.expired';
                $locked->pending_schedule_data = null;
                $locked->save();

                return true;
            });

            if ($expired && Cache::add('payment-expired-notified:'.$this->service->id, true, now()->addHours(6))) {
                // Guard atómico: notifica uma só vez por serviço (defesa contra dispatch duplo
                // job-vs-reaper ou re-execução), para o mesmo push não ir duas vezes.
                $this->service->customer->notify(new PaymentExpiredNotification($this->service->refresh()));
            }

            return;
        }

        if ($paymentOrder->status === Status::REFUNDED) {
            // Customer refused the MBWay push in their bank app. Transição sob lockForUpdate com
            // re-check dos guards (mesmo padrão do poll) para nunca reembolsar duas vezes numa
            // corrida job-vs-poll. markRefused grava o estado terminal → ServiceObserver liberta/
            // reembolsa o hold, agora serializado pelo lock.
            DB::transaction(function () {
                $locked = Service::whereKey($this->service->id)->lockForUpdate()->first();
                if ($locked->payment_status === PaymentStatus::PAID || MbwayPaymentResolver::isTerminal($locked)) {
                    return;
                }

                app(MbwayPaymentResolver::class)->markRefused($locked);
            });

            return;
        }

        $this->confirmPayment();
    }

    /**
     * Payment confirmed: mark PAID, materialize any deferred schedule (or notify the vendor and
     * arm the accept-timeout job for immediate services), and notify the customer.
     */
    private function confirmPayment(): void
    {
        // Transição sob lockForUpdate (igual ao poll): re-lê o serviço e re-verifica os guards
        // imediatamente antes de mutar, para o job e o poll nunca confirmarem/materializarem em
        // paralelo. Preserva a ordem "materializar ANTES de markPaid" (F-05): se a materialização
        // lançar, a transação faz rollback e o retry do job NÃO vê PAID — repete tudo.
        // MaterializePendingSchedule é idempotente (guarda scheduleExists).
        $confirmed = DB::transaction(function () {
            $locked = Service::whereKey($this->service->id)->lockForUpdate()->first();
            if ($locked->payment_status === PaymentStatus::PAID || MbwayPaymentResolver::isTerminal($locked)) {
                return null;
            }

            app(MaterializePendingSchedule::class)->handle($locked);
            app(MbwayPaymentResolver::class)->markPaid($locked);

            return $locked;
        });

        if ($confirmed) {
            $confirmed->customer->notify(new PaymentSuccessNotification($confirmed));
        }
    }
}
