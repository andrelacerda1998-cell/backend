<?php

namespace App\Console\Commands\Services;

use App\Models\Schedule\Schedule;
use App\Notifications\Vendor\ScheduleAttendanceReminderNotification;
use App\Services\Schedule\VendorAttendanceReminderWindow;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Lembra o tecnico, 72h antes, dos servicos que tem marcados.
 *
 * Um agendamento aceite ha semanas e facil de esquecer, e quem fica a espera em
 * casa e o cliente. A 72h ainda ha tempo de arranjar outro tecnico se ele ja
 * nao puder — mais tarde, ja nao ha.
 */
class RemindVendorScheduleAttendanceCommand extends Command
{
    protected $signature = 'schedules:remind-vendor-attendance';

    protected $description = 'Lembra o tecnico dos servicos marcados para dentro de 72h e pede-lhe confirmacao';

    public function handle(): int
    {
        $now = Carbon::now('Europe/Lisbon');

        $schedules = Schedule::query()
            // Só marcações pagas: as ocorrências de série por confirmar ainda
            // não são trabalho dele, e avisá-lo delas seria prometer-lhe um
            // serviço que pode nunca acontecer.
            ->whereNotNull('service_id')
            ->whereNull('vendor_reminder_sent_at')
            ->whereDate('scheduled_day', '>=', $now->copy()->toDateString())
            ->whereDate('scheduled_day', '<=', $now->copy()->addDays(4)->toDateString())
            ->with(['vendor.user', 'serviceType'])
            ->get();

        $sent = 0;
        foreach ($schedules as $schedule) {
            $startsAt = $this->startsAt($schedule);
            if (! $startsAt || ! VendorAttendanceReminderWindow::isOpen($startsAt, $now)) {
                continue;
            }

            $user = $schedule->vendor?->user;
            if (! $user) {
                continue;
            }

            // Marcar ANTES de enviar: o comando corre de hora a hora dentro de
            // uma janela de 12h e, sem isto, o técnico recebia doze avisos.
            $schedule->forceFill(['vendor_reminder_sent_at' => $now])->save();
            $sent++;

            try {
                $user->notify(new ScheduleAttendanceReminderNotification($schedule));
            } catch (Throwable $e) {
                report($e);
            }
        }

        $this->info("Lembretes de presenca enviados: {$sent} (de {$schedules->count()} candidatos)");

        return self::SUCCESS;
    }

    private function startsAt(Schedule $schedule): ?Carbon
    {
        if (! $schedule->scheduled_day) {
            return null;
        }

        $day = Carbon::parse($schedule->scheduled_day, 'Europe/Lisbon')->startOfDay();
        $time = (string) ($schedule->scheduled_time_start ?? '00:00:00');

        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)/', $time, $parts) !== 1) {
            return $day;
        }

        return $day->setTime((int) $parts[1], (int) $parts[2]);
    }
}
