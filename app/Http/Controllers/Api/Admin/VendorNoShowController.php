<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Service;
use App\Services\Common\Services\RegisterVendorNoShow;
use App\Services\Common\Services\VendorNoShowPolicy;
use Exception;

/**
 * A operação dá um serviço como falta do técnico.
 *
 * O gatilho é humano de propósito: o `services:detect-no-show` já avisa o
 * backoffice 20 minutos depois da hora, mas quem confirma é uma pessoa — metade
 * das "faltas" são o cliente que não estava em casa ou uma morada errada, e
 * tirar dinheiro a alguém por engano não se desfaz com um rollback.
 *
 * Consumido pelo backoffice (ver App\Http\Middleware\AdminApiToken).
 */
class VendorNoShowController extends Controller
{
    public function __invoke(Service $service): ApiSuccessResponse|ApiErrorResponse
    {
        if (! VendorNoShowPolicy::isPenalizable($service)) {
            // 409 e não 404: o serviço existe, o que não se pode é penalizá-lo —
            // ou já foi penalizado, ou o técnico chegou a aparecer.
            return new ApiErrorResponse(
                new Exception,
                $service->vendor_no_show_at
                    ? 'Service already marked as a no-show'
                    : 'Service is not in a state where a no-show can be declared',
                409,
            );
        }

        $penalty = (new RegisterVendorNoShow($service))->handle();

        if ($penalty === null) {
            // Perdeu a corrida com outra declaração entre a verificação e o
            // registo (dois operadores, ou um duplo clique).
            return new ApiErrorResponse(new Exception, 'Service already marked as a no-show', 409);
        }

        return new ApiSuccessResponse([
            'service' => [
                'id' => $service->id,
                'vendor_no_show_at' => $service->fresh()->vendor_no_show_at?->toIso8601String(),
                'penalty' => $penalty,
            ],
        ]);
    }
}
