<?php

namespace App\Http\Controllers\Api\Customer\Services;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Exceptions\Api\Common\Service\ServiceNotFound;
use App\Exceptions\Api\Common\Service\ServiceNotPossibleToCancel;
use App\Http\Controllers\Api\Customer\Services\traits\ProcessPendingScheduleAfterPayment;
use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Service;
use App\Services\Common\Services\MbwayPaymentResolver;
use RwInteractive\PayshopSdk\Enums\Payment\Status;

/**
 * Cancelamento exclusivo de um serviço à espera de validação 3DS (Pending3DS) que ainda NÃO
 * foi confirmado no Payshop — o botão "Cancelar" do ecrã de espera do cartão.
 *
 * O /cancel genérico (CancelServiceController) não tem ramo para Pending3DS (lança
 * ServiceNotPossibleToCancel) e, quando a recusa do banco já marcou o serviço CANCELED, lança
 * ServiceAlreadyCanceled — daí o botão só produzir erro. Este endpoint é idempotente e trata
 * ambos os casos.
 */
class CancelPending3DSController extends Controller
{
    use ProcessPendingScheduleAfterPayment;

    public function __invoke(Service $service)
    {
        try {
            if ($service->customer_id !== auth()->user()->id) {
                throw new ServiceNotFound;
            }

            // Idempotência: a recusa do banco (FailureValidationController) já pode ter marcado
            // o serviço CANCELED. Devolver 200 — nunca um erro "já cancelado" — para a app sair
            // graciosamente do ecrã de espera em vez de mostrar um botão que só falha.
            if (in_array($service->status, [ServiceStatus::CANCELED, ServiceStatus::CANCELED_MBWAY], true)) {
                return new ApiSuccessResponse;
            }

            // Só cancelamos ativamente serviços à espera de validação 3DS que ainda não pagaram.
            if ($service->status !== ServiceStatus::PENDING_3DS
                || $service->payment_status === PaymentStatus::PAID) {
                throw new ServiceNotPossibleToCancel;
            }

            // Re-verificar no Payshop ANTES de cancelar — o cliente pode ter concluído o 3DS no
            // instante anterior ao toque em cancelar. Se afinal pagou, não cancelamos: refletimos
            // o pagamento e devolvemos 409 already_paid para a app seguir para o ecrã de confirmado.
            $earlyResponse = $this->resolvePaid3dsBeforeCancel($service);

            if ($earlyResponse) {
                return $earlyResponse;
            }

            // Não confirmado no Payshop → cancelar. Espelha o FailureValidationController: gravar
            // CANCELED dispara o ServiceObserver, que liberta o cativo no Payshop (best-effort) e
            // liberta qualquer voucher. Sem notificação ao vendor (que nunca conheceu o serviço).
            $service->payment_status = PaymentStatus::CANCELED;
            $service->status = ServiceStatus::CANCELED;
            $service->status_justification = 'internal/services.cancel.description';
            $service->save();

            return new ApiSuccessResponse;
        } catch (\Exception $e) {
            return new ApiErrorResponse($e);
        }
    }

    /**
     * Consulta o Payshop e resolve o desfecho de um 3DS pendente antes do cancelamento.
     * Devolve uma resposta 409 already_paid quando o pagamento afinal se concretizou, ou null
     * para o cancelamento prosseguir normalmente.
     */
    private function resolvePaid3dsBeforeCancel(Service $service)
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

        if (! in_array($service->paymentOrder->status, [Status::SUCCESS, Status::PENDING_CONFIRMATION], true)) {
            return null;
        }

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
}
