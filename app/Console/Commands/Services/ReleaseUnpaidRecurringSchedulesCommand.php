<?php

namespace App\Console\Commands\Services;

use App\Models\Schedule\Schedule;
use App\Notifications\Customer\RecurringScheduleReleasedNotification;
use App\Services\Schedule\RecurrencePaymentReminderWindow;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Liberta o horario de uma ocorrencia de serie que ficou por pagar.
 *
 * A regra e a que a app promete no checkout: o horario fica guardado para o
 * cliente ate 48h antes do servico. Passado esse limite sem pagamento, volta a
 * ficar disponivel na agenda -- o tecnico nao pode perder a manha por causa de
 * uma marcacao que ninguem confirmou.
 *
 * O cliente e avisado: uma marcacao que desaparece sem explicacao e alguem a
 * espera do tecnico no proprio dia.
 */
class ReleaseUnpaidRecurringSchedulesCommand extends Command
{
    protected $signature = 'schedules:release-unpaid';

    protected $description = 'Liberta o horario das ocorrencias de serie por pagar a menos de 48h do servico';

    public function handle(): int
    {
        $now = Carbon::now('Europe/Lisbon');

        $candidates = Schedule::query()
            ->whereNotNull('recurrence_parent_id')
            ->whereNull('service_id')
            // Só o que está para acontecer: o passado já não tem horário a
            // libertar, e apagá-lo tiraria histórico à série.
            ->whereDate('scheduled_day', '>=', $now->copy()->toDateString())
            ->whereDate('scheduled_day', '<=', $now->copy()->addDays(3)->toDateString())
            ->with(['customer', 'serviceType'])
            ->get();

        $released = 0;
        foreach ($candidates as $schedule) {
            $startsAt = $this->startsAt($schedule);
            if (! $startsAt) {
                continue;
            }

            // Fora da janela de aviso e ainda longe: há tempo, fica reservado.
            if (! RecurrencePaymentReminderWindow::hasPassed($startsAt, $now)) {
                continue;
            }

            $customer = $schedule->customer;

            // Soft delete: o horário deixa de contar na agenda do técnico e a
            // marcação sai da lista do cliente, mas a série continua a existir
            // para quem for ver o que aconteceu.
            $schedule->delete();
            $released++;

            if ($customer) {
                try {
                    $customer->notify(new RecurringScheduleReleasedNotification($schedule));
                } catch (Throwable $e) {
                    // Uma falha de push não pode deixar o horário preso.
                    report($e);
                }
            }
        }

        $this->info("Horarios libertados por falta de pagamento: {$released} (de {$candidates->count()} candidatos)");

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
