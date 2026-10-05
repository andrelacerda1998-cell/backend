<?php

namespace App\Console\Commands\Services;

use App\Enums\Services\ServiceStatus;
use App\Models\Service;
use App\Notifications\Customer\ServiceAutoClosedNotification;
use App\Services\Common\Services\CloseService;
use Illuminate\Console\Command;

/**
 * Fecha (cobra e paga) os serviços concluídos há mais de
 * `Service::HORAS_ATE_FECHO_AUTOMATICO` horas sem confirmação nem problema.
 *
 * Sem isto, o pagamento do técnico dependia de um toque do cliente, que não
 * tinha razão nenhuma para o dar: o serviço ficava em `Finished`, o técnico
 * não recebia, e a cativação acabava por caducar — dinheiro perdido para os
 * dois lados.
 *
 * É o mesmo fecho do botão do cliente (`CloseService::close`): captura, e só
 * depois paga. Se a captura falhar fica em `ClosedPendingPayment`, como
 * sempre, à espera da repetição manual — nunca se paga o que não se cobrou.
 *
 * Um problema reportado tira o serviço da lista: aí decide uma pessoa.
 */
class AutoCloseFinishedServicesCommand extends Command
{
    protected $signature = 'services:auto-close
                            {--dry-run : Mostra o que fecharia, sem fechar}';

    protected $description = 'Fecha e cobra os serviços concluídos há mais de 24h sem problema reportado.';

    public function handle(): int
    {
        $limite = now()->subHours(Service::HORAS_ATE_FECHO_AUTOMATICO);

        $servicos = Service::query()
            ->where('status', ServiceStatus::FINISHED)
            ->whereNotNull('finished_at')
            ->where('finished_at', '<=', $limite)
            ->whereNull('problem_reported_at')
            ->orderBy('finished_at')
            ->limit(200)
            ->get();

        $fechados = 0;
        $pendentes = 0;

        foreach ($servicos as $service) {
            if ($this->option('dry-run')) {
                $this->line("[dry-run] serviço #{$service->id} concluído em {$service->finished_at}");
                continue;
            }

            try {
                $resultado = (new CloseService($service))->close();
            } catch (\Throwable $e) {
                // 409 = alguém fechou entretanto (o cliente, por exemplo). Os
                // outros reportam-se e o serviço volta a ser tentado na volta
                // seguinte.
                if ((int) $e->getCode() !== 409) {
                    report($e);
                }
                continue;
            }

            $service->refresh();
            $service->forceFill(['auto_closed_at' => now()])->save();

            if ($resultado === ServiceStatus::CLOSED) {
                $fechados++;
                CloseService::anunciarFecho($service);

                try {
                    $service->customer?->notify(new ServiceAutoClosedNotification($service));
                } catch (\Throwable $e) {
                    report($e);
                }
            } else {
                $pendentes++;
            }
        }

        $this->info("Fechados: {$fechados} | captura por repetir: {$pendentes}");

        return self::SUCCESS;
    }
}
