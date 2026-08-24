<?php

namespace App\Services\Matching;

use App\Enums\Services\ServiceStatus;
use App\Enums\Vendors\StatusVendor;
use App\DTO\Services\AddressCoordinatesDTO;
use App\Models\Service;
use App\Models\GeneralSettings\ServicesType;
use App\Models\User;
use App\Models\Vendor;
use App\Settings\MatchingSettings;
use App\Trait\Services\CalculateServicePriceForCustomer;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ordena os profissionais elegíveis para um serviço — ver docs/matching.md.
 *
 * Prioridade definida pelo negócio: melhor avaliação, depois preço mais barato,
 * depois menor distância.
 */
class VendorRankingService
{
    use CalculateServicePriceForCustomer;

    public function __construct(private MatchingSettings $settings)
    {
    }

    /**
     * @param  bool  $immediate  Imediato exige estar online e livre agora; agendado
     *                           só exige não estar indisponível naquele dia.
     * @return Collection<int, RankedVendor>
     */
    public function rank(
        ServicesType $serviceType,
        AddressCoordinatesDTO|\App\Models\Address $address,
        User $customer,
        bool $immediate,
        ?CarbonInterface $scheduledFor = null,
        int $quantity = 1,
    ): Collection {
        $vendors = $this->eligibleVendors($serviceType, $customer, $immediate, $scheduledFor);

        if ($vendors->isEmpty()) {
            return collect();
        }

        $ratings = $this->ratingsFor($vendors->pluck('id')->all(), $serviceType);

        $ranked = $vendors
            ->map(fn (Vendor $vendor) => $this->describe($vendor, $serviceType, $address, $ratings, $immediate, $quantity))
            ->filter()
            ->values();

        return $this->sortAndNumber($ranked);
    }

    /**
     * Aplica a shortlist: os N melhores, com uma vaga reservada a quem ainda
     * não tem avaliações.
     *
     * @param  Collection<int, RankedVendor>  $ranked
     * @return Collection<int, RankedVendor>
     */
    public function shortlist(Collection $ranked, ?int $size = null): Collection
    {
        $size = $size ?? $this->settings->shortlist_size;

        if ($ranked->count() <= $size) {
            return $this->sortAndNumber($ranked);
        }

        $top = $ranked->take($size);
        $minRatings = $this->settings->new_vendor_min_ratings;

        // Se já entrou alguém sem historial, não há nada a corrigir.
        if ($top->contains(fn (RankedVendor $c) => $c->isNewVendor($minRatings))) {
            return $this->sortAndNumber($top);
        }

        $newcomer = $ranked->first(fn (RankedVendor $c) => $c->isNewVendor($minRatings));

        if (! $newcomer) {
            return $this->sortAndNumber($top);
        }

        // Troca o último pelo melhor recém-chegado. Sem isto, quem não tem
        // avaliações fica no fundo, nunca é escolhido, e por isso nunca ganha
        // avaliações — a oferta nova morre à nascença.
        $newcomer->isNewVendorSlot = true;

        return $this->sortAndNumber($top->take($size - 1)->push($newcomer));
    }

    /**
     * @return Collection<int, Vendor>
     */
    private function eligibleVendors(
        ServicesType $serviceType,
        User $customer,
        bool $immediate,
        ?CarbonInterface $scheduledFor,
    ): Collection {
        $isCustomerTest = (bool) ($customer->is_test ?? false);

        $query = Vendor::query()
            ->whereHas('servicesTypes', fn ($q) => $q->where('services_types.id', $serviceType->id))
            // Contas de teste e contas reais nunca se cruzam — mesma regra que
            // o findVendor() já aplicava ao pedido direto.
            ->whereHas('user', fn ($q) => $q->where('is_test', $isCustomerTest));

        if ($immediate) {
            // No imediato o profissional só entra na lista se estiver mesmo
            // disponível agora: a lista é mostrada ao cliente ANTES de alguém
            // ser notificado, por isso tem de ser uma boa previsão de quem vai
            // responder.
            $query->where('status', StatusVendor::ONLINE);
        }

        $vendors = $query->get();

        if ($immediate) {
            $vendors = $vendors->filter(fn (Vendor $v) => $v->can_accept_service);
        }

        if ($scheduledFor) {
            $vendors = $vendors->reject(fn (Vendor $v) => $v->isUnavailableOn($scheduledFor));
        }

        return $vendors->values();
    }

