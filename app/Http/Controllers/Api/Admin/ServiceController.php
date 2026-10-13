<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Services\CandidateStatus;
use App\Enums\Services\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Service;
use App\Support\PesquisaDePessoas;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Serviços pedidos na app, para o backoffice.
 *
 * É a ponte que faltava: os pedidos entram pela app, ficam aqui, e o backoffice
 * não os via -- só via as leads do formulário do site. Com o formulário a sair
 * da landing, sem este endpoint o backoffice deixava de saber que alguém pediu
 * alguma coisa.
 *
 * Devolve também o estado do MATCHING (App\Models\ServiceCandidate): quantos
 * técnicos foram notificados, quantos aceitaram, quantos recusaram e quantos
 * deixaram expirar. É a informação que diz se a rede chega para a procura, e
 * hoje não sai daqui para lado nenhum.
 *
 * Valores: as colunas são INTEGER em cêntimos; saem em euros, para casar com o
 * resto da API de admin (ver VendorPaymentController).
 */
class ServiceController extends Controller
{
    /** Serviços de teste nunca contam -- é a mesma regra do resto do admin. */
    private const EXCLUI_TESTES = true;

    /** Quem disse que sim, independentemente do que aconteceu depois. */
    private const ACEITARAM = [CandidateStatus::ACCEPTED, CandidateStatus::SELECTED, CandidateStatus::LOST];

    /** As ordenações que a lista aceita (`sort`). Qualquer outra é ignorada. */
    private const ORDENACOES = ['id', 'created_at', 'scheduled_at', 'amount'];

    /**
     * Os prazos dos alertas — os MESMOS do OperacoesAoVivoController: um
     * pedido à procura sem ninguém convidado ao fim de dois minutos, e um
     * serviço terminado que o cliente não confirmou num dia.
     */
    private const SEGUNDOS_SEM_CONVITES = 120;

    private const HORAS_SEM_CONFIRMACAO = 24;

    /** O único motivo que é "atenção" e não "crítico" (ver OperacoesAoVivo). */
    private const MOTIVO_SO_ATENCAO = 'cliente_nao_confirmou';

