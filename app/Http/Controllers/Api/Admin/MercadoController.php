<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Services\CandidateStatus;
use App\Enums\Services\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\GeneralSettings\City;
use App\Models\GeneralSettings\OperationArea;
use App\Models\Service;
use App\Services\Operacoes\CidadeDoPedido;
use App\Services\Operacoes\DesfechoDoPedido;
use App\Services\Operacoes\OfertaAgora;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * GET /v1/admin/mercado — procura e oferta ao longo do tempo, por cidade e
 * por categoria.
 *
 * As Operações ao vivo respondem a "este pedido vai ser servido?". Isto
 * responde à pergunta seguinte: ONDE e EM QUÊ é que a Piquet não chega. Semana
 * a semana, quantos pedidos entraram e quantos foram servidos; quanto tempo
 * leva o primeiro "sim"; como acabaram os que não foram; e a matriz cidade ×
 * categoria, com a procura do período ao lado dos técnicos que há agora — para
 * o backoffice pintar de vermelho onde há pedidos e não há quem os faça.
 *
 * Parâmetros, todos opcionais:
 *  - de, ate (AAAA-MM-DD, dias de Lisboa): por omissão as 12 semanas completas
 *    anteriores mais a atual;
 *  - cidade: um nome da lista `cidades`;
 *  - categoria: o id de uma área de operação (lista `categorias`);
 *  - incluir_testes=1: as contas de teste em vez das reais, como nas
 *    Operações ao vivo.
 *
 * "Servido" é o da `taxa_servidos` (DesfechoDoPedido). A procura conta-se na
 * base de dados, agrupada; em PHP só se classificam as poucas linhas que o
 * agrupamento devolve, com a mesma regra de sempre — duas versões da regra,
 * uma em SQL e outra em PHP, divergiam à primeira alteração.
 *
 * A oferta é a de AGORA (quem está Online, ver OfertaAgora): não há histórico
 * de quem esteve Online na semana passada. A matriz põe a procura do período
 * ao lado da oferta de hoje, e é isso que diz.
 */
class MercadoController extends Controller
{
    private const FUSO = 'Europe/Lisbon';

    /** Por omissão: estas semanas completas para trás, mais a atual. */
    public const SEMANAS_POR_OMISSAO = 12;

    /** 53 semanas: chega para ver uma tendência e mantém a consulta das semanas pequena. */
    public const DIAS_MAXIMOS = 371;

    public const PERDIDOS_A_MOSTRAR = 20;

    /** Online sem mandar a posição há mais do que isto (ou nunca). */
    public const DIAS_SEM_POSICAO = 7;

    /** Como acabou cada pedido. Somadas, as chaves dão os pedidos criados. */
    public const COMO_ACABARAM = [
        'servidos', 'sem_resposta', 'recusados', 'cancelados_cliente', 'cancelados_tecnico', 'em_curso',
        'sem_oferta', 'sem_escolha', 'pagamento_falhou', 'outros',
    ];

    /** O motivo de cada perda, pela chave de `como_acabaram`. */
    private const MOTIVO_DA_PERDA = [
        'sem_oferta' => 'sem_oferta',
        'sem_resposta' => 'sem_resposta',
        'recusados' => 'recusado',
        'sem_escolha' => 'sem_escolha',
        'pagamento_falhou' => 'pagamento_falhou',
        'cancelados_cliente' => 'cancelado_cliente',
    ];

    private CidadeDoPedido $cidades;

