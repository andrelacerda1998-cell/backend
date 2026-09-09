<?php

namespace App\Services\Schedule;

use App\Models\Schedule\Schedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Cria a marcacao seguinte de um agendamento que se repete.
 *
 * A serie avanca uma de cada vez, depois de a ocorrencia atual se realizar. Nao
 * se reserva a agenda do tecnico nem se cobra meses a frente, e o cliente para
 * a serie simplesmente cancelando a proxima.
 *
 * A nova marcacao nasce PENDENTE - o tecnico tem de a confirmar como confirmou
 * a primeira. Dar por adquirida a disponibilidade dele daqui a um mes seria
 * marcar-lhe o trabalho a revelia.
 */
class CreateNextRecurrence
{
    /**
     * @return Schedule|null a marcacao criada, ou null quando nao ha repeticao
     *                       a continuar (sem regra, ou ja existe a seguinte).
     */
    public function forSchedule(Schedule $schedule): ?Schedule
    {
        $recurrence = $schedule->recurrence;
        if (! $recurrence) {
            return null;
        }

        $day = $schedule->scheduled_day ? Carbon::parse($schedule->scheduled_day) : null;
        if (! $day) {
            return null;
        }

        $nextDay = $recurrence->next($day)->toDateString();

        // Idempotencia: este metodo corre a partir de um comando diario. Sem
        // esta verificacao, dois disparos no mesmo dia criavam - e cobravam -
        // duas marcacoes iguais ao cliente.
        //
        // `withTrashed()` porque cancelar e um SOFT delete (ver
        // CancelScheduleController) e parar a serie e cancelar a proxima - nao
        // existe outro botao para isso. Sem isto, a ocorrencia cancelada
        // deixava de contar como existente e o comando recriava-a no dia
        // seguinte; como ele volta a olhar para a mesma ocorrencia passada
        // durante sete dias, o cliente teria de a cancelar todos os dias
        // durante uma semana para a serie parar mesmo.
        //
        // A marcacao ressuscitada nascia `is_pending`, portanto entrava na
        // agenda do profissional e no aviso de pagamento ao cliente - um
        // servico que ele tinha cancelado a pedir-lhe dinheiro.
        $exists = Schedule::query()
            ->withTrashed()
            ->where('customer_id', $schedule->customer_id)
            ->where('service_type_id', $schedule->service_type_id)
            ->whereDate('scheduled_day', $nextDay)
            ->where('scheduled_time_start', $schedule->scheduled_time_start)
            ->exists();

        if ($exists) {
            return null;
        }

        $next = Schedule::query()->create([
            'vendor_id' => $schedule->vendor_id,
            'customer_id' => $schedule->customer_id,
            'service_type_id' => $schedule->service_type_id,
            // O servico (e o pagamento) da ocorrencia seguinte e outro: herdar o
            // service_id ligaria duas marcacoes ao mesmo pagamento.
            'service_id' => null,
            'scheduled_day' => $nextDay,
            'scheduled_time_start' => $schedule->scheduled_time_start,
            'scheduled_time_end' => $schedule->scheduled_time_end,
            'recurrence' => $recurrence->value,
            'recurrence_parent_id' => $schedule->id,
            'is_pending' => true,
        ]);

        Log::info('Recurring schedule created', [
            'from_schedule_id' => $schedule->id,
            'new_schedule_id' => $next->id,
            'recurrence' => $recurrence->value,
            'scheduled_day' => $nextDay,
        ]);

        return $next;
    }
}
