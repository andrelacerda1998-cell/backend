<?php

namespace App\Console\Commands\Services;

use App\Enums\Services\ServiceStatus;
use App\Models\Schedule\Schedule;
use App\Models\User;
use App\Notifications\Admin\NoShowOpsNotification;
use App\Notifications\Customer\ScheduleDelayedNotification;
use App\Notifications\Vendor\VendorNoShowNudgeNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Deteta não-comparências: um serviço agendado que continua em SCHEDULED depois da
 * hora marcada — o técnico nunca marcou "A caminho" (que o passaria a ACCEPTED).
 *
 * Foi o buraco do incidente de 13/08: o sistema confirmou o agendamento e ninguém
 * apareceu, e nada detetou isso — só o cliente, pelo WhatsApp. Este comando é a rede
 * de segurança que faltava.
 *
 * Escalonamento em três etapas, minutos após a hora marcada:
 *   - vendor  (T+10): nudge ao técnico ("ainda não saíste?") — apanha esquecimentos.
 *   - customer(T+15): tranquiliza o cliente (mais margem, para não alarmar cedo).
 *   - ops     (T+20): alerta o backoffice para um humano agir (contactar/reatribuir/reembolsar).
 *
 * IDEMPOTÊNCIA: o par (serviço, etapa) é gravado ANTES do envio, com UNIQUE, em
 * service_no_show_notifications — cada etapa dispara no máximo uma vez por serviço.
 * Agendamentos cancelados ficam de fora automaticamente (SoftDeletes no Schedule).
 */
class DetectNoShowCommand extends Command
{
    protected $signature = 'services:detect-no-show
                            {--dry-run : Mostra o que seria enviado, sem enviar nem gravar}';

    protected $description = 'Deteta não-comparências (serviço agendado por iniciar depois da hora) e escala ao técnico, cliente e ops.';

    /** Minutos após a hora marcada em que cada etapa dispara. */
    private const STAGES = [
        'vendor' => 10,
        'customer' => 15,
        'ops' => 20,
    ];

    /** Janela de segurança: ignora agendamentos com mais de 24h — dados velhos não se escalam. */
    private const MAX_AGE_HOURS = 24;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $now = now();
        $sent = 0;
        $skipped = 0;

        $schedules = Schedule::query()
            ->where('is_pending', false)
            ->whereHas('service', fn ($q) => $q->where('status', ServiceStatus::SCHEDULED))
            ->with(['service.customer', 'vendor.user', 'serviceType', 'customer'])
            ->get();

        foreach ($schedules as $schedule) {
            $service = $schedule->service;
            if (! $service) {
                continue;
            }

            // Hora marcada como hora local de parede (Portugal), igual ao ScheduleObserver.
            $scheduledAt = Carbon::parse($schedule->scheduled_day, 'Europe/Lisbon')
                ->setTimeFromTimeString($schedule->scheduled_time_start);

            // Ainda não passou a hora, ou é demasiado antigo (cruft) — ignorar.
            if ($scheduledAt->greaterThan($now) || $scheduledAt->lt($now->copy()->subHours(self::MAX_AGE_HOURS))) {
                continue;
            }

            $time = $scheduledAt->format('H:i');

            foreach (self::STAGES as $stage => $minutes) {
                if ($now->lessThan($scheduledAt->copy()->addMinutes($minutes))) {
                    continue;
                }

                if ($dryRun) {
                    $this->line(sprintf('[dry-run] servico #%d etapa %s (marcado %s)', $service->id, $stage, $time));
                    $sent++;

                    continue;
                }

                // Grava ANTES de enviar: se falhar aqui é porque já foi enviado.
                $inserted = DB::table('service_no_show_notifications')->insertOrIgnore([
                    'service_id' => $service->id,
                    'stage' => $stage,
                    'notified_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                if ($inserted === 0) {
                    $skipped++;

                    continue;
                }

                try {
                    $this->dispatchStage($stage, $schedule, $time);
                    $sent++;
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }

        $this->info(sprintf('no-show: enviados=%d ignorados=%d', $sent, $skipped));

        return self::SUCCESS;
    }

    private function dispatchStage(string $stage, Schedule $schedule, string $time): void
    {
        switch ($stage) {
            case 'vendor':
                $schedule->vendor?->user?->notify(new VendorNoShowNudgeNotification($schedule, $time));
                break;

            case 'customer':
                $schedule->service?->customer?->notify(new ScheduleDelayedNotification($schedule, $time));
                break;

            case 'ops':
                $admins = User::query()
                    ->whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'super-admin']))
                    ->get();

                if ($admins->isNotEmpty()) {
                    Notification::send($admins, new NoShowOpsNotification($schedule, $time));
                }
                break;
        }
    }
}