    public function __invoke(Request $request): ApiSuccessResponse
    {
        $dados = $request->validate([
            'de' => ['nullable', 'date_format:Y-m-d'],
            'ate' => ['nullable', 'date_format:Y-m-d'],
            'cidade' => ['nullable', 'string', 'max:190'],
            'categoria' => ['nullable', 'integer', 'exists:operation_areas,id'],
        ], [
            'de.date_format' => 'A data "de" tem de vir como AAAA-MM-DD.',
            'ate.date_format' => 'A data "ate" tem de vir como AAAA-MM-DD.',
            'categoria.integer' => 'A categoria é o id de uma área de operação.',
            'categoria.exists' => 'Essa categoria não existe.',
        ]);

        [$de, $ate] = $this->periodo($dados['de'] ?? null, $dados['ate'] ?? null);
        $testes = $request->boolean('incluir_testes');
        $this->cidades = new CidadeDoPedido;

        $filtro = [
            'testes' => $testes,
            'inicio' => $de->utc(),
            // Exclusivo: até ao fim do dia `ate`, em Lisboa.
            'fim' => $ate->addDay()->utc(),
            'categoria' => isset($dados['categoria']) ? (int) $dados['categoria'] : null,
            'cidade' => null,
            'textos_da_cidade' => [],
        ];

        // Primeiro os nomes que as moradas do período usam, dos mais
        // frequentes para os menos: é o mais usado que dá nome à linha quando
        // a cidade não está no catálogo.
        $cidadesNoPeriodo = $this->cidadesNoPeriodo($filtro);

        $cidadePedida = trim((string) ($dados['cidade'] ?? ''));
        if ($cidadePedida !== '') {
            $filtro['cidade'] = $this->cidades->nome($cidadePedida);
            $filtro['textos_da_cidade'] = $cidadesNoPeriodo
                ->filter(fn (string $texto) => CidadeDoPedido::mesma($texto, $filtro['cidade']))
                ->values()
                ->all();
        }

        $oferta = OfertaAgora::online($testes);

        return ApiSuccessResponse::make([
            'gerado_em' => now()->toIso8601String(),
            'periodo' => ['de' => $de->toDateString(), 'ate' => $ate->toDateString()],
            'filtros' => ['cidade' => $filtro['cidade'], 'categoria' => $filtro['categoria']],
            'semanas' => $this->semanas($filtro, $de, $ate),
            'tempo_ate_aceitar' => $this->tempoAteAceitar($filtro),
            'como_acabaram' => $this->comoAcabaram($filtro),
            'matriz' => $this->matriz($filtro, $oferta),
            'perdidos' => $this->perdidos($filtro),
            'online_sem_posicao' => $this->onlineSemPosicao($filtro, $oferta),
            'cidades' => $this->opcoesDeCidade($cidadesNoPeriodo, $testes),
            'categorias' => OperationArea::query()->ordered()->get()
                ->map(fn (OperationArea $a) => ['id' => $a->id, 'nome' => $this->nomeDaArea($a)])
                ->values()
                ->all(),
        ]);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} o primeiro e o último dia, em Lisboa */
    private function periodo(?string $de, ?string $ate): array
    {
        $hoje = CarbonImmutable::now(self::FUSO)->startOfDay();

        $ate = $ate ? CarbonImmutable::createFromFormat('!Y-m-d', $ate, self::FUSO) : $hoje;
        $de = $de
            ? CarbonImmutable::createFromFormat('!Y-m-d', $de, self::FUSO)
            : $hoje->startOfWeek(CarbonInterface::MONDAY)->subWeeks(self::SEMANAS_POR_OMISSAO);

        if ($de->gt($ate)) {
            throw ValidationException::withMessages(['de' => 'A data "de" não pode ser depois de "ate".']);
        }
        if ($de->diffInDays($ate) > self::DIAS_MAXIMOS) {
            throw ValidationException::withMessages(['de' => 'O período não pode passar de '.self::DIAS_MAXIMOS.' dias.']);
        }

        return [$de, $ate];
    }

    // ------------------------------------------------------------ a procura

    /**
     * Os pedidos do período, com os filtros. Uma query por bloco, todas a
     * partir daqui, para nenhum bloco contar um universo diferente.
     */
    private function pedidos(array $f): Builder
    {
        $q = Service::query()
            ->where('services.is_test', $f['testes'])
            ->where('services.created_at', '>=', $f['inicio'])
            ->where('services.created_at', '<', $f['fim']);

        if ($f['categoria'] !== null) {
            $area = $f['categoria'];
            // Do catálogo, pela área do tipo; personalizado, pelas áreas que
            // o backoffice lhe deu. Pela tabela e não pela relação: um tipo
            // que saiu do catálogo continua a ter a sua área no histórico.
            $q->where(fn (Builder $c) => $c
                ->whereIn('services.services_type_id', fn (QueryBuilder $t) => $t
                    ->select('id')->from('services_types')->where('operation_area_id', $area))
                ->orWhereExists(fn (QueryBuilder $e) => $e
                    ->selectRaw('1')->from('service_operation_area')
                    ->whereColumn('service_operation_area.service_id', 'services.id')
                    ->where('service_operation_area.operation_area_id', $area)));
        }

        if ($f['cidade'] !== null) {
            $f['textos_da_cidade']
                ? $q->whereIn(DB::raw(CidadeDoPedido::sql()), $f['textos_da_cidade'])
                : $q->whereRaw('1 = 0');
        }

        return $q;
    }

