<?php

namespace App\Http\Controllers\Api\Vendor\Services;

use App\Enums\Services\ServiceStatus;
use App\Events\Common\Services\ServiceCanceledEvent;
use App\Exceptions\Api\Common\Service\ServiceNotFound;
use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Service;
use App\Notifications\Customer\ServiceCanceledByVendorNotification;
use App\Services\Common\Services\CancelService;
use App\Services\Matching\ReabrirPedidoAposCancelamento;

class CancelServiceController extends Controller
{
    public function __invoke(Service $service)
    {
        try {
            if ($service->vendor_id !== auth()->user()->vendor?->id) {
              throw new ServiceNotFound;
            }

            // A hora marcada lê-se ANTES: cancelar apaga a linha de agenda, e é
            // com ela que o pedido reaberto mantém o dia e a hora.
            $intencao = $service->scheduleIntent();

            // Tudo pelo caminho do TÉCNICO. Aceite e no local iam para o
            // `cancelOpenService`, que é a regra do cliente — e cobrava ao
            // cliente o cancelamento do técnico (ver CancelService).
            (new CancelService($service))->vendorCancelService();

            $service->refresh();

            $serviceDetails = $service->only(['id']);

            ServiceCanceledEvent::dispatch($serviceDetails);

            // Notificar SOMENTE o customer do serviço, e só se o cancelamento efetivou
            if ($service->status === ServiceStatus::CANCELED) {
                // O cliente não fica a recomeçar do zero: o pedido volta a
                // procurar outro técnico. Uma falha aqui não desfaz o
                // cancelamento — fica só o aviso de sempre.
                $reaberto = null;
                try {
                    $reaberto = app(ReabrirPedidoAposCancelamento::class)->handle($service, $intencao);
                } catch (\Throwable $e) {
                    report($e);
                }

                $customer = $service->customerUser; // sem filtro whereDoesntHave -> nunca null por causa do vendor
                if ($customer && ! $customer->trashed() && $customer->devices()->exists()) {
                    try {
                        $customer->notify(new ServiceCanceledByVendorNotification(
                            $service,
                            $reaberto?->status === ServiceStatus::MATCHING ? $reaberto : null,
                        ));
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
}
