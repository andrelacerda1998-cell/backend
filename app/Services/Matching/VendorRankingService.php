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
    /** Quantas notas iniciais se olham para decidir que o arranque correu mal. */
    private const BAD_START_RATINGS = 3;

    /** Abaixo disto conta como nota má, para efeitos do arranque. */
    private const BAD_START_BELOW = 3.0;

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
        $vendors = $this->eligibleVendors($serviceType, $customer, $immediate, $scheduledFor, $quantity);

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
        int $quantity = 1,
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
            // Não basta o dia estar livre: o BLOCO tem de estar. Convidar
            // alguém para uma hora que não tem disponível é pior do que não o
            // convidar — ou recusa, e aprende que os convites não são de fiar,
            // ou aceita por distração e falta.
            $slotEnd = $scheduledFor->copy()->addMinutes($this->slotMinutes($serviceType, $quantity));

            $vendors = $vendors->filter(fn (Vendor $v) => $v->hasFreeSlot($scheduledFor, $slotEnd));
        }

        return $vendors->values();
    }

    /**
     * Duração do bloco a reservar, em minutos.
     *
     * A mesma que o pricing usa (`effectiveMinutes`), para o que se verifica na
     * agenda ser exatamente o que se vai ocupar. Sem duração no catálogo
     * assume-se uma hora — não bloquear nada seria pior, porque deixaria passar
     * sobreposições reais.
     */
    private function slotMinutes(ServicesType $serviceType, int $quantity): int
    {
        $minutes = $serviceType->time ? $this->effectiveMinutes($serviceType, $quantity) : 0;

        return $minutes > 0 ? $minutes : 60;
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
     * @return array<int, array{avg: float, count: int, bad_start: bool}>
     */
    public function ratingsFor(array $vendorIds, ServicesType $serviceType): array
    {
        if (empty($vendorIds)) {
            return [];
        }

        $typeIds = ServicesType::where('operation_area_id', $serviceType->operation_area_id)->pluck('id');

        $aggregate = Service::query()
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

        $badStart = $this->badStarters(array_keys($aggregate), $aggregate, $typeIds->all());

        foreach ($aggregate as $vendorId => $row) {
            $aggregate[$vendorId]['bad_start'] = in_array($vendorId, $badStart, true);
        }

        return $aggregate;
    }

    /**
     * Quem abriu com as PRIMEIRAS `BAD_START_RATINGS` notas todas abaixo de
     * `BAD_START_BELOW` estrelas.
     *
     * O amortecedor de arranque existe para a nota estabilizar antes de contar.
     * Não existe para segurar indefinidamente quem já mostrou o que faz: três
     * clientes seguidos a dar menos de 3 não é ruído, é um padrão. A partir daí
     * a nota real conta, mesmo antes das cinco.
     *
     * Só se pergunta a quem ainda está dentro do amortecedor — quem já passou
     * das cinco avaliações não é protegido de qualquer forma, e não vale uma
     * consulta.
     *
     * Ordena por `updated_at` porque não há coluna de "avaliado em": o serviço
     * é atualizado no momento em que o cliente avalia, e é o mais próximo disso
     * que existe.
     *
     * @param  int[]  $vendorIds
     * @param  array<int, array{avg: float, count: int}>  $aggregate
     * @param  int[]  $typeIds
     * @return int[]
     */
    private function badStarters(array $vendorIds, array $aggregate, array $typeIds): array
    {
        $candidates = array_values(array_filter(
            $vendorIds,
            fn (int $id) => $aggregate[$id]['count'] >= self::BAD_START_RATINGS
                && $aggregate[$id]['count'] < $this->settings->new_vendor_min_ratings
        ));

        if (empty($candidates)) {
            return [];
        }

        return Service::query()
            ->select('vendor_id', 'rating_by_customer', 'updated_at')
            ->whereIn('vendor_id', $candidates)
            ->whereIn('services_type_id', $typeIds)
            ->where('status', ServiceStatus::CLOSED)
            ->whereNotNull('rating_by_customer')
            ->orderBy('vendor_id')
            ->orderBy('updated_at')
            ->get()
            ->groupBy('vendor_id')
            ->filter(function ($rows) {
                $first = $rows->take(self::BAD_START_RATINGS);

                return $first->count() === self::BAD_START_RATINGS
                    && $first->every(fn ($r) => (float) $r->rating_by_customer < self::BAD_START_BELOW);
            })
            ->keys()
            ->map(fn ($id) => (int) $id)
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
            //
            // Acontece a sério: para agendados a distância usa a morada de
            // agenda (HasVendorDistance::calculateVendorDistance) e um
            // profissional sem moradas rebenta ali. Registamos, porque um
            // profissional que desaparece dos rankings sem deixar rasto é
            // indistinguível de um que nunca foi elegível.
            \Log::warning('[matching] profissional excluído do ranking', [
                'vendor_id' => $vendor->id,
                'service_type_id' => $serviceType->id,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }

        $rating = $ratings[$vendor->id] ?? null;
        $average = $rating['avg'] ?? null;
        $count = (int) ($rating['count'] ?? 0);

        return new RankedVendor(
            vendor: $vendor,
            ratingAverage: $average,
            ratingCount: $count,
            ratingBand: $this->bandFor($average, $count, (bool) ($rating['bad_start'] ?? false)),
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
    /**
     * Faixa usada para ordenar, ja com o amortecedor de arranque.
     *
     * Ate as primeiras `new_vendor_min_ratings` avaliacoes, conta como faixa A
     * (0) independentemente da media — incluindo quem ainda nao tem nenhuma.
     *
     * Duas razoes. O corte para o ecra do cliente e por rank: quem arranca no
     * fundo nunca e visto, nunca ganha avaliacoes, e nunca sai do fundo. E com
     * uma ou duas notas a media ainda nao diz nada — um unico 4 punha alguem
     * em 4,0 (faixa B) e tirava-lhe a visibilidade por causa de um cliente.
     *
     * ISTO SO ORDENA. A nota MOSTRADA ao cliente continua a ser a real, ou
     * `null` para quem nao tem nenhuma — ver o payload do MatchingController e
     * o `test_no_ratings_means_null_not_five_stars`. Nao se inventa nota a quem
     * a ve; da-se oportunidade a quem ainda nao a tem.
     */
    public function bandFor(?float $average, int $ratingCount, bool $badStart = false): int
    {
        // Três clientes seguidos abaixo de 3 estrelas acabam com a proteção
        // antes das cinco: o amortecedor é para a nota estabilizar, não para
        // segurar quem já mostrou o que faz.
        if ($badStart) {
            return $this->band((float) $average);
        }

        if ($ratingCount < $this->settings->new_vendor_min_ratings) {
            return 0;
        }

        return $this->band((float) $average);
    }

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
