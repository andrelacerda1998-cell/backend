<?php

namespace App\Console\Commands\Vendors;

use App\Models\Vendor;
use App\Notifications\Vendor\IncompleteProfileReminderNotification;
use App\Services\Vendor\ZoneDemand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Lembra os técnicos que ficaram a meio do "Completar o teu perfil".
 *
 * Criar conta e completar o perfil são fases separadas; quem sai a meio da
 * segunda não recebe pedidos e nada o traz de volta se não abrir a app — o
 * banner da Home só é visto por quem já lá está. Este comando é o único
 * mecanismo de re-engagement dessa fase.
 *
 * IDEMPOTÊNCIA: o par (vendor, dia) é gravado ANTES do envio, com UNIQUE na BD.
 * Se a linha já existir, o insertOrIgnore devolve 0 e nada é enviado — correr o
 * comando duas vezes no mesmo dia, ou em paralelo, nunca duplica um lembrete.
 */
class RemindIncompleteProfileCommand extends Command
{
    protected $signature = 'vendors:remind-incomplete-profile
                            {--dry-run : Mostra o que seria enviado, sem enviar nem gravar}';

    protected $description = 'Lembra os técnicos com o perfil por completar (D+1 e D+3 após o registo).';

    /** Dias após o registo em que se avisa. Dois toques e mais nada: insistir afasta. */
    private const THRESHOLDS = [1, 3];

    public function handle(ZoneDemand $zoneDemand): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $sent = 0;
        $skipped = 0;

        foreach (self::THRESHOLDS as $days) {
            $vendors = Vendor::query()
                ->whereBetween('created_at', [
                    now()->subDays($days)->startOfDay(),
                    now()->subDays($days)->endOfDay(),
                ])
                ->with(['user', 'documents', 'allowedZones'])
                ->get()
                // can_accept_service resume TODAS as condições do wizard; quem já
                // passa não precisa de lembrete nenhum.
                ->reject(fn (Vendor $vendor) => (bool) $vendor->can_accept_service);

            foreach ($vendors as $vendor) {
                if (! $vendor->user) {
                    $skipped++;

                    continue;
                }

                // Sem dispositivo registado o push não chega; o email ainda vai,
                // por isso não se salta — só se regista para leitura dos logs.
                $missingSteps = $this->countMissingSteps($vendor);

                if ($missingSteps === 0) {
                    $skipped++;

                    continue;
                }

                if ($dryRun) {
                    $this->line(sprintf(
                        '[dry-run] vendor #%d (D+%d): %d passos em falta',
                        $vendor->id, $days, $missingSteps
                    ));
                    $sent++;

                    continue;
                }

                // Marca ANTES de enviar: em caso de falha do push, o lembrete
                // deste dia não volta a ser tentado. Preferimos perder um aviso
                // a arriscar enviar o mesmo duas vezes.
                $inserted = DB::table('vendor_onboarding_reminders')->insertOrIgnore([
                    'vendor_id' => $vendor->id,
                    'days_after_signup' => $days,
                    'notified_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($inserted === 0) {
                    $skipped++;

                    continue;
                }

                try {
                    $vendor->user->notify(new IncompleteProfileReminderNotification(
                        $missingSteps,
                        $zoneDemand->recentRequestCount($vendor),
                    ));
                    $sent++;
                } catch (\Throwable $e) {
                    // Uma falha de envio nunca pode parar a série toda.
                    report($e);
                    Log::warning('Falha ao lembrar perfil incompleto', [
                        'vendor_id' => $vendor->id,
                        'days' => $days,
                    ]);
                }
            }
        }

        $this->info(sprintf('Lembretes enviados: %d | ignorados: %d', $sent, $skipped));

        return self::SUCCESS;
    }

    /** Passos do wizard ainda por fazer — espelha CompleteYourProfile na app. */
    private function countMissingSteps(Vendor $vendor): int
    {
        $missing = 0;

        if (! $vendor->user?->hasVerifiedPhoneNumber() || ! $vendor->user?->hasVerifiedEmail()) {
            $missing++;
        }

        if ($vendor->allowedZones()->doesntExist()) {
            $missing++;
        }

        if ($vendor->iban === null) {
            $missing++;
        }

        if (! str_contains((string) $vendor->at_user, '/')) {
            $missing++;
        }

        if (! $vendor->all_documents_verified) {
            $missing++;
        }

        return $missing;
    }
}
