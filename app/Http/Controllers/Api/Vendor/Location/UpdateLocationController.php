<?php

namespace App\Http\Controllers\Api\Vendor\Location;

use App\Enums\Services\ServiceStatus;
use App\Events\Common\Services\UpdateLocationEvent;
use App\Events\Vendor\Services\UpdateLocationEvent as VendorUpdateLocationEvent;
use App\Exceptions\Api\Vendor\Status\VendorAlreadyHasDeviceConnected;
use App\Exceptions\Api\Vendor\VendorCantAcceptServices;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Vendor\StoreLocationRequest;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;

class UpdateLocationController extends Controller
{
    public function __invoke(StoreLocationRequest $request)
    {
        try {
            $vendor = auth('api')->user()->vendor;

            $currentLocation = $vendor->currentLocation;
            if ($currentLocation) {
                if ($currentLocation->updated_at->diffInMinutes(now()) < 10) {
                    if ($vendor->currentLocation->device_id !== $request->get('device_id')) {
                        throw new VendorAlreadyHasDeviceConnected;
                    }
                }
            }

            $vendor->currentLocation()->updateOrCreate([], $request->only('latitude', 'longitude', 'device_id'));
            $vendor->currentLocation()->touch();

            // Recarregar ANTES de indexar.
            //
            // A relação foi lida no início deste método, para o teste do
            // dispositivo, e ficou em cache. O `toSearchableArray()` lê
            // `$this->currentLocation` — sem este `load()`, o índice recebia a
            // posição ANTERIOR e, no primeiro ping de sempre, recebia
            // `_geo {0, 0}` com `geoTime` nulo, porque nessa altura ainda não
            // havia posição nenhuma.
            //
            // Um técnico nessas condições fica invisível à procura imediata,
            // que filtra por raio geográfico e pelos últimos 60 minutos: 0,0 é
            // no Golfo da Guiné e um geoTime nulo não entra em janela nenhuma.
            // Sem erro, sem aviso, sem nada — só não aparece.
            //
            // O `$vendor->with('servicesTypes')` que aqui estava não fazia
            // nada: `with()` num modelo devolve uma query nova e o resultado
            // era deitado fora. O `toSearchableArray()` já carrega essa
            // relação sozinho.
            $vendor->load('currentLocation');
            $vendor->searchable();

            $service = $vendor->services()
                ->whereIn('status', [ServiceStatus::FINISHED, ServiceStatus::ACCEPTED])
                ->whereDoesntHave('schedule')
                ->get()
                ->first();

            if ($service) {
                UpdateLocationEvent::dispatch($service->formatDataForCustomer());
                VendorUpdateLocationEvent::dispatch($service->formatDataForVendor(), $vendor->user->id);
            }

            return new ApiSuccessResponse([
                'current_location' => $vendor->currentLocation->only('latitude', 'longitude'),
            ]);
        } catch (VendorCantAcceptServices|VendorAlreadyHasDeviceConnected $exception) {
            return new ApiErrorResponse($exception);
        }

    }
}
