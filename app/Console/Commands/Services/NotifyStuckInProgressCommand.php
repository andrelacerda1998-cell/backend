<?php

namespace App\Console\Commands\Services;

use App\Enums\Services\ServiceStatus;
use App\Models\Service;
use App\Notifications\Vendor\ServiceStuckInProgressNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Apanha serviços deixados "Em execução" e nunca concluídos.
 *
 * Sem o toque em "Concluir serviço" o serviço não fecha: o técnico não é pago,
 * o cliente não recebe fatura, e a app não diz nada — ele vê os Ganhos a zero e
 * conclui que a plataforma não paga. Este comando é a única coisa que o vai
 * buscar.
 *
 * A medida do tempo é `updated_at` (não há coluna arrived_at). Serve bem: se o
 * técnico está a mexer no serviço — fotos, extras — o updated_at avança e o
 * serviço deixa de ser considerado esquecido, que é exatamente o que se quer.
 *
 * IDEMPOTÊNCIA: o par (serviço, limiar) é gravado ANTES do envio, com UNIQUE.
 */
class NotifyStuckInProgressCommand extends Command
{
    protected $signature = 'services:notify-stuck-in-progress
                            {--dry-run : Mostra o que seria enviado, sem enviar nem gravar}';

    protected $description = 'Avisa os técnicos de serviços deixados em execução (3h e 24h).';

    /**
     * 3h: já passou muito do tempo típico de um serviço — provável esquecimento.
     * 24h: última chamada antes de virar caso para o suporte.
     */
    private const THRESHOLDS = [3, 24];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $sent = 0;
        $skipped = 0;

        foreach (self::THRESHOLDS as $hours) {
            $services = Service::query()
                ->where('status', ServiceStatus::ARRIVED)
                ->where('updated_at', '<=', now()->subHours($hours))
                ->with(['vendor.user', 'serviceType'])
                ->get();

            foreach ($services as $service) {
                $user = $service->vendor?->user;

                if (! $user) {
                    $skipped++;

                    continue;
                }

                $hoursInProgress = (int) $service->updated_at->diffInHours(now());

                if ($dryRun) {
                    $this->line(sprintf(
                        '[dry-run] servico #%d (limiar %dh): parado ha %dh — vendor #%d',
                        $service->id, $hours, $hoursInProgress, $service->vendor->id
                    ));
                    $sent++;

                    continue;
                }

                $inserted = DB::table('service_stuck_notifications')->insertOrIgnore([
                    'service_id' => $service->id,
                    'hours_threshold' => $hours,
                    'notified_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($inserted === 0) {
                    $skipped++;

                    continue;
                }

                try {
                    $user->notify(new ServiceStuckInProgressNotification($service, $hoursInProgress));
                    $sent++;
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }

        $this->info(sprintf('Avisos enviados: %d | ignorados: %d', $sent, $skipped));

        return self::SUCCESS;
    }
}
