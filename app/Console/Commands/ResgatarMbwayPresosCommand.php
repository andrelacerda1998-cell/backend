<?php

namespace App\Console\Commands;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Jobs\Services\MbwayPaymentCheckJob;
use App\Models\Service;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Rede de segurança para os MB Way presos — o equivalente, para o MB Way, do
 * `services:expire-pending-3ds`.
 *
 * Um MB Way é verificado pelo MbwayPaymentCheckJob de 10 em 10 s durante 4
 * minutos, e pelo ecrã de espera da app enquanto está aberto. Se essa cadeia
 * parar — um erro na confirmação esgota as 3 tentativas do job, um worker
 * reiniciado perde o job agendado — nada volta a olhar para o pedido. Foi o
 * pedido 299 (15/10/2026): MB Way autorizado, 20,34 € cativos, pedido em
 * AwaitingPayment para sempre, o cliente sem conseguir pedir mais nada e o
 * técnico escolhido sem saber de nada.
 *
 * Aqui, cada MB Way por resolver há mais de 10 minutos leva a última
 * verificação do job (`MbwayPaymentCheckJob::ultimaVerificacao`):
 *
 *  - autorizado no Payshop → confirma, como se o job o tivesse apanhado;
 *  - recusado → recusa (liberta e avisa);
 *  - nunca respondido → expira (o banco já desistiu dele há muito).
 *
 * Só até 2 horas depois de a ordem nascer. Pedidos mais antigos ficam para a
 * equipa ("Cancelar" no backoffice): confirmar ou expirar um pedido de há dias
 * avisava o cliente e o técnico de uma coisa de que já ninguém se lembra.
 */
class ResgatarMbwayPresosCommand extends Command
{
    protected $signature = 'services:resgatar-mbway-presos';

    protected $description = 'Confirma, recusa ou expira os pagamentos MB Way cuja verificação automática parou';

    /** Depois da janela do job (4 min) e das suas novas tentativas. */
    public const MINUTOS_SEM_RESPOSTA = 10;

    /** Mais antigo do que isto, decide a equipa. */
    public const HORAS_NO_MAXIMO = 2;

    public function handle(): int
    {
        $presos = Service::query()
            ->whereIn('status', [ServiceStatus::AWAITING_PAYMENT, ServiceStatus::PENDING])
            ->where('payment_status', PaymentStatus::PENDING)
            ->whereNotNull('payment_order_id')
            ->whereHas('paymentOrder', fn ($q) => $q
                ->where('created_at', '<=', now()->subMinutes(self::MINUTOS_SEM_RESPOSTA))
                ->where('created_at', '>=', now()->subHours(self::HORAS_NO_MAXIMO)))
            ->orderBy('id')
            ->limit(50)
            ->get();

        $resolvidos = 0;
        $falhas = 0;

        foreach ($presos as $service) {
            $antes = $service->status;

            try {
                MbwayPaymentCheckJob::ultimaVerificacao($service);
            } catch (\Throwable $e) {
                // Payshop em baixo, ou a confirmação voltou a rebentar: tenta-se
                // na próxima volta. Reportado uma vez por hora e por pedido,
                // para um pedido teimoso não encher o registo a cada 5 minutos.
                $falhas++;

                if (Cache::add('mbway-preso-reportado:'.$service->id, true, now()->addHour())) {
                    report($e);
                }

                continue;
            }

            if ($service->refresh()->status !== $antes) {
                $resolvidos++;
            }
        }

        if ($presos->isNotEmpty()) {
            $this->info("MB Way presos: {$presos->count()} · resolvidos: {$resolvidos} · por resolver: {$falhas}");
        }

        return self::SUCCESS;
    }
}
