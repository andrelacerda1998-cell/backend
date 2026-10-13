<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Services\CandidateStatus;
use App\Enums\Services\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\GeneralSettings\OperationArea;
use App\Models\Service;
use App\Models\ServiceEvent;
use App\Services\Matching\MatchingService;
use App\Services\Operacoes\DesfechoDoPedido;
use App\Services\Operacoes\OfertaAgora;
use App\Services\Operacoes\RegistoDeEventos;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * GET /v1/admin/operacoes/ao-vivo — o estado do marketplace agora.
 *
 * O backoffice media a empresa e não o marketplace: havia cartões para
 * faturas e downloads e nenhum para a pergunta que decide o negócio — este
 * pedido vai ser servido? A 06/10 nenhum dos 4 pedidos reais do mês foi, e só
 * se soube por um diagnóstico corrido à mão no GitHub.
 *
 * Quatro blocos, todos lidos das tabelas do matching:
 *
 *  - a_procura: pedidos sem técnico, com os convites, o prazo que corre e um
 *    alerta quando estão a morrer;
 *  - em_curso: com técnico, do a caminho ao pagamento por capturar;
 *  - oferta: quem está Online e quem está mesmo pronto (localização recente);
 *  - liquidez (7 e 30 dias): quantos pedidos tiveram um "sim", quantos foram
 *    servidos, quanto demorou o primeiro "sim" e onde se perderam os outros.
 *
 * Mais o `agora` (os agendados de hoje que ainda não começaram), a oferta por
 * cidade, a liquidez do mês e, em cada pedido das listas, desde quando está
 * no estado em que está — é isso que separa um pedido normal de um preso.
 *
 * As contas de teste ficam de fora, salvo `?incluir_testes=1`.
 */
class OperacoesAoVivoController extends Controller
{
    /** Quem disse que sim, mesmo que depois tenha sido escolhido ou perdido. */
    private const DISSERAM_SIM = DesfechoDoPedido::DISSERAM_SIM;

    /*
     * Os limites dos alertas de pedidos presos. Constantes com nome, e não
     * números soltos nas condições, para se poderem afinar num sítio só.
     */

    /** Agendado: a hora marcada passou há mais do que isto e o técnico não chegou. */
    public const MINUTOS_DE_ATRASO_SEM_CHEGAR = 30;

    /**
     * Imediato: não há hora marcada, há a hora em que foi aceite. Dá-se este
     * tempo de viagem antes de começar a contar o atraso de cima — sem ele,
     * um técnico a 20 km acendia um alerta crítico a meio do caminho.
     */
    public const MINUTOS_DE_VIAGEM_IMEDIATO = 30;

    /** Em execução há mais do que isto: provavelmente esqueceu-se de concluir. */
    public const HORAS_EM_EXECUCAO = 8;

    /** À espera do cliente (escolher, pagar, aprovar um extra) há mais do que isto. */
    public const HORAS_A_ESPERA_DO_CLIENTE = 24;

    private const FUSO = 'Europe/Lisbon';

    private const EM_CURSO = [
        ServiceStatus::ACCEPTED,
        ServiceStatus::SCHEDULED,
        ServiceStatus::ARRIVED,
        ServiceStatus::FINISHED,
        ServiceStatus::CLOSED_PENDING_PAYMENT,
    ];

    public function __construct(private readonly MatchingService $matching) {}

    public function __invoke(Request $request): ApiSuccessResponse
    {
        $testes = $request->boolean('incluir_testes');

        return ApiSuccessResponse::make([
            'gerado_em' => now()->toIso8601String(),
            'agora' => $this->agora($testes),
            'a_procura' => $this->aProcura($testes),
            'em_curso' => $this->emCurso($testes),
            'oferta' => $this->oferta($testes),
            'liquidez' => [
                '7d' => $this->liquidez(7, $testes),
                '30d' => $this->liquidez(30, $testes),
                'mes' => $this->liquidezDoMes($testes),
            ],
            'perdidos' => $this->perdidos($testes),
        ]);
    }

    // ------------------------------------------------------------------ agora

    private function agora(bool $testes): array
    {
        return [
            // Marcados para hoje (dia de Lisboa) que ainda não começaram: o
            // técnico não carregou em "Cheguei". Os de manhã já atrasados
            // continuam a contar — ainda não começaram.
            'agendados_hoje' => Service::query()
                ->where('is_test', $testes)
                ->whereIn('status', [ServiceStatus::SCHEDULED, ServiceStatus::ACCEPTED])
                ->whereHas('schedule', fn ($q) => $q
                    ->where('is_pending', false)
                    ->where('scheduled_day', now(self::FUSO)->toDateString()))
                ->count(),
        ];
    }

