<?php

namespace App\Http\Controllers\Api\Customer\Services;

use App\Enums\Services\ServiceStatus;
use App\Exceptions\Api\Common\Service\ServiceNotFound;
use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Service;
use App\Services\Common\Services\ReportarProblema;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * "Reportar um problema", ligado ao serviço.
 *
 * Antes só havia o ticket genérico de suporte: chegava sem contexto e não
 * parava nada. Este fica no serviço, avisa o backoffice e, num serviço
 * concluído, impede que feche e cobre sozinho enquanto ninguém olhar.
 */
class ReportProblemController extends Controller
{
    /** Motivos que o cliente pode escolher. */
    public const MOTIVOS = ['not_done', 'poor_quality', 'damage', 'price', 'no_show', 'other'];

    /** Estados em que um relato faz sentido: o serviço existe e tem técnico. */
    private const ESTADOS = [
        ServiceStatus::SCHEDULED,
        ServiceStatus::ACCEPTED,
        ServiceStatus::ARRIVED,
        ServiceStatus::FINISHED,
        ServiceStatus::CLOSED,
        ServiceStatus::CLOSED_PENDING_PAYMENT,
    ];

    public function __invoke(Request $request, Service $service, ReportarProblema $reportar)
    {
        $dados = $request->validate([
            'reason' => ['required', 'string', Rule::in(self::MOTIVOS)],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            if ($service->customer_id !== auth()->id()) {
                throw new ServiceNotFound;
            }

            if (! in_array($service->status, self::ESTADOS, true)) {
                return new ApiErrorResponse(new Exception, 'This service cannot receive a problem report', 409);
            }

            $reportar->handle($service, 'customer', $dados['reason'], $dados['message'] ?? null);

            return new ApiSuccessResponse(['service' => $service->refresh()->formatDataForCustomer()]);
        } catch (Exception $e) {
            return new ApiErrorResponse($e);
        }
    }
}
