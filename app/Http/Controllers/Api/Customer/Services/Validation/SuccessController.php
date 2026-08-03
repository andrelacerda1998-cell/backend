<?php

namespace App\Http\Controllers\Api\Customer\Services\Validation;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Http\Controllers\Api\Customer\Services\traits\NotifyVendor;
use App\Http\Controllers\Api\Customer\Services\traits\ProcessPendingScheduleAfterPayment;
use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Services\Common\Services\AcceptService;
use App\Services\Common\Services\ResolveServiceExtraValidation;
use Illuminate\Http\Request;
use RwInteractive\PayshopSdk\Api\Payments\PaymentOrder;

class SuccessController extends Controller
{
    use NotifyVendor, ProcessPendingScheduleAfterPayment;

    public function __construct(
        private readonly AcceptService $acceptService,
        private readonly ResolveServiceExtraValidation $resolveServiceExtraValidation,
    ) {}

    public function __invoke(Request $request, Service $service)
    {
        // Callback do 3DS de um EXTRA (tempo/peças), não do serviço base — nunca cai na
        // lógica abaixo, que assume que a ordem em causa é a do serviço base (que já está
        // sempre paga nesta altura, porque só há extras com o serviço a decorrer).
        if ($request->query('extra')) {
            $path = $this->resolveServiceExtraValidation->success($service, (int) $request->query('extra'));

            return redirect()->away('piquet.customer:://'.$path);
        }

        if ($service->payment_status == PaymentStatus::PAID) {
            abort(400, 'Service already paid');
        }

        // An archived service must never be revived back to an open/pending state
        if ($service->status === ServiceStatus::ARCHIVED) {
            abort(400, 'Service archived');
        }

        $paymentOrderService = PaymentOrder::make();
        $paymentDetails = $paymentOrderService->details($service->paymentOrder);

        $successStatuses = ['SUCCESS', 'PENDING_CONFIRMATION', 'PAID'];
        if (! in_array($paymentDetails['order']['status'] ?? null, $successStatuses, true)) {
            // Pré-autorização ainda a processar quando o browser regressou: devolver o
            // controlo à app (deep link) em vez de uma página 400 morta. A app cai no
            // ecrã de espera do cartão e o checkPaymentStatus assenta quando a Payshop confirmar.
            return redirect()->away('piquet.customer:://validation/pending?service='.$service->id);
        }

        // Transição para PAID sob lock + reverificação (espelha OpenServiceController::checkPaymentStatus)
        // para que dois callbacks de sucesso concorrentes não materializem o schedule / disparem a
        // push ao vendor duas vezes. A verificação remota details() ficou fora da transação de propósito.
        $outcome = \DB::transaction(function () use ($service) {
            $locked = Service::whereKey($service->getKey())->lockForUpdate()->first();

            if ($locked->payment_status === PaymentStatus::PAID) {
                return 'already_paid';
            }

            if ($locked->status === ServiceStatus::ARCHIVED) {
                return 'archived';
            }

            $locked->payment_status = PaymentStatus::PAID;
            if ($locked->status === ServiceStatus::PENDING_3DS) {
                $locked->status = ServiceStatus::PENDING;
            }
            $locked->save();

            return 'confirmed_now';
        });

        if ($outcome === 'archived') {
            abort(400, 'Service archived');
        }

        if ($outcome === 'confirmed_now') {
            // Materializa a agenda / notifica o vendor DEPOIS do commit (só quando confirmado agora).
            $this->processPendingScheduleAfterPayment($service->refresh());
        }

        return redirect()->away('piquet.customer:://validation/success?service='.$service->id);
    }
}
