<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Services\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Service;
use App\Models\ServiceExtra;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use RwInteractive\PayshopSdk\Enums\Payment\Status;
use RwInteractive\PayshopSdk\Models\PaymentOrder;

/**
 * Reembolso e libertação de cativo, a partir do backoffice -- e a consulta de
 * a que pedido pertence cada pagamento (index/show).
 *
 * IMPORTANTE — porque é que isto NÃO fala diretamente com a API do Paylands:
 * o dinheiro da app não vive só no Payshop. Um serviço pago já creditou a
 * carteira do técnico e a comissão do sistema; devolver o dinheiro ao cliente
 * por fora deixava esse crédito de pé e o serviço marcado como pago. Passa-se
 * pelo SDK (PaymentOrder::refund/cancel) para o estado local ficar coerente,
 * e recusa-se a operação quando o serviço associado ainda está vivo — nesse
 * caso o caminho certo é cancelar o serviço, que já dispara o ServiceObserver
 * com o tratamento completo (reembolso + crédito + notificações).
 */
class PaymentOrderController extends Controller
{
    /** Estados em que o dinheiro já foi capturado — dá para reembolsar. */
    private const CAPTURED = [
        Status::SUCCESS,
        Status::PARTIALLY_CONFIRMED,
        Status::PARTIALLY_REFUNDED,
    ];

    /**
     * Único estado com cativo ativo no Payshop. Os estados pré-confirmação
     * (CREATED, PENDING_CARD, PENDING_3DS_RESPONSE, ...) não têm transação de
     * confirmação: chamar cancel() neles devolve 400 "no confirmation
     * transaction". Esses expiram sozinhos — ver ServiceObserver.
     */
    private const HOLD = [Status::PENDING_CONFIRMATION];

    /** Quantos pagamentos se podem pedir de uma vez na lista. */
    private const MAXIMO_POR_PEDIDO = 200;

    /**
     * GET /v1/admin/payment-orders?uuids=a,b,c — a que pedido pertence cada
     * pagamento.
     *
     * O backoffice vê os pagamentos do lado do Payshop, onde o que aparece é o
     * uuid da ordem, e não tinha como chegar daí ao pedido: para abrir o
     * pedido de um pagamento, ou procurar pelo id de um pagamento, alguém ia à
     * base de dados. Uma chamada por página, até 200 de cada vez — e não uma
     * por linha.
     *
     * Devolve os encontrados em `items`, pela ordem pedida, e os uuids que não
     * existem em `nao_encontrados`.
     */
    public function index(Request $request): ApiSuccessResponse|ApiErrorResponse
    {
        $uuids = collect(explode(',', $request->string('uuids')->toString()))
            ->map(fn (string $uuid) => trim($uuid))
            ->filter()
            ->unique()
            ->values();

        if ($uuids->isEmpty()) {
            return new ApiErrorResponse(null, 'Indica os pagamentos em uuids, separados por vírgulas.', 422);
        }

        if ($uuids->count() > self::MAXIMO_POR_PEDIDO) {
            return new ApiErrorResponse(null, 'No máximo '.self::MAXIMO_POR_PEDIDO.' pagamentos de cada vez.', 422);
        }

        // keyBy fica com o último: se um uuid se repetisse, ganhava a ordem mais recente.
        $ordens = PaymentOrder::query()->whereIn('uuid', $uuids->all())->orderBy('id')->get()->keyBy('uuid');
        $ligacoes = $this->ligacoes($ordens);

        return ApiSuccessResponse::make([
            'items' => $uuids
                ->filter(fn (string $uuid) => $ordens->has($uuid))
                ->map(fn (string $uuid) => $this->present($ordens->get($uuid), $ligacoes))
                ->values()
                ->all(),
            'nao_encontrados' => $uuids->reject(fn (string $uuid) => $ordens->has($uuid))->values()->all(),
        ]);
    }

    /** GET /v1/admin/payment-orders/{uuid} — um pagamento, com o pedido a que pertence. */
    public function show(string $uuid): ApiSuccessResponse|ApiErrorResponse
    {
        $order = PaymentOrder::query()->where('uuid', $uuid)->orderByDesc('id')->first();

        if (! $order) {
            return new ApiErrorResponse(null, 'Pagamento não encontrado.', 404);
        }

        return ApiSuccessResponse::make($this->present($order));
    }

    /**
     * POST /v1/admin/payment-orders/{uuid}/refund
     *
     * Body opcional: { "amount": 1500 } (cêntimos) para reembolso parcial.
     * Sem `amount`, o SDK devolve a totalidade.
     */
    public function refund(Request $request, string $uuid): ApiSuccessResponse|ApiErrorResponse
    {
        $order = PaymentOrder::where('uuid', $uuid)->first();
        if (! $order) {
            return new ApiErrorResponse(null, 'Pagamento não encontrado.', 404);
        }

        // Sincroniza antes de decidir: o estado local pode estar atrasado em
        // relação ao Payshop (webhook perdido, hold já expirado). Best-effort.
        try {
            $order->updateData();
        } catch (\Throwable $e) {
            report($e);
        }

        if (! in_array($order->status, self::CAPTURED, true)) {
            return new ApiErrorResponse(
                null,
                "Este pagamento está em {$order->status->value} — só se reembolsa dinheiro já cobrado. Para libertar um cativo, usa cancelar.",
                409,
            );
        }

        if ($blocker = $this->openService($order)) {
            return new ApiErrorResponse(
                null,
                "O serviço #{$blocker->id} ainda está em {$blocker->status->value}. Cancela o serviço em Operações — o cancelamento trata do reembolso, do crédito ao cliente e das notificações. Reembolsar por aqui deixava o técnico creditado.",
                409,
            );
        }

        $amount = $request->integer('amount') ?: null;
        if ($amount !== null && ($amount <= 0 || $amount > (int) $order->amount)) {
            return new ApiErrorResponse(null, 'Valor a reembolsar fora do valor do pagamento.', 422);
        }

        try {
            $order->refund($amount);
        } catch (\Throwable $e) {
            report($e);

            return new ApiErrorResponse(null, 'O Payshop recusou o reembolso: '.$e->getMessage(), 502);
        }

        return ApiSuccessResponse::make($this->present($order));
    }