    /**
     * Quantos convites, quantos "sim" e quantas recusas teve cada pedido, numa
     * subconsulta só. Os convites de um pedido nascem depois dele, por isso o
     * corte pela data é seguro e poupa a tabela inteira.
     */
    private function convites(array $f): QueryBuilder
    {
        $sim = array_map(fn (CandidateStatus $e) => $e->value, DesfechoDoPedido::DISSERAM_SIM);

        return DB::table('service_candidates')
            ->selectRaw(
                'service_id, COUNT(*) AS convidados, SUM(status IN (?, ?, ?)) AS sims, SUM(status = ?) AS recusas',
                [...$sim, CandidateStatus::DECLINED->value],
            )
            ->where('created_at', '>=', $f['inicio'])
            ->groupBy('service_id');
    }

    /** As semanas (segunda-feira de Lisboa) do período, com criados e servidos. */
    private function semanas(array $f, CarbonImmutable $de, CarbonImmutable $ate): array
    {
        $segundas = [];
        for ($s = $de->startOfWeek(CarbonInterface::MONDAY); $s->lte($ate); $s = $s->addWeek()) {
            $segundas[] = $s;
        }

        // A semana de cada pedido decide-se na base de dados, pelas fronteiras
        // em UTC calculadas aqui. CONVERT_TZ pedia as tabelas de fusos do
        // MySQL carregadas, e não há garantia de que estejam.
        $quando = [];
        $ligacoes = [];
        foreach (array_slice($segundas, 1) as $i => $segunda) {
            $quando[] = "WHEN services.created_at < ? THEN {$i}";
            $ligacoes[] = $segunda->utc();
        }
        $semana = $quando
            ? 'CASE '.implode(' ', $quando).' ELSE '.(count($segundas) - 1).' END'
            : '0';

        $linhas = $this->pedidos($f)
            ->selectRaw("{$semana} AS semana, services.status AS estado, services.vendor_id IS NOT NULL AS tem_tecnico, COUNT(*) AS total", $ligacoes)
            ->groupBy('semana', 'estado', 'tem_tecnico')
            ->toBase()
            ->get();

        $contas = array_fill(0, count($segundas), ['criados' => 0, 'servidos' => 0]);
        foreach ($linhas as $linha) {
            $i = (int) $linha->semana;
            $contas[$i]['criados'] += (int) $linha->total;
            if ($this->servido($linha->estado, (bool) $linha->tem_tecnico)) {
                $contas[$i]['servidos'] += (int) $linha->total;
            }
        }

        return array_map(fn (CarbonImmutable $segunda, array $c) => [
            'inicio' => $segunda->toDateString(),
            ...$c,
        ], $segundas, $contas);
    }

    /**
     * Do momento em que o pedido entrou em seleção até ao primeiro "sim".
     *
     * A entrada em seleção é a criação, exceto num personalizado, em que é o
     * envio pelo backoffice — o mesmo início da mediana das Operações ao vivo.
     * Contar a análise do backoffice como demora dos técnicos punha-lhes às
     * costas horas que não são deles.
     */
    private function tempoAteAceitar(array $f): array
    {
        $sim = array_map(fn (CandidateStatus $e) => $e->value, DesfechoDoPedido::DISSERAM_SIM);

        $segundos = $this->pedidos($f)
            ->join('service_candidates as sc', 'sc.service_id', '=', 'services.id')
            ->whereIn('sc.status', $sim)
            ->whereNotNull('sc.responded_at')
            ->groupBy('services.id', 'services.custom_dispatched_at', 'services.created_at')
            ->selectRaw('TIMESTAMPDIFF(SECOND, COALESCE(services.custom_dispatched_at, services.created_at), MIN(sc.responded_at)) AS segundos')
            ->toBase()
            ->pluck('segundos')
            ->map(fn ($s) => max(0, (int) $s))
            ->sort()
            ->values()
            ->all();

        $minutos = fn (?float $s) => $s === null ? null : round($s / 60, 1);

        return [
            'mediana_min' => $minutos(self::percentil($segundos, 0.5)),
            'p90_min' => $minutos(self::percentil($segundos, 0.9)),
            'amostra' => count($segundos),
        ];
    }

