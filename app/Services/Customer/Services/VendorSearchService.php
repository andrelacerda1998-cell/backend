<?php

namespace App\Services\Customer\Services;

use App\DTO\Services\AddressCoordinatesDTO;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Vendor;
use Carbon\Carbon;
use Meilisearch\Endpoints\Indexes;

class VendorSearchService
{
    private ServicesType $servicesType;

    private AddressCoordinatesDTO $address;

    public function search(AddressCoordinatesDTO $address, ServicesType $servicesType, bool $isTestCustomer = false)
    {
        $this->address = $address;
        $this->servicesType = $servicesType;

        $query = $this->getVendors();
        $vendors = $query->take(20)->where('is_test', $isTestCustomer)->where('at_valid', true)->get();

        return $vendors->take(20);
    }

    private function getVendors(): \Laravel\Scout\Builder
    {
        $address = $this->address;
        $servicesType = $this->servicesType;


        // MOCK_LOCATION devolve TUDO: sem raio geografico, sem filtro de tipo de
        // servico, sem `status = Online`, sem a janela dos 60 minutos e sem
        // ordenacao nenhuma. Serve para desenvolver sem ter um tecnico a mandar
        // localizacao, mas em producao significaria oferecer ao cliente
        // profissionais offline, de outra especialidade e a qualquer distancia,
        // por ordem arbitraria.
        //
        // A guarda `! app()->isProduction()` e a mesma que o MOCK_SMS ja usa
        // (PhoneLoginController, GuestSendOtpController, PhoneLoginSmsService):
        // se a variavel ficar ligada por engano num deploy, o mock nao pega.
        if ($this->usaLocalizacaoSimulada()) {

            return Vendor::search('', function (Indexes $meilisearch, string $query, array $options) {
                $offset = 0;
                $limit = 200;
                $options['limit'] = $limit;
                $options['offset'] = $offset;

                return $meilisearch->search($query, $options);
            });
        }


        return Vendor::search('', function (Indexes $meilisearch, string $query, array $options) use ($address, $servicesType) {
            $latitude = $address->latitude;
            $longitude = $address->longitude;
            $locationUpdateThreshold = config('services.request.location_update_threshold');
            $pastTime = Carbon::now()->subMinutes($locationUpdateThreshold);

            $geoFilter = $this->buildGeoFilter($latitude, $longitude);
            $serviceTypeFilter = $this->buildServiceTypeFilter($servicesType->id);
            $activityTimeFilter = $this->buildActivityTimeFilter($pastTime->timestamp);
            $statusFilter = $this->buildStatusFilter();

            $options['filter'] = "$geoFilter AND $serviceTypeFilter AND $activityTimeFilter AND $statusFilter";
            // $options['filter'] = $statusFilter;

            $options['sort'] = [
                sprintf('_geoPoint(%F, %F):asc', $latitude, $longitude),
                'ratings.average_rating:desc',
            ];

            return $meilisearch->search($query, $options);
        });
    }

    /**
     * A regra do mock, num sítio só e com nome.
     *
     * Estava escrita dentro do `if` e sem guarda de ambiente. Fica aqui para
     * poder ser presa por um teste — a diferença entre ligado e desligado é
     * devolver os profissionais certos ou devolver o índice inteiro.
     */
    public function usaLocalizacaoSimulada(): bool
    {
        return (bool) config('services.request.mock_location') && ! app()->isProduction();
    }

    private function buildGeoFilter(float $latitude, float $longitude): string
    {
        $distance = config('services.request.new_service_search_distance');

        return sprintf('_geoRadius(%F, %F, %d)', $latitude, $longitude, $distance);
    }

    private function buildServiceTypeFilter(int $serviceTypeId): string
    {
        return sprintf('services_types.id = %d', $serviceTypeId);
    }

    private function buildActivityTimeFilter(int $timestamp): string
    {
        return sprintf('geoTime >= %d', $timestamp);
    }

    private function buildStatusFilter(): string
    {
        return "status = 'Online'";
    }

    private function buildOperationAreaRatingFilter($operationArea): string
    {
        return sprintf('ratings.operation_area_id = %s', $operationArea);
    }

    private function filterVendor(\Illuminate\Database\Eloquent\Collection $vendors)
    {
        return $vendors->filter(fn($vendor) => $vendor->can_accept_service);
    }

    public static function filterByCanAcceptService(\Illuminate\Database\Eloquent\Collection $vendors, ?bool $canAccept = null)
    {
        if ($canAccept === null) {
            return $vendors;
        }

        return $vendors->filter(fn($vendor) => $vendor->can_accept_service === $canAccept);
    }
}
