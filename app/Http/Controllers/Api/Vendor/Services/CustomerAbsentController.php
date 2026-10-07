<?php

namespace App\Http\Controllers\Api\Vendor\Services;

use App\Enums\Services\ServiceStatus;
use App\Exceptions\Api\Common\Service\ServiceNotFound;
use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Service;
use App\Notifications\Customer\VendorCantFindCustomerNotification;
use App\Services\Common\Services\ReportarProblema;
use Exception;
use Illuminate\Http\Request;

/**
 * "Cliente não está": o técnico chegou e não encontra ninguém.
 *
 * Antes não havia saída. Depois de "Cheguei" não podia cancelar; antes disso,
 * cancelar devolvia tudo ao cliente e ele pagava a deslocação do bolso. E o
 * suporte só sabia pelo telefone.
 *
 * Duas coisas, já: o cliente é avisado de que o técnico está à porta (muitas
 * vezes resolve-se aqui), e o backoffice recebe o caso para decidir —
 * esperar, pagar a deslocação, ou fechar. O fecho automático fica parado.
 * Não se cobra nada sozinho: quem decide é uma pessoa.
 */
class CustomerAbsentController extends Controller
{
    public function __invoke(Request $request, Service $service, ReportarProblema $reportar)
    {
        $dados = $request->validate([
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            if ($service->vendor_id !== auth()->user()->vendor?->id) {
                throw new ServiceNotFound;
            }

            // Só quando ele está mesmo lá, ou a caminho de lá: no local, ou
            // aceite e já com "A caminho" carregado.
            $noLocal = $service->status === ServiceStatus::ARRIVED
                || (in_array($service->status, [ServiceStatus::ACCEPTED, ServiceStatus::SCHEDULED], true) && $service->on_the_way_at);

            if (! $noLocal) {
                return new ApiErrorResponse(new Exception, 'Only possible once you are on the way or at the address', 409);
            }

            $primeiro = $reportar->handle($service, 'vendor', 'customer_absent', $dados['message'] ?? null);

            if ($primeiro) {
                try {
                    $service->customer?->notify(new VendorCantFindCustomerNotification($service));
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            return new ApiSuccessResponse(['service' => $service->refresh()->formatDataForVendor()]);
        } catch (Exception $e) {
            return new ApiErrorResponse($e);
        }
    }
}
