<?php

namespace App\Http\Controllers\Api\Customer\Address;

use App\Enums\Services\AddressType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Customer\Address\StoreAddressRequest;
use App\Http\Requests\Api\Customer\Address\UpdateRequest;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Address;
use App\Trait\Customer\ResolvesCustomerAddress;
use Illuminate\Support\Facades\DB;

/**
 * Gestão das várias moradas do cliente (multi-morada). Um proprietário de vários
 * alojamentos guarda uma morada por casa e escolhe qual usar em cada pedido.
 * Uma e só uma é a principal (main_address) — a que serve de omissão.
 */
class AddressesController extends Controller
{
    use ResolvesCustomerAddress;

    public function index()
    {
        $customer = auth('api')->user();

        return new ApiSuccessResponse([
            'addresses' => $customer->addresses()
                ->orderByDesc('main_address')
                ->orderBy('id')
                ->get(),
        ]);
    }

    public function store(StoreAddressRequest $request)
    {
        $customer = auth('api')->user();

        try {
            $addressData = $this->resolveAddressData($request->validated());
        } catch (\Throwable $e) {
            return new ApiErrorResponse($e, 'Address is invalid', 400);
        }

        // A primeira morada é sempre principal; as seguintes só se pedido.
        $isFirst = ! $customer->addresses()->exists();
        $makeMain = $isFirst || $request->boolean('main_address');

        $address = DB::transaction(function () use ($customer, $addressData, $makeMain) {
            if ($makeMain) {
                $customer->addresses()->update(['main_address' => false]);
            }

            return $customer->addresses()->create([
                ...$addressData,
                'main_address' => $makeMain,
                'address_type' => AddressType::HOUSE_ADDRESS,
            ]);
        });

        return new ApiSuccessResponse(['address' => $address], statusCode: 201);
    }

    public function update(UpdateRequest $request, Address $address)
    {
        if (($resp = $this->authorizeOwner($address)) !== null) {
            return $resp;
        }

        try {
            $addressData = $this->resolveAddressData($request->validated());
        } catch (\Throwable $e) {
            return new ApiErrorResponse($e, 'Address is invalid', 400);
        }

        // Editar não muda a principal (isso é o setMain); preserva o flag atual.
        unset($addressData['main_address']);
        $address->update($addressData);

        return new ApiSuccessResponse(['address' => $address->fresh()]);
    }

    public function destroy(Address $address)
    {
        if (($resp = $this->authorizeOwner($address)) !== null) {
            return $resp;
        }

        $customer = auth('api')->user();
        $wasMain = (bool) $address->main_address;

        DB::transaction(function () use ($customer, $address, $wasMain) {
            $address->delete();

            // Apagar a principal promove a mais recente das restantes: o cliente
            // nunca fica sem morada de omissão para pedir.
            if ($wasMain) {
                $next = $customer->addresses()->orderByDesc('id')->first();
                $next?->update(['main_address' => true]);
            }
        });

        return new ApiSuccessResponse();
    }

    public function setMain(Address $address)
    {
        if (($resp = $this->authorizeOwner($address)) !== null) {
            return $resp;
        }

        $customer = auth('api')->user();

        DB::transaction(function () use ($customer, $address) {
            $customer->addresses()->update(['main_address' => false]);
            $address->update(['main_address' => true]);
        });

        return new ApiSuccessResponse(['address' => $address->fresh()]);
    }

    /** 404 se a morada não é do cliente autenticado — não revela existência. */
    private function authorizeOwner(Address $address): ?ApiErrorResponse
    {
        if ($address->user_id !== auth('api')->id()) {
            return new ApiErrorResponse(new \Exception('Address not found'), 'Address not found', 404);
        }

        return null;
    }
}
