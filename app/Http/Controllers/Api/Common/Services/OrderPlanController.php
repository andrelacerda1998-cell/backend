<?php

namespace App\Http\Controllers\Api\Common\Services;

use App\DTO\Services\AddressCoordinatesDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\Common\PlanOrderRequest;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Service;
use App\Models\User;
use App\Services\Matching\PlanoDeVisitas;

/**
 * Como um cesto se vai dividir em visitas, antes de pedir.
 *
 * Público, porque o convidado vê o plano antes de confirmar o telemóvel. Com
 * sessão, usa a conta (as contas de teste só veem técnicos de teste, como em
 * todo o matching).
 */
class OrderPlanController extends Controller
{
    public function __invoke(PlanOrderRequest $request, PlanoDeVisitas $plano): ApiSuccessResponse
    {
        $cliente = auth('api')->user() ?? tap(new User, fn (User $u) => $u->is_test = false);
        $agendado = $request->boolean('scheduled');

        $resultado = $plano->planear(
            PlanoDeVisitas::linhasDe($request->input('items')),
            new AddressCoordinatesDTO((float) $request->input('latitude'), (float) $request->input('longitude')),
            $request->input('city'),
            $cliente,
            ! $agendado,
            $agendado
                ? Service::instanteDe($request->input('schedule.scheduled_day'), $request->input('schedule.scheduled_time_start'))
                : null,
        );

        return new ApiSuccessResponse(PlanoDeVisitas::payload($resultado));
    }
}