    // ------------------------------------------------------------ à procura

    private function aProcura(bool $testes): array
    {
        $servicos = Service::query()
            ->where('is_test', $testes)
            ->whereIn('status', DesfechoDoPedido::ABERTOS)
            ->with(['candidates', 'schedule', 'serviceType', 'customerUser'])
            ->orderBy('created_at')
            ->limit(200)
            ->get();

        $desde = $this->estadosDesde($servicos);

        return $servicos
            ->map(fn (Service $s) => $this->pedidoAberto($s, $desde[$s->id]))
            ->all();
    }

    /** @param  array{desde: ?CarbonInterface, fonte: string}  $desde */
    private function pedidoAberto(Service $s, array $desde): array
    {
        $contagem = $this->contagem($s->candidates);
        $inicio = $s->matchingStartedAt();
        [$prazo, $qual] = $this->prazo($s, $contagem['aceitaram'] > 0);
        $restante = $prazo ? (int) round(now()->diffInSeconds($prazo, false)) : null;
        $janela = $prazo && $inicio ? max(1, (int) $inicio->diffInSeconds($prazo, false)) : null;

        return [
            ...$this->base($s),
            ...$contagem,
            'onda' => (int) $s->candidates->max('wave'),
            'assincrono' => $this->matching->isAsync($s),
            'prazo' => $prazo?->toIso8601String(),
            'prazo_de' => $qual,
            'segundos_restantes' => $restante,
            ...$this->estadoDesde($desde),
            'alerta' => $this->alertaAberto($s, $contagem, $restante, $janela, $inicio, $desde['desde']),
        ];
    }

    /**
     * O relógio que manda agora: o da fase de convites antes do primeiro sim,
     * o do cliente depois. Os mesmos métodos que o `matching:advance` usa.
     *
     * @return array{0: ?CarbonInterface, 1: ?string}
     */
    private function prazo(Service $s, bool $algumSim): array
    {
        $estado = $s->status;

        if ($estado === ServiceStatus::AWAITING_PAYMENT || ($estado === ServiceStatus::MATCHING && $algumSim)) {
            return [$this->matching->customerDeadline($s), 'cliente'];
        }
        if ($estado === ServiceStatus::MATCHING) {
            return [$this->matching->invitationDeadline($s), 'convites'];
        }

        return [null, null];
    }

    /** @return array{nivel: string, motivo: string}|null */
    private function alertaAberto(Service $s, array $c, ?int $restante, ?int $janela, ?CarbonInterface $inicio, ?CarbonInterface $estadoDesde): ?array
    {
        $idade = $inicio ? (int) $inicio->diffInSeconds(now()) : 0;

        if ($s->status === ServiceStatus::PENDING_REVIEW) {
            return ['nivel' => 'critico', 'motivo' => 'personalizado_por_rever'];
        }
        if ($s->status === ServiceStatus::MATCHING && $c['convidados'] === 0 && $idade >= 120) {
            return ['nivel' => 'critico', 'motivo' => 'ninguem_convidado'];
        }
        if ($restante !== null && $restante <= 0) {
            return ['nivel' => 'critico', 'motivo' => 'prazo_esgotado'];
        }
        if ($s->status === ServiceStatus::MATCHING && $c['aceitaram'] === 0 && $c['convidados'] > 0 && $c['por_responder'] === 0) {
            return ['nivel' => 'critico', 'motivo' => 'ninguem_a_responder'];
        }
        // O último quinto do prazo, e nunca menos de um minuto de aviso.
        if ($restante !== null && $janela !== null && $restante <= max(60, (int) ($janela * 0.2))) {
            return ['nivel' => 'atencao', 'motivo' => 'prazo_a_acabar'];
        }
        // Do lado do cliente há mais de um dia — a escolher, a pagar ou no
        // 3-D Secure. Os prazos normais são de minutos e já teriam dado
        // `prazo_esgotado` acima: isto é a rede para o caminho que nenhum
        // relógio apanha (um 3-D Secure sem prazo, um MB Way pendurado).
        $aEsperaDesde = $this->aEsperaDoClienteDesde($s, $c, $estadoDesde);
        if ($aEsperaDesde && $aEsperaDesde->lte(now()->subHours(self::HORAS_A_ESPERA_DO_CLIENTE))) {
            return ['nivel' => 'atencao', 'motivo' => 'cliente_sem_resposta'];
        }
        if ($s->status === ServiceStatus::MATCHING && $c['aceitaram'] > 0) {
            return ['nivel' => 'info', 'motivo' => 'cliente_a_escolher'];
        }

        return null;
    }