    /**
     * POST /v1/admin/payment-orders/{uuid}/cancel — liberta o valor cativo.
     *
     * Não é o mesmo que reembolsar: aqui o dinheiro nunca chegou a sair da
     * conta do cliente, só estava reservado.
     */
    public function cancel(string $uuid): ApiSuccessResponse|ApiErrorResponse
    {
        $order = PaymentOrder::where('uuid', $uuid)->first();
        if (! $order) {
            return new ApiErrorResponse(null, 'Pagamento não encontrado.', 404);
        }

        try {
            $order->updateData();
        } catch (\Throwable $e) {
            report($e);
        }

        if (! in_array($order->status, self::HOLD, true)) {
            return new ApiErrorResponse(
                null,
                "Este pagamento está em {$order->status->value} — não há cativo ativo para libertar.",
                409,
            );
        }

        if ($blocker = $this->openService($order)) {
            return new ApiErrorResponse(
                null,
                "O serviço #{$blocker->id} ainda está em {$blocker->status->value}. Cancela o serviço em Operações — o cancelamento liberta o cativo por si.",
                409,
            );
        }

        try {
            $order->cancel();
        } catch (\Throwable $e) {
            report($e);

            return new ApiErrorResponse(null, 'O Payshop recusou a libertação: '.$e->getMessage(), 502);
        }

        return ApiSuccessResponse::make($this->present($order));
    }

    /**
     * Serviço associado que ainda não é terminal — bloqueia a operação manual.
     * Os terminais já foram tratados (ou nunca chegaram a consumir dinheiro).
     */
    private function openService(PaymentOrder $order): ?Service
    {
        // A mesma lista que estava aqui escrita à mão, agora num sítio só
        // (ver ServiceStatus::finais()).
        return Service::where('payment_order_id', $order->id)
            ->whereNotIn('status', ServiceStatus::finais())
            ->first();
    }

    /**
     * Os pedidos ligados a estes pagamentos, em duas consultas para a página
     * toda.
     *
     * Um pagamento é de um pedido (`services.payment_order_id`) ou de um EXTRA
     * de um pedido — tempo ou peças que o técnico pediu durante o serviço têm
     * a sua própria ordem no Payshop (`service_extras.payment_order_id`). Nos
     * dois casos o que o backoffice quer abrir é o pedido.
     *
     * @param  Collection<array-key, PaymentOrder>  $ordens
     * @return array{servicos: Collection<int, Service>, extras: Collection<int, ServiceExtra>}
     */
    private function ligacoes(Collection $ordens): array
    {
        $ids = $ordens->pluck('id')->all();

        return [
            // Do mais antigo para o mais recente: o keyBy fica com o último.
            'servicos' => Service::query()
                ->whereIn('payment_order_id', $ids)
                ->orderBy('id')
                ->get(['id', 'customer_id', 'payment_order_id'])
                ->keyBy('payment_order_id'),
            'extras' => ServiceExtra::query()
                ->whereIn('payment_order_id', $ids)
                ->with('service:id,customer_id')
                ->orderBy('id')
                ->get(['id', 'service_id', 'payment_order_id'])
                ->keyBy('payment_order_id'),
        ];
    }

    /**
     * @param  array{servicos: Collection<int, Service>, extras: Collection<int, ServiceExtra>}|null  $ligacoes
     */
    private function present(PaymentOrder $order, ?array $ligacoes = null): array
    {
        $ligacoes ??= $this->ligacoes(collect([$order]));
        $servico = $ligacoes['servicos']->get($order->id);
        $extra = $servico ? null : $ligacoes['extras']->get($order->id);

        return [
            'uuid' => $order->uuid,
            // O pedido a que o pagamento pertence -- também quando é o
            // pagamento de um extra (e então vem o extra em `service_extra_id`).
            'service_id' => $servico?->id ?? $extra?->service_id,
            'service_extra_id' => $extra?->id,
            // Quem pagou: o cliente do pedido, ou o dono da ordem quando o
            // pagamento já não está ligado a pedido nenhum.
            'customer_id' => $servico?->customer_id ?? $extra?->service?->customer_id ?? $order->user_id,
            'status' => $this->estadoDe($order),
            // Colunas em cêntimos, como o resto do sistema.
            'amount' => (int) $order->amount,
            'refunded' => (int) $order->refunded,
        ];
    }

    /**
     * O estado tal como está gravado. Lido em bruto e não pelo enum do SDK: um
     * estado novo do Payshop que o enum ainda não conheça não pode deitar
     * abaixo uma lista de 200 pagamentos.
     */
    private function estadoDe(PaymentOrder $order): ?string
    {
        $estado = $order->getAttributes()['status'] ?? null;

        return $estado instanceof \BackedEnum ? (string) $estado->value : $estado;
    }
}
