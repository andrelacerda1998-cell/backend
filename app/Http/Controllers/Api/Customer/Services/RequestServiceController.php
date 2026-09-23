<?php

namespace App\Http\Controllers\Api\Customer\Services;

use App\DTO\Services\AddressCoordinatesDTO;
use App\Enums\Services\AddressType;
use App\Exceptions\Api\Customer\CustomerCantRequestServices;
use App\Exceptions\Api\Customer\CustomerDontHaveMainAddress;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Customer\Services\RequestServiceRequest;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Service;
use App\Models\Vendor;
use App\Services\Customer\Services\ScheduleVendorSearchService;
use App\Services\Customer\Services\VendorSearchService;
use App\Services\RateService;
use App\Trait\Services\HasVendorDistance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RequestServiceController extends Controller
{
    use HasVendorDistance;

    public function __invoke(RequestServiceRequest $request, VendorSearchService $searchService)
    {
        // Unidades pedidas. Sem valor => 1, que é o comportamento de sempre.
        $quantity = max(1, (int) $request->get('quantity', 1));
        try {
            $currentUser = auth()->user();
            $mainAddress = $currentUser->mainAddress();

            if (! $currentUser->canRequestService()) {
                throw new CustomerCantRequestServices;
            } elseif (! $mainAddress) {
                throw new CustomerDontHaveMainAddress;
            }

            $userAddress = AddressCoordinatesDTO::fromAddress($mainAddress);

            $requestedServiceType = ServicesType::find($request->get('service_type'));

            $matchingVendors = $searchService->search($userAddress, $requestedServiceType, false);

            $transformedVendors = $this->transformVendors($matchingVendors, $requestedServiceType, $userAddress, $quantity);
            $transformedVendors = $transformedVendors->filter();

            return new ApiSuccessResponse(['vendors' => $transformedVendors]);
        } catch (\Exception $exception) {
            return new ApiErrorResponse($exception);
        }
    }

    public function guestSearch(Request $request, VendorSearchService $searchService, ScheduleVendorSearchService $scheduleSearchService)
    {
        $quantity = max(1, (int) $request->get('quantity', 1));
        // Validação fora do try: a ValidationException tem de propagar para o handler
        // global (422), senão o catch abaixo mascara-a num 500 "Something went wrong".
        $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            // Dia e hora do trabalho, quando ja escolhidos: a lista mostra
            // precos e a sobretaxa e a do servico, nao a de agora.
            'scheduled_day' => 'date|nullable|required_with:scheduled_time_start',
            'scheduled_time_start' => 'string|nullable|required_with:scheduled_day',
        ]);

        $latitude = (float) $request->get('latitude');
        $longitude = (float) $request->get('longitude');

        // Rejeitar Null Island (0/0): coords válidas no cast mas lixo no negócio.
        if ($latitude === 0.0 && $longitude === 0.0) {
            return new ApiErrorResponse(null, 'Invalid location coordinates.', 422);
        }

        try {
            $serviceTypeId = $request->get('service_type_id');
            $isScheduled = $request->boolean('scheduled');

            $serviceType = ServicesType::find($serviceTypeId);

            if (! $serviceType) {
                return new ApiSuccessResponse(['vendors' => []]);
            }

            $guestAddress = new AddressCoordinatesDTO($latitude, $longitude);

            if ($isScheduled) {
                $matchingVendors = $scheduleSearchService->search($guestAddress, $serviceType, false);
                $serviceAt = Service::instanteDe($request->get('scheduled_day'), $request->get('scheduled_time_start'));

                $transformed = $matchingVendors->transform(function (Vendor $vendor) use ($serviceType, $guestAddress, $quantity, $serviceAt) {
                    try {
                        $rateService = app(RateService::class);
                        $hourlyRate = $vendor->getRawOriginal('price_rate');
                        // Unidades: a lista de técnicos mostra o preço FINAL, por isso
                        // tem de já contar com elas — senão o cliente compara valores de
                        // uma unidade e no checkout aparece outro número.
                        $timeService = $serviceType->time * $quantity;

                        $scheduleAddress = $vendor->addresses()
                            ->where('address_type', AddressType::SCHEDULE_ADDRESS)
                            ->first();

                        if ($scheduleAddress === null) {
                            return null;
                        }

                        $vendorRatings = $vendor->averageRating()
                            ->where('operation_area_id', $serviceType->operation_area_id)
                            ->first();

                        $distance = $this->calculateVendorDistance($vendor, $guestAddress);
                        $price = $rateService->calculateForCustomerForSchedule($hourlyRate, $timeService, $distance, true, true, $serviceAt);
                        $original_price = $rateService->calculateForCustomerForOldPrice($hourlyRate, $timeService, $distance, true, true, $serviceAt);

                        return [
                            'id' => $vendor->id,
                            'name' => $vendor->user->name,
                            'rate' => $price,
                            'original_price' => $original_price,
                            // A fatia da estrada dentro do total, para o cartao poder
                            // separar servico de deslocacao. Nao e um extra a somar: ja
                            // esta dentro do `rate`. Corre a mesma formula com tarifa e
                            // tempo a zero, por isso acompanha qualquer mudanca de preco.
                            'travel_amount' => (int) round($rateService->calculateTravelForCustomer($distance, true)),
                            'distance' => $distance,
                            // Nota real ou null. O `?? 5` que aqui estava dava 5 estrelas a quem
                            // nunca foi avaliado: um tecnico acabado de entrar aparecia ao
                            // cliente com nota maxima, indistinguivel de quem a merecera.
                            'rating' => $vendorRatings?->average_rating,
                            'ratings_count' => $vendorRatings?->total_ratings ?? 0,
                            'avatar' => $vendor->user->avatar,
                        ];
                    } catch (\Throwable $e) {
                        // Um técnico com dados incompletos (sem utilizador, sem
                        // morada, sem preço) deixava o pedido inteiro em 500 e o
                        // cliente sem conseguir agendar. Fica de fora e os outros
                        // aparecem — mas o erro fica registado, senão o buraco
                        // some-se em silêncio.
                        Log::warning('Schedule vendor skipped while building list', [
                            'vendor_id' => $vendor->id,
                            'service_type_id' => $serviceType->id,
                            'error' => $e->getMessage(),
                        ]);

                        return null;
                    }
                })->filter()->values()->take(3);
            } else {
                $matchingVendors = $searchService->search($guestAddress, $serviceType, false);

                $transformed = $matchingVendors->transform(function (Vendor $vendor) use ($serviceType, $guestAddress, $quantity) {
                    $rateService = app(RateService::class);
                    $hourlyRate = $vendor->getRawOriginal('price_rate');
                    // Unidades: a lista de técnicos mostra o preço FINAL, por isso
                    // tem de já contar com elas — senão o cliente compara valores de
                    // uma unidade e no checkout aparece outro número.
                    $timeService = $serviceType->time * $quantity;

                    if ($vendor->currentLocation == null) {
                        return null;
                    }

                    $vendorRatings = $vendor->averageRating()
                        ->where('operation_area_id', $serviceType->operation_area_id)
                        ->first();

                    $distance = $this->calculateVendorDistanceInstantService($vendor, $guestAddress);
                    $price = $rateService->calculateForCustomerInstantService($hourlyRate, $timeService, $distance);

                    return [
                        'id' => $vendor->id,
                        'name' => $vendor->user->name,
                        'rate' => $price,
                        // Ver a nota na lista de agendados: parcela ja incluida no `rate`.
                        'travel_amount' => (int) round($rateService->calculateTravelForCustomer($distance, false)),
                        'distance' => $distance,
                        // Nota real ou null. O `?? 5` que aqui estava dava 5 estrelas a quem
                        // nunca foi avaliado: um tecnico acabado de entrar aparecia ao
                        // cliente com nota maxima, indistinguivel de quem a merecera.
                        'rating' => $vendorRatings?->average_rating,
                        'ratings_count' => $vendorRatings?->total_ratings ?? 0,
                        'avatar' => $vendor->user->avatar,
                    ];
                })->filter()->values()->take(3);
            }

            return new ApiSuccessResponse(['vendors' => $transformed]);
        } catch (\Throwable $exception) {
            // Throwable e não Exception: um \Error de PHP (ler propriedade de
            // null, um argumento do tipo errado) não é Exception, passava aqui
            // ao lado sem sequer ficar registado, e o cliente via só um 500.
            // O "Something went wrong" que o cliente recebe não diz nada a
            // ninguém; sem este registo, saber porque falhou uma procura obriga
            // a adivinhar a partir do ecrã.
            Log::error('Guest vendor search failed', [
                'scheduled' => $request->boolean('scheduled'),
                'service_type_id' => $request->get('service_type_id'),
                'error' => $exception->getMessage(),
                'at' => $exception->getFile().':'.$exception->getLine(),
            ]);

            return new ApiErrorResponse($exception);
        }
    }

    private function transformVendors($vendors, ServicesType $serviceType, $userAddress, int $quantity = 1)
    {
        return $vendors->transform(function (Vendor $vendor) use ($serviceType, $userAddress, $quantity) {
            $rateService = app(RateService::class);

            $vendorUser = $vendor->user;

            $hourlyRate = $vendor->getRawOriginal('price_rate');
            $timeService = $serviceType->time * $quantity;

            if ($vendor->currentLocation == null) {
                return null;
            }
            $vendorRatings = $vendor->averageRating()
                ->where('operation_area_id', $serviceType->operation_area_id)
                ->first();

            $distance = $this->calculateVendorDistanceInstantService($vendor, $userAddress);

            $price = $rateService->calculateForCustomerInstantService($hourlyRate, $timeService, $distance);

            return [
                'id' => $vendor->id,
                'name' => $vendorUser->name,
                // 'nif' => $vendorUser->nif,
                'rate' => $price,
                // 'hourly_rate' => $hourlyRate,
                // Ver a nota na lista de agendados: parcela ja incluida no `rate`.
                'travel_amount' => (int) round($rateService->calculateTravelForCustomer($distance, false)),
                'distance' => $distance,
                // Nota real ou null. O `?? 5` que aqui estava dava 5 estrelas a quem
                // nunca foi avaliado: um tecnico acabado de entrar aparecia ao
                // cliente com nota maxima, indistinguivel de quem a merecera.
                'rating' => $vendorRatings?->average_rating,
                'ratings_count' => $vendorRatings?->total_ratings ?? 0,
                'avatar' => $vendorUser->avatar,
            ];
        })->values()->take(3);
    }

    private function calculateRate($hourRate, $distance) {}
}