    /** Desde quando a bola está do lado do cliente, ou null se não está. */
    private function aEsperaDoClienteDesde(Service $s, array $c, ?CarbonInterface $estadoDesde): ?CarbonInterface
    {
        if (in_array($s->status, [ServiceStatus::AWAITING_PAYMENT, ServiceStatus::PENDING_3DS], true)) {
            return $estadoDesde;
        }
        // Em seleção com alguém a dizer que sim: o cliente escolhe desde o
        // primeiro sim, não desde que o pedido entrou em seleção.
        if ($s->status === ServiceStatus::MATCHING && $c['aceitaram'] > 0) {
            return $s->candidates_ready_at ?? $estadoDesde;
        }

        return null;
    }

    // ------------------------------------------------------------- em curso

    private function emCurso(bool $testes): array
    {
        $servicos = Service::query()
            ->where('is_test', $testes)
            ->whereIn('status', self::EM_CURSO)
            ->where(fn ($q) => $q
                // Os agendados só quando estão perto: hoje, amanhã ou atrasados.
                ->where('status', '!=', ServiceStatus::SCHEDULED->value)
                ->orWhereHas('schedule', fn ($sc) => $sc->where('scheduled_day', '<=', now('Europe/Lisbon')->addDay()->toDateString())))
            ->with([
                'schedule', 'serviceType', 'customerUser', 'vendor.user',
                // Só os extras por aprovar: um orçamento enviado ao cliente
                // sem resposta é um pedido parado do lado dele.
                'extras' => fn ($q) => $q->where('status', 'pending'),
            ])
            ->orderBy('created_at')
            ->limit(200)
            ->get();

        $desde = $this->estadosDesde($servicos);

        return $servicos
            ->map(fn (Service $s) => [
                ...$this->base($s),
                'tecnico' => $s->vendor?->user?->name,
                'a_caminho_em' => optional($s->on_the_way_at)->toIso8601String(),
                'chegou_em' => optional($s->arrived_at)->toIso8601String(),
                ...$this->estadoDesde($desde[$s->id]),
                'alerta' => $this->alertaEmCurso($s, $desde[$s->id]['desde']),
            ])
            ->all();
    }

    private function alertaEmCurso(Service $s, ?CarbonInterface $estadoDesde): ?array
    {
        if ($s->status === ServiceStatus::CLOSED_PENDING_PAYMENT) {
            return ['nivel' => 'critico', 'motivo' => 'pagamento_por_capturar'];
        }
        if ($this->tecnicoAtrasado($s, $estadoDesde)) {
            return ['nivel' => 'critico', 'motivo' => 'tecnico_nao_chegou'];
        }
        if ($s->status === ServiceStatus::FINISHED && $s->updated_at && $s->updated_at->lt(now()->subDay())) {
            return ['nivel' => 'atencao', 'motivo' => 'cliente_nao_confirmou'];
        }
        // Um extra (tempo ou peças) à espera da aprovação do cliente há mais
        // de um dia: o técnico não pode avançar nem fechar.
        $extraMaisAntigo = $s->extras->min('created_at');
        if ($extraMaisAntigo && Carbon::parse($extraMaisAntigo)->lte(now()->subHours(self::HORAS_A_ESPERA_DO_CLIENTE))) {
            return ['nivel' => 'atencao', 'motivo' => 'cliente_sem_resposta'];
        }
        // Em execução há horas demais. O `services:notify-stuck-in-progress`
        // já avisa o técnico às 3 e às 24 h; isto põe-no à frente de quem
        // está no backoffice antes de virar caso de suporte.
        if ($s->status === ServiceStatus::ARRIVED) {
            $comecou = $s->arrived_at ?? $estadoDesde;
            if ($comecou && $comecou->lte(now()->subHours(self::HORAS_EM_EXECUCAO))) {
                return ['nivel' => 'atencao', 'motivo' => 'em_execucao_ha_muito'];
            }
        }

        return null;
    }

