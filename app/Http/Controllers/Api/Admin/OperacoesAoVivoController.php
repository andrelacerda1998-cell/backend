<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Services\CandidateStatus;
use App\Enums\Services\ServiceStatus;
use App\Enums\Vendors\StatusVendor;
use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\GeneralSettings\OperationArea;
use App\Models\Service;
use App\Models\Vendor;
use App\Services\Matching\MatchingService;
use App\Services\Operacoes\DesfechoDoPedido;
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
 * As contas de teste ficam de fora, salvo `?incluir_testes=1`.
 */
class OperacoesAoVivoController extends Controller
{
    /** Quem disse que sim, mesmo que depois tenha sido escolhido ou perdido. */
    private const DISSERAM_SIM = [CandidateStatus::ACCEPTED, CandidateStatus::SELECTED, CandidateStatus::LOST];

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
            'a_procura' => $this->aProcura($testes),
            'em_curso' => $this->emCurso($testes),
            'oferta' => $this->oferta($testes),
            'liquidez' => [
                '7d' => $this->liquidez(7, $testes),
                '30d' => $this->liquidez(30, $testes),
            ],
            'perdidos' => $this->perdidos($testes),
        ]);
    }

    // ------------------------------------------------------------ à procura

    private function aProcura(bool $testes): array
    {
        return Service::query()
            ->where('is_test', $testes)
            ->whereIn('status', DesfechoDoPedido::ABERTOS)
            ->with(['candidates', 'schedule', 'serviceType', 'customerUser'])
            ->orderBy('created_at')
            ->limit(200)
            ->get()
            ->map(fn (Service $s) => $this->pedidoAberto($s))
            ->all();
    }

    private function pedidoAberto(Service $s): array
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
            'alerta' => $this->alertaAberto($s, $contagem, $restante, $janela, $inicio),
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
    private function alertaAberto(Service $s, array $c, ?int $restante, ?int $janela, ?CarbonInterface $inicio): ?array
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
        if ($s->status === ServiceStatus::MATCHING && $c['aceitaram'] > 0) {
            return ['nivel' => 'info', 'motivo' => 'cliente_a_escolher'];
        }

        return null;
    }

    // ------------------------------------------------------------- em curso

    private function emCurso(bool $testes): array
    {
        return Service::query()
            ->where('is_test', $testes)
            ->whereIn('status', self::EM_CURSO)
            ->where(fn ($q) => $q
                // Os agendados só quando estão perto: hoje, amanhã ou atrasados.
                ->where('status', '!=', ServiceStatus::SCHEDULED->value)
                ->orWhereHas('schedule', fn ($sc) => $sc->where('scheduled_day', '<=', now('Europe/Lisbon')->addDay()->toDateString())))
            ->with(['schedule', 'serviceType', 'customerUser', 'vendor.user'])
            ->orderBy('created_at')
            ->limit(200)
            ->get()
            ->map(fn (Service $s) => [
                ...$this->base($s),
                'tecnico' => $s->vendor?->user?->name,
                'a_caminho_em' => optional($s->on_the_way_at)->toIso8601String(),
                'chegou_em' => optional($s->arrived_at)->toIso8601String(),
                'alerta' => $this->alertaEmCurso($s),
            ])
            ->all();
    }

    private function alertaEmCurso(Service $s): ?array
    {
        if ($s->status === ServiceStatus::CLOSED_PENDING_PAYMENT) {
            return ['nivel' => 'critico', 'motivo' => 'pagamento_por_capturar'];
        }
        if ($s->status === ServiceStatus::FINISHED && $s->updated_at && $s->updated_at->lt(now()->subDay())) {
            return ['nivel' => 'atencao', 'motivo' => 'cliente_nao_confirmou'];
        }

        return null;
    }

    // ---------------------------------------------------------------- oferta

    private function oferta(bool $testes): array
    {
        $janela = (int) config('services.request.location_update_threshold', 60);
        $limite = now()->subMinutes($janela);

        $online = Vendor::query()
            ->where('status', StatusVendor::ONLINE)
            ->whereHas('user', fn ($q) => $q->where('is_test', $testes))
            ->with(['currentLocation', 'servicesTypes', 'user'])
            ->get();

        $pronto = fn (Vendor $v) => $v->can_accept_service
            && $v->currentLocation?->updated_at?->gte($limite);

        $porArea = [];
        foreach ($online as $v) {
            foreach ($v->servicesTypes->pluck('operation_area_id')->filter()->unique() as $area) {
                $porArea[$area] ??= ['online' => 0, 'prontos' => 0];
                $porArea[$area]['online']++;
                if ($pronto($v)) {
                    $porArea[$area]['prontos']++;
                }
            }
        }
        $nomes = OperationArea::query()->whereIn('id', array_keys($porArea))->get()
            ->mapWithKeys(fn (OperationArea $a) => [$a->id => $a->getTranslation('name', 'pt-pt', false) ?: $a->getTranslation('name', 'pt', false) ?: (string) $a->id]);

        return [
            'janela_minutos' => $janela,
            'online' => $online->count(),
            'podem_aceitar' => $online->filter(fn (Vendor $v) => $v->can_accept_service)->count(),
            'prontos' => $online->filter($pronto)->count(),
            'por_area' => collect($porArea)
                ->map(fn ($n, $id) => ['area_id' => $id, 'area' => $nomes[$id] ?? (string) $id, ...$n])
                ->sortByDesc('online')
                ->values()
                ->all(),
        ];
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