    private function comoAcabaram(array $f): array
    {
        $contas = array_fill_keys(self::COMO_ACABARAM, 0);

        $linhas = $this->pedidos($f)
            ->leftJoinSub($this->convites($f), 'c', 'c.service_id', '=', 'services.id')
            ->selectRaw('services.status AS estado, services.vendor_id IS NOT NULL AS tem_tecnico, '
                .'COALESCE(c.convidados, 0) > 0 AS convidou, COALESCE(c.sims, 0) > 0 AS algum_sim, '
                .'COALESCE(c.recusas, 0) > 0 AS alguma_recusa, '
                .'(services.vendor_canceled_at IS NOT NULL OR services.vendor_no_show_at IS NOT NULL) AS pelo_tecnico, '
                .'COUNT(*) AS total')
            ->groupBy('estado', 'tem_tecnico', 'convidou', 'algum_sim', 'alguma_recusa', 'pelo_tecnico')
            ->toBase()
            ->get();

        foreach ($linhas as $linha) {
            $contas[$this->comoAcabou($linha)] += (int) $linha->total;
        }

        return $contas;
    }

    /**
     * A chave de `como_acabaram` de um pedido (ou de uma linha agrupada).
     *
     * Parte do DesfechoDoPedido — o mesmo das Operações ao vivo — e só abre
     * dois dos desfechos dele:
     *  - "sem resposta" (ninguém disse que sim) divide-se em `recusados`,
     *    quando alguém viu e disse que não, e `sem_resposta`, quando os
     *    convites caducaram sem ninguém lhes tocar. Pedem respostas
     *    diferentes: rever o preço ou a hora, ou acordar a oferta;
     *  - "cancelado" divide-se por quem cancelou: o técnico (cancelou depois
     *    de aceitar, ou faltou) ou o resto — na prática o cliente, mais os
     *    cancelamentos feitos pelo backoffice.
     * O `Refused` antigo (o técnico recusou um pedido feito diretamente a
     * ele) é uma recusa e conta como tal.
     */
    private function comoAcabou(object $p): string
    {
        $estado = ServiceStatus::tryFrom((string) $p->estado);

        if (! $estado) {
            return 'outros';
        }
        if ($estado === ServiceStatus::REFUSED) {
            return 'recusados';
        }

        return match (DesfechoDoPedido::de($estado, (bool) $p->tem_tecnico, $p->convidou ? 1 : 0, (bool) $p->algum_sim)) {
            DesfechoDoPedido::SERVIDO => 'servidos',
            DesfechoDoPedido::EM_ABERTO => 'em_curso',
            DesfechoDoPedido::SEM_RESPOSTA => $p->alguma_recusa ? 'recusados' : 'sem_resposta',
            DesfechoDoPedido::CANCELADO => $p->pelo_tecnico ? 'cancelados_tecnico' : 'cancelados_cliente',
            DesfechoDoPedido::SEM_OFERTA => 'sem_oferta',
            DesfechoDoPedido::SEM_ESCOLHA => 'sem_escolha',
            DesfechoDoPedido::PAGAMENTO_FALHOU => 'pagamento_falhou',
            default => 'outros',
        };
    }

    private function servido(?string $estado, bool $temTecnico): bool
    {
        $estado = ServiceStatus::tryFrom((string) $estado);

        return $estado !== null && DesfechoDoPedido::servido($estado, $temTecnico);
    }

    // ----------------------------------------------------- procura × oferta

