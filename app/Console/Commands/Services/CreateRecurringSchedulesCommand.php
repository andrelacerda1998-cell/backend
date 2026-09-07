<?php

namespace App\Console\Commands\Services;

use App\Models\Schedule\Schedule;
use App\Services\Schedule\CreateNextRecurrence;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Continua as series de agendamentos que se repetem.
 *
 * Corre uma vez por dia e procura marcacoes com regra de repeticao cujo dia ja
 * passou. Para cada uma cria a seguinte, se ainda nao existir.
 *
 * Uma de cada vez, e so depois de a anterior acontecer: e o que evita reservar
 * a agenda do tecnico e cobrar o cliente meses a frente por servicos que ele
 * ainda pode nao querer.
 */
class CreateRecurringSchedulesCommand extends Command
{
    protected $signature = 'schedules:create-recurring {--days=7 : Quantos dias para tras procurar}';

    protected $description = 'Cria a marcacao seguinte dos agendamentos recorrentes que ja se realizaram';

    public function handle(CreateNextRecurrence $createNext): int
    {
        $since = Carbon::today('Europe/Lisbon')->subDays((int) $this->option('days'));

        $schedules = Schedule::query()
            ->whereNotNull('recurrence')
            ->whereDate('scheduled_day', '<', Carbon::today('Europe/Lisbon'))
            ->whereDate('scheduled_day', '>=', $since)
            // Uma marcacao que o tecnico nunca chegou a confirmar nao e motivo
            // para continuar a serie: o cliente ficaria com marcacoes a nascer
            // de um servico que nao aconteceu.
            ->where('is_pending', false)
            ->get();

        $created = 0;
        foreach ($schedules as $schedule) {
            if ($createNext->forSchedule($schedule)) {
                $created++;
            }
        }

        $this->info("Agendamentos recorrentes criados: {$created} (de {$schedules->count()} candidatos)");

        return self::SUCCESS;
    }
}
