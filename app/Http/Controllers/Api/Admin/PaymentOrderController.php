<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Services\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Service;
use Illuminate\Http\Request;
use RwInteractive\PayshopSdk\Enums\Payment\Status;
use RwInteractive\PayshopSdk\Models\PaymentOrder;

/**
 * Reembolso e libertação de cativo, a partir do backoffice.
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
        return Service::where('payment_order_id', $order->id)
            ->whereNotIn('status', [
                ServiceStatus::CANCELED,
                ServiceStatus::CLOSED,
                ServiceStatus::REFUSED,
                ServiceStatus::ARCHIVED,
                ServiceStatus::REFUSED_MBWAY,
                ServiceStatus::EXPIRED_MBWAY,
                ServiceStatus::CANCELED_MBWAY,
                ServiceStatus::EXPIRED_3DS,
                ServiceStatus::MATCHING_FAILED,
            ])
            ->first();
    }

    private function present(PaymentOrder $order): array
    {
        return [
            'uuid' => $order->uuid,
            'status' => $order->status->value,
            // Colunas em cêntimos, como o resto do sistema.
            'amount' => (int) $order->amount,
            'refunded' => (int) $order->refunded,
        ];
    }
}
