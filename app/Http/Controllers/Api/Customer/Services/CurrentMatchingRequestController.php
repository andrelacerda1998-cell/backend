<?php

namespace App\Http\Controllers\Api\Customer\Services;

use App\Enums\Services\CandidateStatus;
use App\Enums\Services\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Service;
use App\Models\Vendor;
use App\Services\Matching\MatchingService;
use App\Trait\Services\CalculateServicePriceForCustomer;

/**
 * O pedido que está à espera do cliente, para a Home poder mostrá-lo.
 *
 * Sem isto, um pedido em seleção só era alcançável pela notificação: quem a
 * descartasse, a fechasse sem tocar, ou tivesse as notificações recusadas,
 * ficava com propostas à espera e sem caminho nenhum de volta — e com um
 * relógio a correr. Nem o cartão da Home (Finished/Accepted/Arrived) nem o
 * separador Serviços (agendamentos e histórico) cobriam estes estados.
 *
 * Endpoint próprio e não mais um estado no `CheckHasAnyServiceOpenController`:
 * aquele alimenta o cartão do serviço a decorrer, com técnico, morada e
 * contagem — coisas que um pedido em seleção ainda não tem.
 */
class CurrentMatchingRequestController extends Controller
{
    use CalculateServicePriceForCustomer;

    public function __construct(private readonly MatchingService $matching) {}

    public function __invoke()
    {
        $abertos = auth()->user()->services()
            ->whereIn('status', [
                ServiceStatus::PENDING_REVIEW,
                ServiceStatus::MATCHING,
                ServiceStatus::AWAITING_PAYMENT,
            ])
            ->latest('id')
            ->get();

        if ($abertos->isEmpty()) {
            return new ApiSuccessResponse(['request' => null, 'requests' => []]);
        }

        // Pode haver MAIS DO QUE UM aberto ao mesmo tempo, e isso não era
        // visível: o `startCustom` recusa um segundo personalizado enquanto
        // houver um em análise, mas o `start()` de catálogo não olha para o
        // PendingReview. Bastava o cliente pedir um serviço normal para o
        // personalizado sumir daqui — invisível no ecrã e a bloquear na mesma.
        $service = $abertos->first();

        $language = auth()->user()->language ?? 'pt-pt';

        return new ApiSuccessResponse([
            // `request` fica como estava, para as versões da app já
            // instaladas. `requests` é o que as novas leem.
            'requests' => $abertos->map(fn (Service $s) => $this->payloadDoPedido($s, $language))->values(),
            'request' => $this->payloadDoPedido($service, $language),
        ]);
    }

    /**
     * O que a app precisa de saber sobre UM pedido aberto.
     *
     * Extraído para o mesmo desenho servir o `request` (o mais recente,
     * como sempre) e cada elemento de `requests`. Duas construções do mesmo
     * payload divergiam à primeira alteração, e a app passava a ver coisas
     * diferentes conforme o sítio onde olhasse.
     */
    private function payloadDoPedido(Service $service, string $language): array
    {
        return [
            'id' => $service->id,
            'status' => $service->status,
            'is_custom' => (bool) $service->is_custom,
            // O que o cliente pediu, para o cartão dizer de que serviço se
            // trata. Num personalizado é a descrição que ele próprio escreveu.
            'title' => $service->is_custom
                ? $service->custom_description
                : $service->serviceType?->getTranslation('name', $language),
            // Quantos já se disponibilizaram. Zero é informação legítima: em
            // análise, ou ainda à espera da primeira resposta.
            'candidates_ready' => $this->countReady($service),
            // Quando: o dia e a hora pedidos, ou null se for para agora. A app
            // formata — o dia da semana e o "hoje/amanha" dependem do fuso e do
            // idioma de quem esta a olhar, nao do servidor.
            'schedule' => $service->scheduleIntent(),
            // Num pedido imediato nao ha hora escolhida; o que responde a
            // "quando e que eu pedi isto?" e a hora a que foi feito.
            'requested_at' => $service->created_at?->toIso8601String(),
            // Ate quando pode escolher e pagar. null enquanto nao ha ninguem
            // para escolher — nao ha relogio do cliente antes de haver decisao.
            // Sai do MESMO metodo que o `matching:advance` usa para matar o
            // pedido: se fossem duas contas, a contagem no ecra chegava a zero
            // com o pedido vivo, ou o pedido morria com o relogio a andar.
            'expires_at' => $this->matching->customerDeadline($service)?->toIso8601String(),
            // O relogio do telemovel pode estar errado. A app conta a partir da
            // diferenca entre estes dois, e nao do seu proprio Date.now().
            'server_time' => now()->toIso8601String(),
            // Escolheu e nao pagou. Sem isto o separador dizia-lhe que ainda
            // andavamos "a procura de profissionais" — quando ja tinha um
            // escolhido a espera dele. O preco vai congelado (o mesmo que viu
            // ao escolher) para o checkout se poder retomar sem recalcular.
            'selected' => $this->selectedQuote($service),
        ];
    }

    private function selectedQuote(Service $service): ?array
    {
        if ($service->status !== ServiceStatus::AWAITING_PAYMENT) {
            return null;
        }

        $selected = $service->candidates()
            ->with('vendor.user')
            ->where('status', CandidateStatus::SELECTED)
            ->first();

        if (! $selected) {
            return null;
        }

        return [
            'amount' => (int) $selected->quoted_amount,
            'travel_amount' => $this->travelAmountForCustomer(
                (float) $selected->quoted_distance,
                $this->matching->isScheduled($service),
            ),
            'distance' => (float) $selected->quoted_distance,
            // O checkout desenha-se a partir do tecnico e recusa-se a avancar
            // sem ele. Quem retoma o pagamento pelo separador nao passou pelo
            // ecra de escolha, por isso o tecnico tem de vir por aqui.
            'vendor' => [
                'id' => $selected->vendor_id,
                'name' => $selected->vendor?->user?->name,
                // null = sem avaliacoes. Nao se inventa nota.
                // Mesmo corte do MatchingController: abaixo do mínimo, sem número.
                'rating' => ($selected->rating_average === null || (int) $selected->rating_count < Vendor::MIN_AVALIACOES_PARA_MOSTRAR)
                    ? null
                    : round($selected->rating_average / 100, 2),
                'rating_count' => (int) $selected->rating_count,
            ],
        ];
    }

    private function countReady(Service $service): int
    {
        if ($service->status !== ServiceStatus::MATCHING) {
            return 0;
        }

        return $this->matching->selectableFor($service)->count();
    }
}