    /**
     * Cidade × categoria: os pedidos do período e os técnicos Online de agora.
     *
     * Um pedido personalizado com duas categorias conta nas duas — é por
     * qualquer uma delas que se convida. Um técnico conta em cada cidade que
     * escolheu e em cada categoria que faz; sem cidades, na linha `cidade:
     * null` (no matching não tem restrição de cidade, só o raio máximo).
     */
    private function matriz(array $f, Collection $oferta): array
    {
        $linhas = [];
        $linha = function (?string $cidade, ?int $area) use (&$linhas): string {
            $chave = ($cidade ?? '').'|'.($area ?? '');
            $linhas[$chave] ??= [
                'cidade' => $cidade, 'categoria_id' => $area,
                'criados' => 0, 'servidos' => 0,
                'tecnicos_online' => 0, 'tecnicos_podem_aceitar' => 0, 'tecnicos_prontos' => 0,
            ];

            return $chave;
        };

        $procura = $this->pedidos($f)
            ->leftJoin('services_types as st', 'st.id', '=', 'services.services_type_id')
            ->leftJoin('service_operation_area as soa', fn ($j) => $j
                ->on('soa.service_id', '=', 'services.id')
                ->whereNull('services.services_type_id'))
            ->selectRaw(CidadeDoPedido::sql().' AS cidade, COALESCE(st.operation_area_id, soa.operation_area_id) AS area_id, '
                .'services.status AS estado, services.vendor_id IS NOT NULL AS tem_tecnico, COUNT(*) AS total')
            ->groupBy('cidade', 'area_id', 'estado', 'tem_tecnico')
            ->toBase()
            ->get();

        foreach ($procura as $p) {
            $area = $p->area_id !== null ? (int) $p->area_id : null;
            // O filtro deixa passar um personalizado inteiro; as outras
            // categorias dele não são desta pergunta.
            if ($f['categoria'] !== null && $area !== $f['categoria']) {
                continue;
            }
            $chave = $linha($this->cidades->nome($p->cidade), $area);
            $linhas[$chave]['criados'] += (int) $p->total;
            if ($this->servido($p->estado, (bool) $p->tem_tecnico)) {
                $linhas[$chave]['servidos'] += (int) $p->total;
            }
        }

        foreach ($oferta as $o) {
            foreach ($this->cidadesDoTecnico($o, $f) as $cidade) {
                foreach ($this->areasDoTecnico($o, $f) as $area) {
                    $chave = $linha($cidade, $area);
                    $linhas[$chave]['tecnicos_online']++;
                    $linhas[$chave]['tecnicos_podem_aceitar'] += $o['pode_aceitar'] ? 1 : 0;
                    $linhas[$chave]['tecnicos_prontos'] += $o['pronto'] ? 1 : 0;
                }
            }
        }

        $nomes = $this->nomesDasAreas(array_filter(array_column($linhas, 'categoria_id')));

        return collect($linhas)
            ->map(fn (array $l) => [
                'cidade' => $l['cidade'],
                'categoria_id' => $l['categoria_id'],
                'categoria' => $l['categoria_id'] !== null ? ($nomes[$l['categoria_id']] ?? (string) $l['categoria_id']) : null,
                ...array_diff_key($l, ['cidade' => true, 'categoria_id' => true]),
            ])
            ->sort(fn (array $a, array $b) => [$b['criados'], $b['tecnicos_online'], $a['cidade'] === null, $a['cidade'], $a['categoria'] === null, $a['categoria']]
                <=> [$a['criados'], $a['tecnicos_online'], $b['cidade'] === null, $b['cidade'], $b['categoria'] === null, $b['categoria']])
            ->values()
            ->all();
    }

    /** @return list<?string> as cidades em que o técnico conta, já com o filtro */
    private function cidadesDoTecnico(array $o, array $f): array
    {
        $cidades = $o['cidades'] ?: [null];

        if ($f['cidade'] === null) {
            return $cidades;
        }

        return array_values(array_filter($cidades, fn (?string $c) => CidadeDoPedido::mesma($c, $f['cidade'])));
    }

