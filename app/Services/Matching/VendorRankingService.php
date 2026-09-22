<?php

namespace App\Services\Matching;

use App\DTO\Services\AddressCoordinatesDTO;
use App\Enums\Services\ServiceStatus;
use App\Enums\Vendors\StatusVendor;
use App\Models\Address;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Service;
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

    public function __construct(private MatchingSettings $settings) {}

    /**
     * @param  bool  $immediate  Imediato exige estar online e livre agora; agendado
     *                           só exige não estar indisponível naquele dia.
     * @return Collection<int, RankedVendor>
     */
    public function rank(
        MatchingScope $scope,
        AddressCoordinatesDTO|Address $address,
        User $customer,
        bool $immediate,
        ?CarbonInterface $scheduledFor = null,
    ): Collection {
        $vendors = $this->eligibleVendors($scope, $customer, $immediate, $scheduledFor);

        if ($vendors->isEmpty()) {
            return collect();
        }

        $ratings = $this->ratingsFor($vendors->pluck('id')->all(), $scope);

        $ranked = $vendors
            ->map(fn (Vendor $vendor) => $this->describe($vendor, $scope, $address, $ratings, $immediate))
            ->filter()
            ->values();

        return $this->sortAndNumber($this->dentroDoRaio($ranked));
    }

    /**
     * Quem está dentro do raio é convidado primeiro.
     *
     * Não é exclusão dura: se não sobrar ninguém dentro — porque não há
     * cobertura, ou porque os de dentro já foram todos convidados em ondas
     * anteriores — devolve-se a lista inteira e o raio abre-se. Em zonas com
     * pouca cobertura o pedido continua a ter hipótese.
     *
     * Antes disto o motor não tinha predicado geográfico nenhum: a distância
     * entrava só como terceiro critério de desempate, e para um serviço em
     * Lisboa apareciam lado a lado uma proposta a 1 km por 34,11 € e outra a
     * 398 km por 554,98 €.
     *
     * @param  Collection<int, RankedVendor>  $ranked
     * @return Collection<int, RankedVendor>
     */
    public function dentroDoRaio(Collection $ranked): Collection
    {
        $raio = (int) ($this->settings->max_radius_km ?? 0);

        if ($raio <= 0) {
            return $ranked;
        }

        $perto = $ranked->filter(fn (RankedVendor $v) => $v->distance <= $raio)->values();

        return $perto->isNotEmpty() ? $perto : $ranked;
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
        MatchingScope $scope,
        User $customer,
        bool $immediate,
        ?CarbonInterface $scheduledFor,
    ): Collection {
        $isCustomerTest = (bool) ($customer->is_test ?? false);

        // Num pedido de catalogo e elegivel quem faz AQUELE tipo. Num
        // personalizado nao ha tipo: e elegivel quem faz qualquer tipo de
        // uma das categorias que o backoffice escolheu.
        $query = Vendor::query()
            ->whereHas('servicesTypes', fn ($q) => $scope->isCustom()
                ? $q->whereIn('services_types.operation_area_id', $scope->operationAreaIds)
                : $q->where('services_types.id', $scope->serviceType->id))
            // Contas de teste e contas reais nunca se cruzam — mesma regra que
            // o findVendor() já aplicava ao pedido direto.
            ->whereHas('user', fn ($q) => $q->where('is_test', $isCustomerTest));

        // Online é agora o unico interruptor: o profissional diz na Home da
        // app se quer receber trabalho, e isso vale para os dois modos. Antes
        // so contava no imediato — no agendado quem mandava era o horario
        // declarado no registo, que o profissional nao voltava a abrir. Tinha o
        // efeito ao contrario do esperado: estar Offline nao impedia convites
        // agendados, e estar Online nao chegava para os receber.
        $query->where('status', StatusVendor::ONLINE);

        $vendors = $query->get();

        // Quem desligou "Novos pedidos" sai da onda.
        //
        // Antes continuava no ranking e ocupava um dos lugares, sem receber
        // push nenhum: o cliente ficava à espera de resposta de quem não fora
        // avisado, e lia "Avisámos os técnicos da tua zona" quando, no limite,
        // não fora avisado ninguém. O lugar passa a ir a quem pode mesmo ser
        // chamado; o convite fica-lhe no histórico na mesma.
        $vendors = $vendors->filter(fn (Vendor $v) => $v->shouldReceive('new_requests'));

        // A verificação documental passa a valer nos DOIS. Corria só no
        // imediato, e por isso um profissional com registo criminal caducado
        // era excluído de um pedido para agora e convidado para um agendado —
        // com o escudo "Técnico Verificado" ao lado do nome no ecrã do cliente.
        $vendors = $vendors->filter(fn (Vendor $v) => $v->can_accept_service);

        if ($scheduledFor) {
            // O que se verifica aqui e se o profissional pode MESMO estar la:
            // ferias marcadas e sobreposicao com outro servico (ver
            // Vendor::hasFreeSlot). O horario semanal declarado deixou de
            // contar — quem nao quiser aquele trabalho recusa o convite, que
            // ja lhe diz o servico, o valor, a morada e a hora.
            $slotEnd = $scheduledFor->copy()->addMinutes($this->slotMinutes($scope));

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
    private function slotMinutes(MatchingScope $scope): int
    {
        return $scope->minutes > 0 ? $scope->minutes : 60;
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
    public function ratingsFor(array $vendorIds, MatchingScope|ServicesType $scope): array
    {
        if (empty($vendorIds)) {
            return [];
        }

        // Num personalizado as avaliacoes que contam sao as de todas as
        // categorias escolhidas — e o trabalho que ele vai fazer.
        $areaIds = $scope instanceof MatchingScope
            ? $scope->operationAreaIds
            : [(int) $scope->operation_area_id];

        $typeIds = ServicesType::whereIn('operation_area_id', $areaIds)->pluck('id');

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
        MatchingScope $scope,
        AddressCoordinatesDTO|Address $address,
        array $ratings,
        bool $immediate,
    ): ?RankedVendor {
        try {
            $prices = $this->calculatePricesForMinutes($scope->minutes, $address, $vendor, ! $immediate);
        } catch (\Throwable $e) {
            \Log::warning('[matching] profissional excluído do ranking', [
                'vendor_id' => $vendor->id,
                'scope' => $scope->label(),
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
