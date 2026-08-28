<?php

namespace App\Http\Controllers\Api\Customer\Address;

use App\Enums\Services\AddressType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Customer\Address\UpdateRequest;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Trait\Customer\ResolvesCustomerAddress;

class UpdateAddressController extends Controller
{
    use ResolvesCustomerAddress;

    public function __invoke(UpdateRequest $request)
    {
        $data = $request->validated();

        $user = auth('api')?->user();

        // Rota guest-accessible: token inválido (expirado/revogado) -> 401 explícito
        // em vez de gravar em silêncio e empurrar o cliente para blocked-by-zone.
        if ($user === null && $request->bearerToken()) {
            return new ApiErrorResponse(new \Exception('Unauthenticated.'), 'Unauthenticated.', 401);
        }

        $customer = $user?->isCustomer() ? $user : null;

        try {
            $addressData = $this->resolveAddressData($data);
        } catch (\Throwable $e) {
            return new ApiErrorResponse($e, 'Address is invalid', 400);
        }

        if ($customer) {
            // Mantém a semântica antiga (edição da morada principal): atualiza a
            // principal existente ou cria a primeira. O CRUD multi-morada vive
            // no AddressesController.
            $main = $customer->addresses()->where('main_address', true)->first();
            $customer->addresses()->updateOrCreate(
                ['id' => $main?->id],
                [...$addressData, 'main_address' => true, 'address_type' => AddressType::HOUSE_ADDRESS],
            );
        }

        return new ApiSuccessResponse(['address' => $addressData, 'allowed_by_zone' => $user?->allowedByZone]);
    }
}