    /** @return list<?int> as categorias em que o técnico conta, já com o filtro */
    private function areasDoTecnico(array $o, array $f): array
    {
        $areas = $o['areas'] ?: [null];

        if ($f['categoria'] === null) {
            return $areas;
        }

        return in_array($f['categoria'], $o['areas'], true) ? [$f['categoria']] : [];
    }

    /**
     * Os últimos pedidos do período que acabaram sem técnico: ninguém para
     * convidar, ninguém disse que sim, o cliente não escolheu ou não pagou,
     * ou desistiu antes de haver técnico.
     */
    private function perdidos(array $f): array
    {
        $servicos = $this->pedidos($f)
            ->leftJoinSub($this->convites($f), 'c', 'c.service_id', '=', 'services.id')
            ->where(fn (Builder $q) => $q
                ->whereIn('services.status', [ServiceStatus::MATCHING_FAILED, ServiceStatus::REFUSED, ...DesfechoDoPedido::PAGAMENTO])
                // Desistiu antes de haver técnico. Cancelado pelo técnico não
                // é perda de procura: o pedido reabre para outro.
                ->orWhere(fn (Builder $c) => $c
                    ->where('services.status', ServiceStatus::CANCELED)
                    ->whereNull('services.vendor_id')
                    ->whereNull('services.vendor_canceled_at')
                    ->whereNull('services.vendor_no_show_at')))
            ->select('services.*')
            ->selectRaw('services.status AS estado, services.vendor_id IS NOT NULL AS tem_tecnico, '
                .'COALESCE(c.convidados, 0) > 0 AS convidou, COALESCE(c.sims, 0) > 0 AS algum_sim, '
                .'COALESCE(c.recusas, 0) > 0 AS alguma_recusa')
            ->with([
                'serviceType' => fn ($q) => $q->withTrashed()->with(['operationArea' => fn ($a) => $a->withTrashed()]),
                'operationAreas' => fn ($q) => $q->withTrashed(),
            ])
            ->orderByDesc('services.created_at')
            ->orderByDesc('services.id')
            ->limit(self::PERDIDOS_A_MOSTRAR)
            ->get();

        return $servicos
            ->map(function (Service $s) {
                $morada = is_array($s->address) ? $s->address : [];
                $areas = $s->is_custom
                    ? $s->operationAreas
                    : collect([$s->serviceType?->operationArea])->filter();

                return [
                    'id' => (string) $s->id,
                    'criado_em' => $s->created_at?->toIso8601String(),
                    // Como em CidadeDoPedido::sql(): `city`, ou `locality` quando vem vazia.
                    'cidade' => $this->cidades->nome(filled($morada['city'] ?? null) ? $morada['city'] : ($morada['locality'] ?? null)),
                    'categoria' => $areas->map(fn (OperationArea $a) => $this->nomeDaArea($a))->implode(', ') ?: null,
                    'tipo' => $s->serviceType?->getTranslation('name', 'pt-pt', false) ?: $s->serviceType?->name,
                    // Os cancelados que chegam aqui são todos sem técnico
                    // (ver o filtro acima), por isso `pelo_tecnico` é falso.
                    'motivo' => self::MOTIVO_DA_PERDA[$this->comoAcabou((object) [
                        'estado' => $s->getRawOriginal('estado'),
                        'tem_tecnico' => $s->getRawOriginal('tem_tecnico'),
                        'convidou' => $s->getRawOriginal('convidou'),
                        'algum_sim' => $s->getRawOriginal('algum_sim'),
                        'alguma_recusa' => $s->getRawOriginal('alguma_recusa'),
                        'pelo_tecnico' => false,
                    ])] ?? 'outro',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Online sem mandar a posição há mais de DIAS_SEM_POSICAO (ou nunca).
     *
     * São os "Online" que não estão lá: a 06/10, 49 dos 51 Online estavam
     * assim. Contam como oferta em todo o lado e não aparecem a ninguém no
     * "Pedir agora". É a mesma lista que o `vendors:expirar-online` passa a
     * Offline ao fim de 72 h, menos quem tem um serviço em curso — se
     * aparecer aqui alguém, ou tem trabalho em mãos ou o comando não correu.
     */
    private function onlineSemPosicao(array $f, Collection $oferta): array
    {
        $limite = now()->subDays(self::DIAS_SEM_POSICAO);

        return $oferta
            ->filter(fn (array $o) => ! $o['ultima_posicao'] || $o['ultima_posicao']->lt($limite))
            ->filter(fn (array $o) => $f['cidade'] === null || $this->cidadesDoTecnico($o, $f) !== [])
            ->filter(fn (array $o) => $f['categoria'] === null || in_array($f['categoria'], $o['areas'], true))
            ->sortBy(fn (array $o) => $o['ultima_posicao']?->getTimestamp() ?? 0)
            ->map(function (array $o) {
                $user = $o['vendor']->user;

                return [
                    'vendor_id' => $o['vendor']->id,
                    'nome' => $user ? (trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: null) : null,
                    'cidade' => implode(', ', $o['cidades']) ?: null,
                    'cidades' => $o['cidades'],
                    'ultima_posicao_em' => $o['ultima_posicao']?->toIso8601String(),
                    'pode_aceitar' => $o['pode_aceitar'],
                ];
            })
            ->values()
            ->all();
    }

    // ------------------------------------------------------------- filtros

    /**
     * As cidades das moradas do período, das mais pedidas para as menos.
     * Sem os filtros de cidade e categoria: é daqui que sai a lista para
     * escolher, e o próprio filtro de cidade.
     *
     * @return Collection<int, string>
     */
    private function cidadesNoPeriodo(array $f): Collection
    {
        $textos = $this->pedidos([...$f, 'cidade' => null, 'categoria' => null])
            ->selectRaw(CidadeDoPedido::sql().' AS cidade, COUNT(*) AS total')
            ->groupBy('cidade')
            ->orderByDesc('total')
            ->orderBy('cidade')
            ->toBase()
            ->get()
            ->pluck('cidade')
            ->filter()
            ->map(fn ($t) => (string) $t)
            ->values();

        // Regista os nomes por esta ordem (ver CidadeDoPedido::nome).
        $textos->each(fn (string $t) => $this->cidades->nome($t));

        return $textos;
    }

    /** Onde houve pedidos no período, mais onde há técnicos (Online ou não). */
    private function opcoesDeCidade(Collection $cidadesNoPeriodo, bool $testes): array
    {
        $dosTecnicos = DB::table('vendor_available_cities')
            ->join('cities', 'cities.id', '=', 'vendor_available_cities.city_id')
            ->join('vendors', 'vendors.id', '=', 'vendor_available_cities.vendor_id')
            ->join('users', 'users.id', '=', 'vendors.user_id')
            ->where('users.is_test', $testes)
            ->whereNull('vendors.deleted_at')
            ->distinct()
            ->pluck('cities.name');

        return $cidadesNoPeriodo
            ->merge($dosTecnicos)
            ->map(fn ($nome) => $this->cidades->nome($nome))
            ->filter()
            ->unique()
            ->sortBy(fn (string $nome) => City::normalizar($nome))
            ->values()
            ->all();
    }

    // --------------------------------------------------------------- comum

    /** @return array<int, string> */
    private function nomesDasAreas(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return OperationArea::withTrashed()->whereIn('id', array_unique($ids))->get()
            ->mapWithKeys(fn (OperationArea $a) => [$a->id => $this->nomeDaArea($a)])
            ->all();
    }

    /** O mesmo nome que as Operações ao vivo mostram. */
    private function nomeDaArea(OperationArea $a): string
    {
        return $a->getTranslation('name', 'pt-pt', false) ?: $a->getTranslation('name', 'pt', false) ?: (string) $a->id;
    }

    /**
     * Percentil por interpolação linear (o PERCENTIL.INC das folhas de
     * cálculo): o p50 é a mediana, a mesma das Operações ao vivo.
     *
     * @param  list<int>  $ordenados
     */
    private static function percentil(array $ordenados, float $p): ?float
    {
        $n = count($ordenados);

        if ($n === 0) {
            return null;
        }

        $posicao = ($n - 1) * $p;
        $abaixo = (int) floor($posicao);
        $acima = (int) ceil($posicao);

        return $ordenados[$abaixo] + ($ordenados[$acima] - $ordenados[$abaixo]) * ($posicao - $abaixo);
    }
}
