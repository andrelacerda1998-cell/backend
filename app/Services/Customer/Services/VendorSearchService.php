<?php

namespace App\Services\Customer\Services;

use App\DTO\Services\AddressCoordinatesDTO;
use App\Enums\Vendors\StatusVendor;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Vendor;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Scout\Builder;
use Meilisearch\Endpoints\Indexes;

class VendorSearchService
{
    private ServicesType $servicesType;

    private AddressCoordinatesDTO $address;

    /**
     * Técnicos disponíveis para um pedido imediato.
     *
     * O índice de pesquisa é um acelerador, não a única forma de responder — a
     * mesma decisão que o agendamento já tinha tomado (ver
     * ScheduleVendorSearchService). Aqui não tinha: uma lista vazia do índice
     * saía como resposta final, com HTTP 200, e a app mostrava "não há técnicos
     * disponíveis". Indistinguível de uma avaria, e foi por isso que a procura
     * imediata devolveu zero em produção durante dias sem ninguém conseguir
     * dizer porquê.
     *
     * Passa a haver três caminhos, e nenhum deles é calado:
     *
     *  - o índice rebenta  -> cai para a base de dados, com registo;
     *  - o índice diz zero -> confirma-se contra a base de dados. Se a base
     *    tiver candidatos, é o índice que está errado: responde-se com a base e
     *    grita-se no log;
     *  - zero dos dois lados -> zero é mesmo a resposta, e fica registado QUAL
     *    das condições a produziu.
     */
    public function search(AddressCoordinatesDTO $address, ServicesType $servicesType, bool $isTestCustomer = false)
    {
        $this->address = $address;
        $this->servicesType = $servicesType;

        try {
            $doIndice = $this->getVendors()
                ->take(20)
                ->where('is_test', $isTestCustomer)
                ->where('at_valid', true)
                ->get();
        } catch (\Throwable $e) {
            Log::warning('Immediate vendor search fell back to database', [
                'reason' => $e->getMessage(),
                'service_type_id' => $servicesType->id,
            ]);

            return $this->searchInDatabase($isTestCustomer);
        }

        if ($doIndice->isNotEmpty()) {
            return $doIndice->take(20);
        }

        $doBanco = $this->searchInDatabase($isTestCustomer);

        if ($doBanco->isNotEmpty()) {
            // O índice está errado. Não é uma curiosidade: enquanto isto
            // aparecer, todos os pedidos imediatos estão a ser respondidos pela
            // base de dados e o índice precisa de ser reconstruído.
            Log::warning('Immediate vendor search: index found nothing, database did', [
                'service_type_id' => $servicesType->id,
                'recovered' => $doBanco->count(),
            ] + $this->contagensQueExplicamOZero($isTestCustomer));

            return $doBanco;
        }

        // Zero legítimo — mas dizer QUAL das condições o produziu poupa a
        // próxima investigação.
        Log::info('Immediate vendor search found nobody', [
            'service_type_id' => $servicesType->id,
        ] + $this->contagensQueExplicamOZero($isTestCustomer));

        return $doIndice;
    }

    /**
     * Os mesmos filtros do índice, em SQL.
     *
     * Tem de ser os MESMOS, não uns mais largos: um recurso que devolvesse
     * técnicos offline ou longe seria pior do que devolver zero — mandava um
     * profissional que não está a trabalhar para casa de um cliente.
     */
    private function searchInDatabase(bool $isTestCustomer): Collection
    {
        $raio = (int) config('services.request.new_service_search_distance');
        $limiar = Carbon::now()->subMinutes((int) config('services.request.location_update_threshold'));

        $query = Vendor::query()
            ->with(['user', 'servicesTypes', 'currentLocation', 'averageRating'])
            ->where('at_valid', true)
            ->whereHas('user', fn ($q) => $q->where('is_test', $isTestCustomer));

        // Com a localização simulada ligada (só fora de produção) o índice
        // devolve tudo; o recurso faz o mesmo, senão os dois discordavam em
        // desenvolvimento e o aviso disparava sempre.
        if (! $this->usaLocalizacaoSimulada()) {
            $query
                ->where('status', StatusVendor::ONLINE)
                ->whereHas('servicesTypes', fn ($q) => $q->whereKey($this->servicesType->id))
                ->whereHas('currentLocation', function ($q) use ($limiar, $raio) {
                    $q->where('updated_at', '>=', $limiar);
                    $this->limitarACaixa($q, $raio);
                });
        }

        $vendors = $query->take(200)->get();

        if ($this->usaLocalizacaoSimulada()) {
            return $vendors->take(20);
        }

        // A caixa é quadrada e o raio é redondo: os cantos ficam de fora aqui.
        return $vendors
            ->filter(fn (Vendor $v) => ($this->metrosAte($v) ?? PHP_INT_MAX) <= $raio)
            ->sortBy(fn (Vendor $v) => $this->metrosAte($v) ?? PHP_INT_MAX)
            ->values()
            ->take(20);
    }