    public function index(Request $request): ApiSuccessResponse
    {
        $request->validate([
            'customer_id' => ['nullable', 'integer'],
            'vendor_id' => ['nullable', 'integer'],
            'dia' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $perPage = min((int) $request->integer('per_page', 20), 100);

        $query = Service::query()
            // Explícito porque a lista junta colunas calculadas (o motivo de
            // atenção) — sem isto o select ficava só com elas.
            ->select('services.*')
            ->with(['customerUser', 'vendor.user', 'serviceType', 'schedule', 'media', 'operationAreas'])
            ->withCount([
                'candidates as candidates_invited',
                'candidates as candidates_notified' => fn ($q) => $q->where('status', CandidateStatus::NOTIFIED),
                // ACEITOU ALGUMA VEZ. Quem aceita passa depois a SELECTED (foi
                // escolhido) ou a LOST (outro foi escolhido, ou o pedido fechou).
                // Contar só ACCEPTED dava zero em todo o pedido que avançou — o
                // contrário do que a coluna diz.
                'candidates as candidates_accepted' => fn ($q) => $q->whereIn('status', self::ACEITARAM),
                'candidates as candidates_declined' => fn ($q) => $q->where('status', CandidateStatus::DECLINED),
                'candidates as candidates_expired' => fn ($q) => $q->where('status', CandidateStatus::EXPIRED),
            ]);

        $this->comMotivoDeAtencao($query);

        if (self::EXCLUI_TESTES) {
            $query->where('services.is_test', false);
        }

        /*
         * Vários estados de uma vez. Os separadores do backoffice são GRUPOS
         * ("Em curso" = técnico em casa + à espera de confirmação), e o filtro
         * de um só estado não os conseguia exprimir: o backoffice deixava de o
         * mandar e cada separador mostrava a lista inteira.
         */
        $estados = array_values(array_filter(array_map(
            'trim',
            explode(',', $request->string('statuses')->toString()),
        )));

        if ($estados) {
            $query->whereIn('status', $estados);
        } elseif ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }

        // A cidade vive na morada guardada no serviço (JSON), com dois nomes
        // possíveis consoante a origem — os mesmos dois que o present() lê.
        if ($cidade = trim($request->string('city')->toString())) {
            $query->where(fn ($q) => $q
                ->where('address->city', $cidade)
                ->orWhere('address->locality', $cidade));
        }

        // `category_id` no backoffice é o tipo de serviço (ver present()).
        if ($request->filled('category_id')) {
            $query->where('services_type_id', $request->integer('category_id'));
        }

        // Só os personalizados (ou só os do catálogo). O sino do backoffice
        // procura os personalizados a cada 45 s: sem isto lia o histórico todo.
        if ($request->has('is_custom')) {
            $query->where('is_custom', $request->boolean('is_custom'));
        }

        /*
         * A CATEGORIA que a equipa conhece (Canalização, Eletricidade…) é a área
         * de operação do tipo de serviço. É por ela que o filtro do backoffice
         * pergunta — o tipo é demasiado fino para escolher numa lista.
         */
        if ($request->filled('operation_area_id')) {
            $area = $request->integer('operation_area_id');
            $query->whereHas('serviceType', fn ($t) => $t->where('operation_area_id', $area));
        }

        // Os pedidos de UMA pessoa, para as fichas do cliente e do técnico:
        // antes a ficha procurava pelo nome e esperava que só houvesse um.
        if ($request->filled('customer_id')) {
            $query->where('services.customer_id', $request->integer('customer_id'));
        }
        if ($request->filled('vendor_id')) {
            $query->where('services.vendor_id', $request->integer('vendor_id'));
        }

        if ($dia = $request->string('dia')->toString()) {
            $this->doDia($query, $dia);
        }

        // Os que ainda não acabaram (ver ServiceStatus::finais()).
        if ($request->boolean('abertos')) {
            $query->whereNotIn('services.status', ServiceStatus::finais());
        }

        if ($request->boolean('agendados')) {
            $this->soMarcacoesPorVir($query);
        }

        /*
         * Procura por nome ou telefone do cliente. É por aqui que se resolve um
         * caso ao telefone, por isso o telefone conta tanto como o nome.
         */
        $numeroExato = null;

        if ($termo = trim($request->string('search')->toString())) {
            // "#282" ou "282" é o número do serviço — o que o cliente lê no
            // recibo e diz ao telefone. Antes só se procurava pelo cliente, e
            // o número não encontrava nada.
            $numero = trim(ltrim($termo, '#'));
            $numeroExato = ctype_digit($numero) ? (int) $numero : null;

            $query->where(function ($q) use ($termo, $numeroExato) {
                if ($numeroExato !== null) {
                    // Qualificado com a tabela: os whereHas abaixo juntam
                    // outras tabelas com a sua própria coluna `id`.
                    $q->orWhere('services.id', $numeroExato);
                }

                // Nome completo, telefone em qualquer formato e email — a
                // mesma regra das listas de clientes e de técnicos.
                $q->orWhereHas('customerUser', fn ($c) => PesquisaDePessoas::aplicar($c, $termo, [
                    PesquisaDePessoas::NOME, PesquisaDePessoas::TELEFONE, PesquisaDePessoas::EMAIL,
                ]))
                    // E pelo técnico: "o serviço do Rui de ontem".
                    ->orWhereHas('vendor.user', fn ($v) => PesquisaDePessoas::aplicar($v, $termo, [
                        PesquisaDePessoas::NOME, PesquisaDePessoas::TELEFONE,
                    ]));
            });
        }

        if ($desde = $request->string('from')->toString()) {
            $query->whereDate('created_at', '>=', $desde);
        }
        if ($ate = $request->string('to')->toString()) {
            $query->whereDate('created_at', '<=', $ate);
        }

        /*
         * A ORDEM, por esta prioridade:
         *
         *  1. o pedido com o número exato que se procurou. "282" encontra também
         *     os clientes com 282 no telefone, e o #282 ficava perdido no meio
         *     deles — às vezes na página dois;
         *  2. com `atencao=1`, os que pedem uma pessoa: primeiro os críticos,
         *     depois os de atenção;
         *  3. a ordenação pedida (`sort`/`dir`), ou a de sempre — o mais
         *     recente primeiro.
         */
        if ($numeroExato !== null) {
            $query->orderByRaw('(services.id = ?) DESC', [$numeroExato]);
        }

        if ($request->boolean('atencao')) {
            $query->orderByRaw(
                'CASE WHEN atencao_motivo IS NULL THEN 2 WHEN atencao_motivo = ? THEN 1 ELSE 0 END',
                [self::MOTIVO_SO_ATENCAO],
            );
        }

        $this->ordenar($query, $request->string('sort')->toString(), $request->string('dir')->toString());

        $servicos = $query->paginate($perPage);

        return ApiSuccessResponse::make([
            'items' => collect($servicos->items())
                ->map(fn (Service $s) => $this->present($s))
                ->all(),
            'meta' => [
                'current_page' => $servicos->currentPage(),
                'last_page' => $servicos->lastPage(),
                'per_page' => $servicos->perPage(),
                'total' => $servicos->total(),
            ],
        ]);
    }

    /**
     * Um serviço com a lista de candidatos, para o detalhe do pedido.
     *
     * Separado do index de propósito: a lista de candidatos por serviço numa
     * listagem de 100 seriam centenas de linhas que ninguém lê.
     */
    public function show(Service $service): ApiSuccessResponse
    {
        $service->load(['customerUser', 'vendor.user', 'serviceType', 'schedule', 'candidates.vendor.user', 'media', 'operationAreas', 'paymentOrder']);

        // O mesmo alerta que a lista calcula, para o detalhe o poder mostrar.
        [$sql, $bindings] = $this->sqlDoMotivoDeAtencao();
        $service->setAttribute('atencao_motivo', Service::query()
            ->whereKey($service->getKey())
            ->selectRaw("{$sql} AS atencao_motivo", $bindings)
            ->first()
            ?->getAttribute('atencao_motivo'));

        return ApiSuccessResponse::make([
            ...$this->present($service),
            /*
             * Para resolver o pedido sem sair dele: o telefone do técnico e o
             * pagamento a que o pedido está ligado, que é o que o reembolso do
             * backoffice precisa.
             *
             * O telefone do técnico passou a vir também na listagem, com o nome
             * `vendor_phone` (o backoffice esconde-o consoante a permissão de
             * quem está a ver). Este nome fica para quem já o lê.
             */
            'technician_phone' => $service->vendor?->user?->phone_number,
            'payment_order_uuid' => $service->paymentOrder?->uuid,
            /*
             * As fotografias que o cliente anexou, com URL assinado.
             *
             * `customerPhotosPayload()` é o mesmo método que as apps do cliente
             * e do técnico usam -- uma só definição de validade (60 min) em vez
             * de três que divergiriam à primeira alteração. Só no detalhe: na
             * listagem vai apenas a contagem.
             */
            'customer_photos' => $service->customerPhotosPayload(),
            /*
             * As contagens, com nome próprio. No detalhe, `candidates` é a
             * LISTA (abaixo) e substitui as contagens que o present() pôs com o
             * mesmo nome — o detalhe nunca as tinha.
             */
            'candidate_counts' => $this->contagemDeCandidatos($service),
            /*
             * O caminho do pedido (ver RegistoDeEventos). Só existe a partir
             * de 08/10/2026: os pedidos anteriores têm a lista vazia, e o
             * backoffice mostra a cronologia aproximada que tinha.
             */
            'events' => $service->events()->with('vendor.user')->get()
                ->map(fn ($e) => [
                    'tipo' => $e->tipo,
                    'de' => $e->estado_de,
                    'para' => $e->estado_para,
                    'vendor_id' => $e->vendor_id,
                    'vendor_name' => $e->vendor?->user ? trim(($e->vendor->user->first_name ?? '').' '.($e->vendor->user->last_name ?? '')) ?: null : null,
                    'dados' => $e->dados,
                    'em' => optional($e->ocorreu_em)->toIso8601String(),
                ])
                ->values()
                ->all(),
            'candidates' => $service->candidates
                ->sortBy('rank')
                ->map(fn ($c) => [
                    'vendor_id' => $c->vendor_id,
                    'vendor_name' => $c->vendor?->user?->name,
                    'status' => $c->status instanceof CandidateStatus ? $c->status->value : $c->status,
                    'rank' => $c->rank,
                    'wave' => $c->wave,
                    'rating_average' => $c->rating_average,
                    'quoted_amount' => $this->euros($c->quoted_amount),
                    'quoted_distance' => $c->quoted_distance,
                    'notified_at' => optional($c->created_at)->toIso8601String(),
                    // A hora em que RESPONDEU, e null se ainda não respondeu.
                    // Era o `updated_at`, que muda com qualquer alteração à
                    // linha — e dava hora de resposta a quem nunca respondeu.
                    'responded_at' => optional($c->responded_at)->toIso8601String(),
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * Quantos técnicos foram convidados e o que responderam.
     *
     * Na listagem vem do `withCount` (uma query para a página toda). No
     * detalhe os candidatos já estão carregados, e conta-se a partir deles:
     * antes o detalhe dizia sempre zero, porque só a listagem pedia as
     * contagens. `notified` são os que ainda podem responder.
     *
     * @return array<string, int>
     */
    private function contagemDeCandidatos(Service $service): array
    {
        if ($service->relationLoaded('candidates')) {
            $estados = $service->candidates->map(
                fn ($c) => $c->status instanceof CandidateStatus ? $c->status : CandidateStatus::tryFrom((string) $c->status)
            );

            return [
                'invited' => $estados->count(),
                'notified' => $estados->filter(fn ($e) => $e === CandidateStatus::NOTIFIED)->count(),
                'accepted' => $estados->filter(fn ($e) => in_array($e, self::ACEITARAM, true))->count(),
                'declined' => $estados->filter(fn ($e) => $e === CandidateStatus::DECLINED)->count(),
                'expired' => $estados->filter(fn ($e) => $e === CandidateStatus::EXPIRED)->count(),
            ];
        }

        return [
            'invited' => (int) ($service->candidates_invited ?? 0),
            'notified' => (int) ($service->candidates_notified ?? 0),
            'accepted' => (int) ($service->candidates_accepted ?? 0),
            'declined' => (int) ($service->candidates_declined ?? 0),
            'expired' => (int) ($service->candidates_expired ?? 0),
        ];
    }

    /** Cêntimos → euros. `null` fica `null`: zero seria dizer que é de graça. */
    private function euros(int|float|null $centimos): ?float
    {
        return $centimos === null ? null : round($centimos / 100, 2);
    }

    /**
     * Nomes em snake_case e iguais aos que o backoffice já espera
     * (`LaravelServiceRow`), para a ligação ser só ligar o interruptor.
     */
    private function present(Service $service): array
    {
        $morada = is_array($service->address) ? $service->address : [];
        $comissao = $service->amount !== null && $service->amount_for_vendor !== null
            ? $service->amount - $service->amount_for_vendor
            : null;

        return [
            'id' => (string) $service->id,
            'customer_id' => $service->customer_id,
            'customer_name' => $service->customerUser?->name,
            'customer_phone' => $service->customerUser?->phone_number,
            'technician_id' => $service->vendor_id,
            'technician_name' => $service->vendor?->user?->name,
            /*
             * Os ids e os telefones das duas pessoas do pedido, em bruto. O
             * backoffice é que os esconde ou mostra consoante a permissão de
             * quem está a ver — decidir isso aqui obrigava a API a saber quem
             * é quem, e ela só conhece o token partilhado do backoffice.
             * `vendor_id` é o mesmo valor que `technician_id`, com o nome que
             * o resto da API usa.
             */
            'vendor_id' => $service->vendor_id,
            'vendor_phone' => $service->vendor?->user?->phone_number,
            'category_id' => $service->services_type_id,
            'category_name' => $service->serviceType?->name,
            // A categoria que a equipa conhece, para o backoffice filtrar por ela.
            'operation_area_id' => $service->serviceType?->operation_area_id,
            'service_name' => $service->serviceType?->name,
            'location' => $morada['address'] ?? $morada['street'] ?? null,
            'city' => $morada['city'] ?? $morada['locality'] ?? null,
            'source' => 'app',
            'status' => $service->status?->value ?? (string) $service->status,
            'payment_status' => $service->payment_status?->value ?? (string) $service->payment_status,
            'requested_at' => optional($service->created_at)->toIso8601String(),
            /*
             * A marcação vive na tabela `schedules`, não no serviço. O dia e a
             * hora saem separados como estão gravados -- juntá-los aqui seria
             * inventar um fuso que ninguém escreveu.
             */
            'scheduled_day' => $service->schedule?->scheduled_day,
            'scheduled_time' => $service->schedule?->scheduled_time_start,
            'started_at' => optional($service->arrived_at)->toIso8601String(),
            'on_the_way_at' => optional($service->on_the_way_at)->toIso8601String(),
            /*
             * Não há coluna de conclusão: o serviço fecha por mudança de estado
             * (Finished/Closed). `updated_at` é o mais próximo que existe e só
             * se devolve quando o serviço está mesmo fechado -- em qualquer
             * outro estado seria a data da última alteração, que não é a mesma
             * coisa e induziria em erro.
             */
            'completed_at' => in_array($service->status?->value, ['Finished', 'Closed'], true)
                ? optional($service->updated_at)->toIso8601String()
                : null,
            'total_customer_value' => $this->euros($service->amount),
            'technician_value' => $this->euros($service->amount_for_vendor),
            'piquet_revenue' => $this->euros($comissao),
            'rating' => $service->rating_by_customer,
            'customer_notes' => $service->customer_notes,
            /*
             * Pedido personalizado: o que o cliente descreveu por palavras dele,
             * quando não há tipo de catálogo que sirva.
             *
             * O backoffice mostrava aqui seis pedidos inventados enquanto os
             * reais -- serviços com `is_custom` -- não chegavam à API de admin.
             */
            'is_custom' => (bool) $service->is_custom,
            'custom_description' => $service->custom_description,
            'custom_duration_minutes' => $service->custom_duration_minutes,
            'custom_dispatched_at' => $service->custom_dispatched_at?->toIso8601String(),
            'custom_categories' => $service->operationAreas->pluck('name')->all(),
            /*
             * Quantas fotografias o cliente anexou.
             *
             * Só a CONTAGEM na listagem: os URLs são assinados e temporários, e
             * gerar dezenas por página para imagens que ninguém abriu seria
             * trabalho deitado fora. O detalhe (`show`) traz os URLs.
             */
            'customer_photos_count' => $service->getMedia('customer')->count(),
            /*
             * O estado do matching. Sem isto não se sabe a diferença entre "não
             * apareceu ninguém" e "ninguém foi sequer perguntado" -- que é a
             * diferença entre um problema de rede e um problema de sistema.
             */
            'candidates' => $this->contagemDeCandidatos($service),
            /*
             * Se o pedido está à espera de uma pessoa, e porquê — os mesmos
             * motivos e níveis das Operações ao vivo. null quando não pede
             * nada. É também o que a lista usa para os pôr primeiro
             * (`atencao=1`).
             */
            'atencao' => $this->atencao($service),
        ];
    }

    /**
     * A ordenação pedida pelo backoffice, ou a de sempre (o mais recente
     * primeiro). Só os campos da lista branca: o nome da coluna vem de fora,
     * e uma ordenação desconhecida ignora-se em vez de rebentar.
     *
     * O desempate é sempre pelo id, para a paginação não repetir nem saltar
     * pedidos com o mesmo valor.
     */
    private function ordenar(Builder $query, string $campo, string $sentido): void
    {
        if (! in_array($campo, self::ORDENACOES, true)) {
            $query->orderByDesc('services.id');

            return;
        }

        $sentido = in_array(strtolower($sentido), ['asc', 'desc'], true) ? strtolower($sentido) : 'desc';

        switch ($campo) {
            case 'id':
                $query->orderBy('services.id', $sentido);

                return;

            case 'created_at':
                $query->orderBy('services.created_at', $sentido)->orderBy('services.id', $sentido);

                return;

            case 'amount':
                // Os pedidos ainda sem preço (à procura, personalizados por
                // rever) vão para o fim nos dois sentidos: não são "os mais
                // baratos".
                $query->orderByRaw('services.amount IS NULL')
                    ->orderBy('services.amount', $sentido)
                    ->orderByDesc('services.id');

                return;

            case 'scheduled_at':
                // A marcação vive na tabela `schedule` (dia e hora de relógio
                // de Lisboa, como o cliente a escolheu). Os pedidos sem marcação
                // vão para o fim nos dois sentidos.
                $quando = '(SELECT MIN(TIMESTAMP(agenda.scheduled_day, agenda.scheduled_time_start)) FROM schedule AS agenda'
                    .' WHERE agenda.service_id = services.id AND agenda.deleted_at IS NULL)';

                $query->orderByRaw("{$quando} IS NULL")
                    ->orderByRaw("{$quando} {$sentido}")
                    ->orderByDesc('services.id');

                return;
        }
    }

    /**
     * Os pedidos de um dia (de Lisboa): os MARCADOS para esse dia e, dos que
     * não têm marcação, os CRIADOS nesse dia.
     *
     * "Não tem marcação" é não ter linha na tabela `schedule` — a mesma fonte
     * do `scheduled_day` que a lista mostra. Um agendado ainda à procura de
     * técnico tem a intenção guardada no próprio pedido e só ganha a linha
     * depois de pago; até lá conta no dia em que foi pedido, que é também o
     * dia em que a equipa trabalha nele.
     *
     * O `created_at` está em UTC: o dia de Lisboa converte-se para UTC antes
     * de comparar, senão o pedido das 00:30 de Lisboa (23:30 UTC do dia
     * anterior, no verão) caía no dia errado.
     */
    private function doDia(Builder $query, string $dia): void
    {
        $inicio = CarbonImmutable::createFromFormat('!Y-m-d', $dia, 'Europe/Lisbon');
        $fim = $inicio->addDay();

        $query->where(fn (Builder $q) => $q
            ->whereHas('schedule', fn (Builder $marcacao) => $marcacao->whereDate('scheduled_day', $dia))
            ->orWhere(fn (Builder $semMarcacao) => $semMarcacao
                ->whereDoesntHave('schedule')
                ->where('services.created_at', '>=', $inicio->utc())
                ->where('services.created_at', '<', $fim->utc())));
    }

    /**
     * Marcações que ainda estão para vir, com um técnico já atribuído.
     *
     * É a pergunta de quem vai suspender um técnico: "que clientes ficam sem
     * ninguém?". Por isso entram também os `Pending` com marcação — já pagos,
     * à espera de o técnico confirmar —, que ficam tão sem técnico como os
     * confirmados.
     *
     * "Para vir" compara com a hora de relógio de Lisboa: é assim que o dia e
     * a hora da marcação estão gravados.
     */
    private function soMarcacoesPorVir(Builder $query): void
    {
        $agora = now('Europe/Lisbon');
        $hoje = $agora->toDateString();
        $hora = $agora->format('H:i:s');

        $query->whereIn('services.status', [ServiceStatus::PENDING, ServiceStatus::ACCEPTED, ServiceStatus::SCHEDULED])
            ->whereHas('schedule', fn (Builder $marcacao) => $marcacao->where(fn (Builder $q) => $q
                ->whereDate('scheduled_day', '>', $hoje)
                ->orWhere(fn (Builder $hojeMaisTarde) => $hojeMaisTarde
                    ->whereDate('scheduled_day', $hoje)
                    ->whereTime('scheduled_time_start', '>', $hora))));
    }

    private function comMotivoDeAtencao(Builder $query): void
    {
        [$sql, $bindings] = $this->sqlDoMotivoDeAtencao();

        $query->selectRaw("{$sql} AS atencao_motivo", $bindings);
    }

    /**
     * Porque é que um pedido precisa de uma pessoa — em SQL, para a lista o
     * poder ORDENAR por isso e paginar sem carregar os pedidos todos.
     *
     * São as regras do OperacoesAoVivoController, com os mesmos nomes:
     *
     *  - `personalizado_por_rever` (crítico): pedido personalizado à espera de
     *    o backoffice definir tempo e categorias;
     *  - `pagamento_por_capturar` (crítico): o cliente confirmou mas a
     *    cobrança falhou — o técnico ainda não recebeu;
     *  - `ninguem_convidado` (crítico): à procura há mais de dois minutos e
     *    ninguém foi convidado;
     *  - `ninguem_a_responder` (crítico): convidou-se, ninguém aceitou e já
     *    não há ninguém a quem a resposta ainda possa chegar;
     *  - `cliente_nao_confirmou` (atenção): o técnico terminou e o cliente não
     *    confirmou num dia.
     *
     * Ficam de fora os alertas dos PRAZOS (`prazo_esgotado`, `prazo_a_acabar`)
     * e o `cliente_a_escolher`: dependem dos relógios do MatchingService, que
     * só se calculam pedido a pedido, e resolvem-se sozinhos em minutos pelo
     * `matching:advance` — não são trabalho para a equipa.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function sqlDoMotivoDeAtencao(): array
    {
        $candidatos = 'SELECT 1 FROM service_candidates AS candidato WHERE candidato.service_id = services.id';
        $responderam = implode(', ', array_fill(0, count(self::ACEITARAM) + 1, '?'));

        $sql = 'CASE'
            ." WHEN services.status = ? THEN 'personalizado_por_rever'"
            ." WHEN services.status = ? THEN 'pagamento_por_capturar'"
            ." WHEN services.status = ? AND NOT EXISTS ({$candidatos})"
            ."  AND COALESCE(services.custom_dispatched_at, services.created_at) <= ? THEN 'ninguem_convidado'"
            ." WHEN services.status = ? AND EXISTS ({$candidatos})"
            ."  AND NOT EXISTS ({$candidatos} AND candidato.status IN ({$responderam})) THEN 'ninguem_a_responder'"
            ." WHEN services.status = ? AND services.updated_at < ? THEN 'cliente_nao_confirmou'"
            .' END';

        return [$sql, [
            ServiceStatus::PENDING_REVIEW->value,
            ServiceStatus::CLOSED_PENDING_PAYMENT->value,
            ServiceStatus::MATCHING->value,
            now()->subSeconds(self::SEGUNDOS_SEM_CONVITES),
            ServiceStatus::MATCHING->value,
            // Quem ainda pode responder, ou já disse que sim.
            CandidateStatus::NOTIFIED->value,
            ...array_map(fn (CandidateStatus $c) => $c->value, self::ACEITARAM),
            ServiceStatus::FINISHED->value,
            now()->subHours(self::HORAS_SEM_CONFIRMACAO),
        ]];
    }

    /** @return array{nivel: string, motivo: string}|null */
    private function atencao(Service $service): ?array
    {
        $motivo = $service->getAttribute('atencao_motivo');

        if (! $motivo) {
            return null;
        }

        return [
            'nivel' => $motivo === self::MOTIVO_SO_ATENCAO ? 'atencao' : 'critico',
            'motivo' => $motivo,
        ];
    }
}