    /**
     * A hora de começar passou há mais de MINUTOS_DE_ATRASO_SEM_CHEGAR e o
     * técnico não chegou (com ou sem "a caminho" — para o cliente à porta,
     * ainda ninguém apareceu).
     *
     * Num agendado a hora é a marcada; num imediato é a hora em que ficou
     * aceite mais a viagem (MINUTOS_DE_VIAGEM_IMEDIATO).
     */
    private function tecnicoAtrasado(Service $s, ?CarbonInterface $estadoDesde): bool
    {
        if (! in_array($s->status, [ServiceStatus::SCHEDULED, ServiceStatus::ACCEPTED], true) || $s->arrived_at) {
            return false;
        }

        $inicio = $s->schedule
            ? Service::instanteDe($s->schedule->scheduled_day, $s->schedule->scheduled_time_start)
            : $estadoDesde?->copy()->addMinutes(self::MINUTOS_DE_VIAGEM_IMEDIATO);

        return $inicio !== null && $inicio->copy()->addMinutes(self::MINUTOS_DE_ATRASO_SEM_CHEGAR)->isPast();
    }

    // ---------------------------------------------------------------- oferta

    private function oferta(bool $testes): array
    {
        $online = OfertaAgora::online($testes);

        $porArea = [];
        foreach ($online as $o) {
            foreach ($o['areas'] as $area) {
                $porArea[$area] ??= ['online' => 0, 'prontos' => 0];
                $porArea[$area]['online']++;
                if ($o['pronto']) {
                    $porArea[$area]['prontos']++;
                }
            }
        }
        $nomes = OperationArea::query()->whereIn('id', array_keys($porArea))->get()
            ->mapWithKeys(fn (OperationArea $a) => [$a->id => $a->getTranslation('name', 'pt-pt', false) ?: $a->getTranslation('name', 'pt', false) ?: (string) $a->id]);

        return [
            'janela_minutos' => OfertaAgora::janelaMinutos(),
            'online' => $online->count(),
            'podem_aceitar' => $online->where('pode_aceitar', true)->count(),
            'prontos' => $online->where('pronto', true)->count(),
            'por_area' => collect($porArea)
                ->map(fn ($n, $id) => ['area_id' => $id, 'area' => $nomes[$id] ?? (string) $id, ...$n])
                ->sortByDesc('online')
                ->values()
                ->all(),
            'por_cidade' => $this->ofertaPorCidade($online),
        ];
    }

    /**
     * A mesma contagem, pelas cidades que cada técnico escolheu (a área de
     * trabalho dele no matching). Um técnico com três cidades conta nas três;
     * quem não escolheu nenhuma fica na linha `cidade: null` — no matching
     * não tem restrição de cidade, só o raio máximo.
     */
    private function ofertaPorCidade(Collection $online): array
    {
        $porCidade = [];
        foreach ($online as $o) {
            foreach ($o['cidades'] ?: [null] as $cidade) {
                $chave = $cidade ?? '';
                $porCidade[$chave] ??= ['cidade' => $cidade, 'online' => 0, 'podem_aceitar' => 0, 'prontos' => 0];
                $porCidade[$chave]['online']++;
                $porCidade[$chave]['podem_aceitar'] += $o['pode_aceitar'] ? 1 : 0;
                $porCidade[$chave]['prontos'] += $o['pronto'] ? 1 : 0;
            }
        }

        return collect($porCidade)
            ->sort(fn ($a, $b) => [$b['online'], $a['cidade'] === null, $a['cidade']] <=> [$a['online'], $b['cidade'] === null, $b['cidade']])
            ->values()
            ->all();
    }

    // -------------------------------------------------------------- liquidez

