<?php

namespace App\Console\Commands\Services;

use App\Models\Schedule\Schedule;
use App\Notifications\Customer\ConfirmRecurringScheduleNotification;
use App\Services\Schedule\RecurrencePaymentReminderWindow;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Avisa o cliente de que a proxima ocorrencia de uma serie precisa de ser
 * confirmada e paga.
 *
 * Numa serie cada servico e pago a parte: a ocorrencia seguinte nasce sem
 * pagamento e so acontece se o cliente a confirmar. Este comando garante que
 * ele e avisado com folga -- entre 72h e 48h antes -- e nao no proprio dia.
 */
class RemindRecurringSchedulePaymentCommand extends Command
{
    protected $signature = 'schedules:remind-recurring-payment';

    protected $description = 'Lembra o cliente de confirmar e pagar a proxima ocorrencia de uma serie (72h-48h antes)';

    public function handle(): int
    {
        $now = Carbon::now('Europe/Lisbon');

        $schedules = Schedule::query()
            // Só ocorrências nascidas de uma série: as primeiras marcações já
            // foram pagas no checkout.
            ->whereNotNull('recurrence_parent_id')
            // Sem serviço associado = sem pagamento feito. Assim que o cliente
            // confirma, o serviço é criado e a marcação sai desta lista.
            ->whereNull('service_id')
            ->whereNull('payment_reminder_sent_at')
            ->whereDate('scheduled_day', '>=', $now->copy()->toDateString())
            ->whereDate('scheduled_day', '<=', $now->copy()->addDays(4)->toDateString())
            ->with(['customer', 'serviceType'])
            ->get();

        $sent = 0;
        foreach ($schedules as $schedule) {
            $startsAt = $this->startsAt($schedule);
            if (! $startsAt) {
                continue;
            }

            // Fora da janela e ainda por chegar: volta na próxima hora.
            if (! RecurrencePaymentReminderWindow::isOpen($startsAt, $now)
                && ! RecurrencePaymentReminderWindow::hasPassed($startsAt, $now)) {
                continue;
            }

            $customer = $schedule->customer;
            if (! $customer) {
                continue;
            }

            $customer->notify(new ConfirmRecurringScheduleNotification($schedule));

            // Marca ANTES de contar: o comando corre de hora a hora durante uma
            // janela de 24h e sem isto o cliente recebia o mesmo aviso 24 vezes.
            $schedule->forceFill(['payment_reminder_sent_at' => $now])->save();
            $sent++;
        }

        $this->info("Lembretes de pagamento enviados: {$sent} (de {$schedules->count()} candidatos)");

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
