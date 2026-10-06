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

            /**
             * O CLIENTE DE UM SERVIÇO AGENDADO TAMBÉM O VÊ A CAMINHO.
             *
             * Isto tinha `whereDoesntHave('schedule')`: a posição só era
             * emitida para serviços imediatos, e quem tinha marcado para
             * sábado às 15h ficava sem o mapa -- via o técnico aceitar e
             * depois silêncio até alguém tocar à campainha. O ecrã do mapa
             * existe e funciona nos dois casos; só nunca recebia os dados.
             *
             * MAS SÓ DEPOIS DE ELE SAIR. Um agendamento pode estar marcado
             * para daqui a uma semana, e emitir a posição do técnico desde o
             * momento em que aceita era dar ao cliente um rastreador por sete
             * dias. O `on_the_way_at` é o carimbo de "vou a caminho": antes
             * disso não há nada que o cliente precise de ver, e depois disso é
             * exactamente o que ele quer.
             *
             * Nos IMEDIATOS fica como estava -- aceitar já significa ir a
             * caminho, e exigir-lhes o carimbo tirava o mapa a quem o tem hoje.
             */
            $servicos = $vendor->services()
                ->whereIn('status', [ServiceStatus::FINISHED, ServiceStatus::ACCEPTED])
                ->where(fn ($q) => $q
                    ->whereDoesntHave('schedule')
                    ->orWhereNotNull('on_the_way_at'))
                ->get();

            /**
             * TODOS, e não `->first()`.
             *
             * Com um imediato e um agendado a caminho ao mesmo tempo, o
             * `first()` escolhia um e o outro cliente ficava sem mapa. Cada
             * evento vai para o canal do seu serviço, por isso não há mistura.
             */
            foreach ($servicos as $service) {
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