    private function liquidez(int $dias, bool $testes): array
    {
        $pedidos = $this->pedidosDesde($dias, $testes);

        $porDesfecho = array_fill_keys([
            DesfechoDoPedido::SERVIDO, DesfechoDoPedido::SEM_OFERTA, DesfechoDoPedido::SEM_RESPOSTA,
            DesfechoDoPedido::SEM_ESCOLHA, DesfechoDoPedido::PAGAMENTO_FALHOU, DesfechoDoPedido::CANCELADO,
            DesfechoDoPedido::OUTRO, DesfechoDoPedido::EM_ABERTO,
        ], 0);
        $porModo = [];
        $tempos = [];
        $comSim = 0;

        foreach ($pedidos as $p) {
            $porDesfecho[$p['desfecho']]++;
            $porModo[$p['modo']] ??= ['pedidos' => 0, 'servidos' => 0];
            $porModo[$p['modo']]['pedidos']++;
            if ($p['desfecho'] === DesfechoDoPedido::SERVIDO) {
                $porModo[$p['modo']]['servidos']++;
            }
            if ($p['desfecho'] !== DesfechoDoPedido::EM_ABERTO && $p['algum_sim']) {
                $comSim++;
            }
            if ($p['segundos_ate_primeiro_sim'] !== null) {
                $tempos[] = $p['segundos_ate_primeiro_sim'];
            }
        }

        $terminados = count($pedidos) - $porDesfecho[DesfechoDoPedido::EM_ABERTO];

        return [
            'dias' => $dias,
            'pedidos' => count($pedidos),
            'terminados' => $terminados,
            'com_sim' => $comSim,
            'servidos' => $porDesfecho[DesfechoDoPedido::SERVIDO],
            // Das contas fecham só os pedidos que já acabaram: um aberto ainda
            // pode vir a ser servido.
            'taxa_com_sim' => $terminados > 0 ? round($comSim / $terminados * 100, 1) : null,
            'taxa_servidos' => $terminados > 0 ? round($porDesfecho[DesfechoDoPedido::SERVIDO] / $terminados * 100, 1) : null,
            'mediana_segundos_ate_primeiro_sim' => $this->mediana($tempos),
            'por_desfecho' => $porDesfecho,
            'por_modo' => $porModo,
        ];
    }

    /**
     * O mês do calendário em curso, em Lisboa: quantos pedidos entraram e
     * quantos foram servidos. Servido é o mesmo da `taxa_servidos`
     * (DesfechoDoPedido); a taxa é que é outra conta — sobre todos os
     * criados, e de 0 a 1: um pedido ainda aberto conta como não servido até
     * o ser. Contada na base de dados, sem carregar os pedidos.
     */
    private function liquidezDoMes(bool $testes): array
    {
        $inicio = now(self::FUSO)->startOfMonth();

        $linhas = Service::query()
            ->where('is_test', $testes)
            ->where('created_at', '>=', $inicio->copy()->utc())
            ->selectRaw('status, vendor_id IS NOT NULL AS tem_tecnico, COUNT(*) AS total')
            ->groupBy('status', 'tem_tecnico')
            ->toBase()
            ->get();

        $criados = 0;
        $servidos = 0;
        foreach ($linhas as $linha) {
            $criados += (int) $linha->total;
            $estado = ServiceStatus::tryFrom((string) $linha->status);
            if ($estado && DesfechoDoPedido::servido($estado, (bool) $linha->tem_tecnico)) {
                $servidos += (int) $linha->total;
            }
        }

        return [
            'desde' => $inicio->toIso8601String(),
            'criados' => $criados,
            'servidos' => $servidos,
            'taxa' => $criados > 0 ? round($servidos / $criados, 3) : null,
        ];
    }

    /** Os pedidos dos últimos `$dias`, cada um já com o desfecho. */
    private function pedidosDesde(int $dias, bool $testes): Collection
    {
        return Service::query()
            ->where('is_test', $testes)
            ->where('created_at', '>=', now()->subDays($dias))
            ->with(['candidates', 'schedule', 'serviceType'])
            ->orderByDesc('created_at')
            ->get()
            ->map(function (Service $s) {
                $sims = $s->candidates->filter(fn ($c) => in_array($this->estadoDe($c), self::DISSERAM_SIM, true));
                $primeiro = $sims->pluck('responded_at')->filter()->min();
                $inicio = $s->matchingStartedAt();

                return [
                    'servico' => $s,
                    'modo' => $this->modo($s),
                    'convidados' => $s->candidates->count(),
                    'algum_sim' => $sims->isNotEmpty(),
                    'segundos_ate_primeiro_sim' => $primeiro && $inicio
                        ? max(0, (int) $inicio->diffInSeconds(Carbon::parse($primeiro), false))
                        : null,
                    'desfecho' => DesfechoDoPedido::de($s->status, $s->vendor_id !== null, $s->candidates->count(), $sims->isNotEmpty()),
                ];
            });
    }

    private function perdidos(bool $testes): array
    {
        $perdas = [
            DesfechoDoPedido::SEM_OFERTA, DesfechoDoPedido::SEM_RESPOSTA,
            DesfechoDoPedido::SEM_ESCOLHA, DesfechoDoPedido::PAGAMENTO_FALHOU,
        ];

        return $this->pedidosDesde(30, $testes)
            ->filter(fn ($p) => in_array($p['desfecho'], $perdas, true))
            ->take(15)
            ->map(fn ($p) => [
                ...$this->base($p['servico']),
                'convidados' => $p['convidados'],
                'desfecho' => $p['desfecho'],
                'viveu_segundos' => $p['servico']->created_at && $p['servico']->updated_at
                    ? (int) $p['servico']->created_at->diffInSeconds($p['servico']->updated_at)
                    : null,
            ])
            ->values()
            ->all();
    }