    /**
     * Caixa envolvente em graus, para o SQL não ter de calcular haversine.
     * É um pré-filtro grosseiro: quem sobra é medido a sério a seguir.
     *
     * O CAST não é decoração. `vendors_location.latitude` e `longitude` são
     * VARCHAR (ver a migration de 2024-10-04), e um `whereBetween` sobre
     * texto compara caractere a caractere: '-9.137' fica FORA do intervalo
     * ['-9.712', '-8.561'] porque '1' < '7'. Ou seja, metade de Portugal
     * desaparecia da procura sem erro nenhum — que é precisamente a família de
     * avaria que este ficheiro existe para não repetir.
     */
    private function limitarACaixa($q, int $raio): void
    {
        $grausLat = $raio / 111_320;
        $cos = max(cos(deg2rad($this->address->latitude)), 0.01);
        $grausLng = $raio / (111_320 * $cos);

        $q->whereRaw('CAST(latitude AS DECIMAL(12, 8)) BETWEEN ? AND ?', [
            $this->address->latitude - $grausLat,
            $this->address->latitude + $grausLat,
        ])->whereRaw('CAST(longitude AS DECIMAL(12, 8)) BETWEEN ? AND ?', [
            $this->address->longitude - $grausLng,
            $this->address->longitude + $grausLng,
        ]);
    }

    private function metrosAte(Vendor $vendor): ?float
    {
        $lat = $vendor->currentLocation?->latitude;
        $lng = $vendor->currentLocation?->longitude;

        if ($lat === null || $lng === null) {
            return null;
        }

        $dLat = deg2rad((float) $lat - $this->address->latitude);
        $dLng = deg2rad((float) $lng - $this->address->longitude);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($this->address->latitude)) * cos(deg2rad((float) $lat)) * sin($dLng / 2) ** 2;

        return 6_371_000 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Quantos caem em cada condição. É isto que transforma um zero numa
     * resposta: sem estes números, "não há técnicos" podia ser falta de
     * técnicos, todos offline, localizações velhas ou o índice parado.
     */
    private function contagensQueExplicamOZero(bool $isTestCustomer): array
    {
        $limiar = Carbon::now()->subMinutes((int) config('services.request.location_update_threshold'));

        $base = fn () => Vendor::query()
            ->where('at_valid', true)
            ->whereHas('user', fn ($q) => $q->where('is_test', $isTestCustomer));

        return [
            'db_com_este_servico' => $base()
                ->whereHas('servicesTypes', fn ($q) => $q->whereKey($this->servicesType->id))->count(),
            'db_destes_online' => $base()
                ->whereHas('servicesTypes', fn ($q) => $q->whereKey($this->servicesType->id))
                ->where('status', StatusVendor::ONLINE)->count(),
            'db_destes_com_localizacao_fresca' => $base()
                ->whereHas('servicesTypes', fn ($q) => $q->whereKey($this->servicesType->id))
                ->where('status', StatusVendor::ONLINE)
                ->whereHas('currentLocation', fn ($q) => $q->where('updated_at', '>=', $limiar))->count(),
        ];
    }

    // `protected` e nao `private` para o teste poder simular o indice a
    // rebentar sem ter um Meilisearch a serio a rebentar.
    protected function getVendors(): Builder
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

    private function filterVendor(Collection $vendors)
    {
        return $vendors->filter(fn ($vendor) => $vendor->can_accept_service);
    }

    public static function filterByCanAcceptService(Collection $vendors, ?bool $canAccept = null)
    {
        if ($canAccept === null) {
            return $vendors;
        }

        return $vendors->filter(fn ($vendor) => $vendor->can_accept_service === $canAccept);
    }
}
