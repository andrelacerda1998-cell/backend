<?php

namespace App\Http\Controllers\Api\Customer\Services;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Events\Vendor\Services\ServiceCanceledEvent;
use App\Exceptions\Api\Common\Service\ServiceAlreadyCanceled;
use App\Exceptions\Api\Common\Service\ServiceNotFound;
use App\Exceptions\Api\Common\Service\ServiceNotPossibleToCancel;
use App\Http\Controllers\Api\Customer\Services\traits\ProcessPendingScheduleAfterPayment;
use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Service;
use App\Notifications\Vendor\ServiceCanceledNotification;
use App\Services\Common\Services\CancelService;
use App\Services\Common\Services\MbwayPaymentResolver;
use RwInteractive\PayshopSdk\Enums\Payment\Status;

class CancelServiceController extends Controller
{
    use ProcessPendingScheduleAfterPayment;

    public function __invoke(Service $service)
    {
        try {
            if ($service->customer_id !== auth()->user()->id) {
              throw new ServiceNotFound;
            }
            if (in_array($service->status, [ServiceStatus::CANCELED, ServiceStatus::CANCELED_MBWAY], true)) {
                throw new ServiceAlreadyCanceled;
            }

            // Pagamento MBWay ainda por confirmar: re-verificar no Payshop ANTES de cancelar —
            // o cliente pode ter aprovado o push no banco no instante anterior. Se pagou, não
            // cancelamos: confirmamos e devolvemos 409 already_paid para a app seguir o fluxo pago.
            $canceledBeforePayment = false;
            if ($service->status === ServiceStatus::PENDING
                && $service->payment_status !== PaymentStatus::PAID) {
                $earlyResponse = $this->resolvePendingMbwayBeforeCancel($service);

                if ($earlyResponse) {
                    return $earlyResponse;
                }

                $canceledBeforePayment = true;
            }

            $cancelService = new CancelService($service);

            if ($canceledBeforePayment) {
                // Vendor nunca soube deste serviço (só é notificado após o pagamento confirmar):
                // status terminal próprio e SEM notificação de cancelamento.
                $cancelService->customerCancelBeforePayment();
            } elseif ($service->status === ServiceStatus::PENDING) {
                $cancelService->customerCancel();
            } else {
                $cancelService->cancelOpenService();
            }

            $service->refresh();

            $expectedStatus = $canceledBeforePayment ? ServiceStatus::CANCELED_MBWAY : ServiceStatus::CANCELED;
            if ($service->status !== $expectedStatus) {
                throw new ServiceNotPossibleToCancel;
            }

            $this->cancelUnprocessedPaymentOrder($service);

            // Notificar SOMENTE o vendor do serviço (se houver vendor atribuído) — e nunca
            // num cancelamento pré-pagamento, em que o vendor não conhecia o serviço.
            $vendor = $canceledBeforePayment ? null : $service->vendor?->user;
            if ($vendor) {
                ServiceCanceledEvent::dispatch($vendor->id, $service);
                if (! $vendor->trashed() && $vendor->devices()->exists()) {
                    try {
                        $vendor->notify(new ServiceCanceledNotification($service));
                    } catch (\Throwable $e) {
                        report($e); // falha de push NUNCA quebra o cancelamento
                    }
                }
            }

            return new ApiSuccessResponse;
        } catch (\Exception $e) {
            return new ApiErrorResponse($e);
        }
    }

    /**
     * Consulta o Payshop e resolve o desfecho de um MBWay pendente antes do cancelamento.
     * Devolve uma resposta (409 already_paid / 200 refused) quando o pagamento já tem desfecho,
     * ou null para o cancelamento prosseguir normalmente.
     */
    private function resolvePendingMbwayBeforeCancel(Service $service)
    {
        $service->loadMissing('paymentOrder');

        if (! $service->paymentOrder) {
            return null;
        }

        try {
            $service->paymentOrder->updateData();
        } catch (\Throwable $e) {
            report($e); // Payshop indisponível não pode impedir o cliente de cancelar

            return null;
        }

        $orderStatus = $service->paymentOrder->status;

        if (in_array($orderStatus, [Status::SUCCESS, Status::PENDING_CONFIRMATION], true)) {
            // Afinal pagou. Mesmo lock/transição do checkPaymentStatus para não correr contra o job.
            $outcome = \DB::transaction(function () use ($service) {
                $locked = Service::whereKey($service->getKey())->lockForUpdate()->first();

                if ($locked->payment_status === PaymentStatus::PAID) {
                    return 'already_paid';
                }

                if (MbwayPaymentResolver::isTerminal($locked)) {
                    return 'terminal';
                }

                app(MbwayPaymentResolver::class)->markPaid($locked);

                return 'confirmed_now';
            });

            if ($outcome === 'terminal') {
                return null;
            }

            if ($outcome === 'confirmed_now') {
                $this->processPendingScheduleAfterPayment($service->refresh());
            }

            return response()->json([
                'message' => __('exceptions.services.payment_already_confirmed'),
                'data' => ['service' => $service->refresh()->formatDataForCustomer()],
                'metadata' => ['code' => 'already_paid'],
            ], 409);
        }

        if ($orderStatus === Status::REFUNDED) {
            // Já tinha recusado no banco: refletir a realidade (REFUSED_MBWAY, não CANCELED).
            // O Observer liberta o hold; para o cliente o efeito é o mesmo que o cancelamento.
            app(MbwayPaymentResolver::class)->markRefused($service);

            return new ApiSuccessResponse(['status' => ServiceStatus::REFUSED_MBWAY->value]);
        }

        return null;
    }

    /**
     * Ordem MBWay ainda não autorizada (CREATED/PENDING_PAYMENT) num serviço já cancelado:
     * cancelar também no Payshop para o push morrer na app do banco — evita uma aprovação
     * póstuma com o serviço já cancelado. (PENDING_CONFIRMATION/SUCCESS já são tratados
     * pelo ServiceObserver no save do estado CANCELED.)
     */
    private function cancelUnprocessedPaymentOrder(Service $service): void
    {
        $service->loadMissing('paymentOrder');
        $order = $service->paymentOrder;

        if (! $order || in_array($order->status, [
            Status::SUCCESS,
            Status::REFUNDED,
            Status::CANCELLED,
            Status::PENDING_CONFIRMATION,
        ], true)) {
            return;
        }

        try {
            $order->cancel();
        } catch (\Throwable $e) {
            report($e); // melhor esforço — o hold nunca foi confirmado, expira sozinho
        }
    }
}
