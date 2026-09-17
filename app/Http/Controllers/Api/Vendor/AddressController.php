<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Enums\Services\AddressType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Vendor\Address\VerifyRequest;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\User;
use App\Services\Common\AddressService;
use Exception;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Spatie\Geocoder\Facades\Geocoder;
use Illuminate\Contracts\Support\Responsable;
use App\Http\Requests\Api\Customer\Address\UpdateRequest;

class AddressController extends Controller
{
    public function __construct(private readonly AddressService $addressService)
    {
    }

    public function get()
    {
        $vendor = auth()->user()->vendor;

        // A morada da EMPRESA, e nao "a primeira que houver". Desde que a de
        // agendamento passou a poder coexistir, a primeira tanto pode ser uma
        // como outra — e o ecra da empresa mostrava a morada errada.
        //
        // Recurso a primeira para quem so tem uma linha sem tipo certo, de
        // antes desta correccao: melhor mostrar o que la esta do que um ecra
        // vazio a quem ja preencheu.
        $address = $vendor->addresses->firstWhere('address_type', AddressType::FISCAL_ADDRESS)
            ?? $vendor->addresses->first();

        return ApiSuccessResponse::make($address);
    }

    public function update(UpdateRequest $request)
    {
        $data = $request->validated();
        $geoAddress = $this->addressService->getCoordinates($data);

        if (!is_array($geoAddress) || empty($geoAddress['address_components'] ?? null) || !isset($geoAddress['lat'], $geoAddress['lng'])) {
            Log::info('address',$geoAddress);
            return new ApiErrorResponse(new \Exception('Could not geocode address.'), 'Address is invalid', 400);
        }

        $vendor = auth()->user()->vendor;

        try {
            $addressData = $this->addressService->transformAddress([
                'address_name' => 'Fiscal Address',
                ...$data,
            ], $geoAddress);
        } catch (\Exception $e) {
            return new ApiErrorResponse($e, "Address is invalid", 400);
        }

        // A chave e o TIPO. Com o array vazio, o `updateOrCreate` agarrava a
        // primeira morada do tecnico fosse ela qual fosse e reescrevia-a,
        // tipo incluido: gravar a morada da empresa apagava a de agendamento,
        // e vice-versa. As duas nunca podiam existir ao mesmo tempo.
        //
        // Isso nao era so arrumacao: a fiscal sustenta a facturacao e a
        // activacao do tecnico, e a de agendamento e de onde sai a distancia
        // (e o preco) de todos os servicos agendados.
        $vendor->addresses()->updateOrCreate(
            ['address_type' => AddressType::FISCAL_ADDRESS],
            [
                ...$addressData,
                'user_id' => $vendor->user->id,
            ],
        );

        return new ApiSuccessResponse(['address' => $addressData]);
    }

    public function verify(VerifyRequest $request): Responsable
    {
        $data = $request->validated();
        $geoAddress = $this->addressService->getCoordinates($data);

        if (isset($geoAddress['address_components'])) {
            foreach ($geoAddress['address_components'] as $addressComponent) {
                if (in_array('country', $addressComponent->types) && $addressComponent->short_name !== 'PT') {
                    return new ApiErrorResponse(
                        new Exception('Only addresses from Portugal are accepted.'),
                        'Address is invalid',
                        400
                    );
                }
            }
        }

        return new APISuccessResponse(['address' => $geoAddress]);
    }
}
