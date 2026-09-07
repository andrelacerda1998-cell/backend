<?php

namespace App\Http\Controllers\Api\Vendor\Schedule;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Schedule\Schedule;
use Exception;
use Illuminate\Support\Carbon;

/**
 * O tecnico confirma que vai ao servico marcado (botao do lembrete das 72h).
 *
 * Aceitar um agendamento e dizer "fico com ele"; confirmar, dias depois, e
 * dizer "continuo a contar com ele". E a segunda que evita o cliente em casa a
 * espera de alguem que se esqueceu — e, quando nao chega, da tempo a operacao
 * de arranjar outro tecnico.
 */
class ConfirmScheduleAttendanceController extends Controller
{
    public function __invoke(Schedule $schedule): ApiSuccessResponse|ApiErrorResponse
    {
        // Só os seus: sem isto, um técnico confirmava a presença noutro
        // trabalho qualquer (mesma proteção de getScheduleData/storeSchedule).
        if ($schedule->vendor_id !== auth()->user()->vendor?->id) {
            return new ApiErrorResponse(new Exception, 'Schedule not found', 404);
        }

        // Marcação por pagar (ocorrência de série): não é trabalho dele ainda,
        // e confirmá-la dava-lhe por garantido um serviço que pode não existir.
        if (! $schedule->service_id) {
            return new ApiErrorResponse(new Exception, 'Schedule is not confirmed yet', 409);
        }

        // Idempotente: dois toques no botão (ou um push aberto duas vezes) não
        // são um erro — a resposta é a mesma.
        if (! $schedule->vendor_confirmed_at) {
            $schedule->forceFill(['vendor_confirmed_at' => Carbon::now()])->save();
        }

        return new ApiSuccessResponse([
            'schedule' => [
                'id' => $schedule->id,
                'vendor_confirmed_at' => $schedule->vendor_confirmed_at?->toIso8601String(),
            ],
        ]);
    }
}
