<?php

namespace App\Http\Controllers\Api\Vendor\Services;

use App\Exceptions\Api\Common\Service\ServiceNotFound;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ServiceRateRequest;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Service;

class VendorRateServiceController extends Controller
{
    /**
     * O 404 e o 409 saem por `ApiErrorResponse` e nao por `throw`.
     *
     * O `ServiceNotFound` declara `getStatus() = 404`, mas esse metodo so e
     * lido pelo `ApiErrorResponse`: lancada solta, a excecao chegava ao
     * handler global como uma `\Exception` qualquer e a resposta era 500.
     * E o "ja avaliou" construia um `ApiErrorResponse` sem codigo, ficando
     * com o 500 que e o valor por omissao — uma regra de negocio cumprida a
     * responder como se o servidor tivesse rebentado.
     */
    public function __invoke(ServiceRateRequest $request, Service $service)
    {
        if ($service->vendor_id !== auth()->user()->vendor->id) {
            return new ApiErrorResponse(new ServiceNotFound);
        }

        if ($service->rating_by_vendor !== null) {
            return new ApiErrorResponse(null, 'You have already rated this service', 409);
        }

        $service->update(['rating_by_vendor' => $request->get('rate')]);

        return new ApiSuccessResponse(compact('service'));
    }
}