    /**
     * Média e número de avaliações por profissional, na área de operação do
     * tipo de serviço pedido.
     *
     * Lê `rating_by_customer` diretamente dos serviços fechados, e não a tabela
     * `vendor_ratings`. Essa é um resumo em cache, recalculado pelo
     * `VendorObserver`: serve para mostrar, mas pode estar atrasada em relação
     * à última avaliação. Quem decide a ordem lê a fonte.
     *
     * @param  int[]  $vendorIds
     * @return array<int, array{avg: float, count: int}>
     */
    private function ratingsFor(array $vendorIds, ServicesType $serviceType): array
    {
        if (empty($vendorIds)) {
            return [];
        }

        $typeIds = ServicesType::where('operation_area_id', $serviceType->operation_area_id)->pluck('id');

        return Service::query()
            ->select('vendor_id', DB::raw('AVG(rating_by_customer) as avg_rating'), DB::raw('COUNT(rating_by_customer) as total'))
            ->whereIn('vendor_id', $vendorIds)
            ->whereIn('services_type_id', $typeIds)
            ->where('status', ServiceStatus::CLOSED)
            ->whereNotNull('rating_by_customer')
            ->groupBy('vendor_id')
            ->get()
            ->mapWithKeys(fn ($row) => [
                (int) $row->vendor_id => [
                    'avg' => (float) $row->avg_rating,
                    'count' => (int) $row->total,
                ],
            ])
            ->all();
    }

    private function describe(
        Vendor $vendor,
        ServicesType $serviceType,
        AddressCoordinatesDTO|\App\Models\Address $address,
        array $ratings,
        bool $immediate,
        int $quantity,
    ): ?RankedVendor {
        try {
            $prices = $this->calculatePrices($serviceType, $address, $vendor, ! $immediate, $quantity);
        } catch (\Throwable $e) {
            // Sem localização utilizável não há distância, logo não há preço.
            // Fica de fora em vez de entrar com um orçamento inventado.
            return null;
        }

        $rating = $ratings[$vendor->id] ?? null;
        $average = $rating['avg'] ?? null;

        return new RankedVendor(
            vendor: $vendor,
            ratingAverage: $average,
            ratingCount: $rating['count'] ?? 0,
            ratingBand: $average === null ? null : $this->band($average),
            distance: (float) $prices['distance'],
            quotedAmount: $prices['customer_amount'],
            quotedAmountForVendor: $prices['vendor_amount'],
        );
    }

    /**
     * Faixa da avaliação. 0 é a melhor.
     *
     * As faixas existem para o preço e a distância chegarem a contar: com
     * ordenação direta por média, empates são raros e o primeiro critério
     * decidiria sempre sozinho.
     */
    public function band(float $average): int
    {
        foreach (array_values($this->settings->rating_bands) as $index => $floor) {
            if ($average >= $floor) {
                return $index;
            }
        }

        return count($this->settings->rating_bands);
    }

    /**
     * @param  Collection<int, RankedVendor>  $ranked
     * @return Collection<int, RankedVendor>
     */
    private function sortAndNumber(Collection $ranked): Collection
    {
        $bandCount = count($this->settings->rating_bands);

        return $ranked
            ->sortBy(fn (RankedVendor $c) => $c->sortKey($bandCount))
            ->values()
            ->each(function (RankedVendor $c, int $i) {
                $c->rank = $i + 1;
            });
    }
}