    // ---------------------------------------------------------------- comum

    /**
     * Desde quando cada pedido está no estado em que está.
     *
     * Do histórico (`service_events`, existe desde 08/10): o último passo que
     * o levou ao estado atual. Sem ele — pedidos anteriores ao histórico, ou
     * um estado mudado por um caminho que não passa pelo modelo — fica o
     * `updated_at`, que é só uma aproximação: mexe também com outras
     * alterações (fotos, notas, o "a caminho"), por isso quer dizer "está
     * assim há PELO MENOS isto". A `fonte` diz qual das duas foi.
     *
     * Uma consulta para a lista inteira, e não uma por pedido.
     *
     * @param  Collection<int, Service>  $servicos
     * @return array<int, array{desde: ?CarbonInterface, fonte: string}>
     */
    private function estadosDesde(Collection $servicos): array
    {
        if ($servicos->isEmpty()) {
            return [];
        }

        $passos = ServiceEvent::query()
            ->whereIn('service_id', $servicos->pluck('id')->all())
            ->whereIn('tipo', [RegistoDeEventos::CRIADO, RegistoDeEventos::ESTADO])
            ->whereNotNull('estado_para')
            ->selectRaw('service_id, estado_para, MAX(ocorreu_em) AS desde')
            ->groupBy('service_id', 'estado_para')
            ->toBase()
            ->get()
            ->groupBy('service_id');

        $resultado = [];
        foreach ($servicos as $s) {
            $estado = $s->status?->value ?? (string) $s->status;
            $passo = $passos->get($s->id)?->firstWhere('estado_para', $estado);

            $resultado[$s->id] = $passo
                ? ['desde' => Carbon::parse($passo->desde), 'fonte' => 'historico']
                : ['desde' => $s->updated_at, 'fonte' => 'updated_at'];
        }

        return $resultado;
    }

    /** @param  array{desde: ?CarbonInterface, fonte: string}  $desde */
    private function estadoDesde(array $desde): array
    {
        return [
            'estado_desde' => $desde['desde']?->toIso8601String(),
            'estado_desde_fonte' => $desde['fonte'],
        ];
    }

    private function base(Service $s): array
    {
        $morada = is_array($s->address) ? $s->address : [];

        return [
            'id' => (string) $s->id,
            'estado' => $s->status?->value ?? (string) $s->status,
            'modo' => $this->modo($s),
            'tipo' => $s->serviceType?->name,
            'cliente' => $s->customerUser?->name,
            'cidade' => $morada['city'] ?? $morada['locality'] ?? null,
            'criado_em' => optional($s->created_at)->toIso8601String(),
            'marcado_para' => $s->schedule
                ? trim($s->schedule->scheduled_day.' '.$s->schedule->scheduled_time_start)
                : null,
        ];
    }

    private function modo(Service $s): string
    {
        if ($s->is_custom) {
            return 'personalizado';
        }

        return $this->matching->isScheduled($s) ? 'agendado' : 'imediato';
    }

    private function contagem(Collection $candidatos): array
    {
        $estados = $candidatos->map(fn ($c) => $this->estadoDe($c));

        return [
            'convidados' => $estados->count(),
            'por_responder' => $estados->filter(fn ($e) => $e === CandidateStatus::NOTIFIED)->count(),
            'aceitaram' => $estados->filter(fn ($e) => in_array($e, self::DISSERAM_SIM, true))->count(),
            'recusaram' => $estados->filter(fn ($e) => $e === CandidateStatus::DECLINED)->count(),
            'expiraram' => $estados->filter(fn ($e) => $e === CandidateStatus::EXPIRED)->count(),
        ];
    }

    private function estadoDe($candidato): ?CandidateStatus
    {
        return $candidato->status instanceof CandidateStatus
            ? $candidato->status
            : CandidateStatus::tryFrom((string) $candidato->status);
    }

    /** @param  list<int>  $valores */
    private function mediana(array $valores): ?int
    {
        if ($valores === []) {
            return null;
        }
        sort($valores);
        $n = count($valores);
        $meio = intdiv($n, 2);

        return $n % 2 ? $valores[$meio] : (int) round(($valores[$meio - 1] + $valores[$meio]) / 2);
    }
}
