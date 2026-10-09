<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Services\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\GeneralSettings\OperationArea;
use App\Models\Service;
use App\Services\Common\Services\CloseService;
use App\Services\Matching\DespacharPedidoPersonalizado;
use Illuminate\Http\Request;

/**
 * As ações sobre um pedido que só existiam no Filament, agora também para o
 * backoffice: despachar um personalizado, fechar um serviço terminado, tentar
 * cobrar de novo e desistir e devolver.
 *
 * Cada uma é a MESMA operação que o Filament corre (DespacharPedidoPersonalizado
 * e CloseService), com as mesmas guardas de estado e o mesmo lock -- só muda a
 * porta. No Filament, tentar cobrar e desistir são só para super-admin; aqui a
 * API de admin usa um token partilhado e quem pode é decidido no backoffice
 * (perfil Finanças, com motivo registado).
 */
class ServiceActionController extends Controller
{
    /** POST /v1/admin/services/{service}/despachar  { "minutos": 120, "areas": [3, 7] } */
    public function despachar(Request $request, Service $service, DespacharPedidoPersonalizado $despachar): ApiSuccessResponse|ApiErrorResponse
    {
        $dados = $request->validate([
            'minutos' => ['required', 'integer', 'min:'.DespacharPedidoPersonalizado::MINUTOS_MINIMOS, 'max:1440'],
            'areas' => ['required', 'array', 'min:1'],
            'areas.*' => ['integer'],
        ]);

        $existentes = OperationArea::whereIn('id', $dados['areas'])->pluck('id')->all();
        if (count($existentes) !== count(array_unique($dados['areas']))) {
            return new ApiErrorResponse(null, 'Há categorias que não existem.', 422);
        }

        try {
            $convidados = $despachar($service, (int) $dados['minutos'], $dados['areas']);
        } catch (\DomainException $e) {
            return new ApiErrorResponse(null, $e->getMessage(), 409);
        } catch (\InvalidArgumentException $e) {
            return new ApiErrorResponse(null, $e->getMessage(), 422);
        }

        return ApiSuccessResponse::make($this->resultado($service, ['convidados' => $convidados]));
    }

    /**
     * POST /v1/admin/services/{service}/fechar — o técnico terminou e o cliente
     * não confirmou: cobra e paga ao técnico. Se a cobrança falhar, o serviço
     * fica em ClosedPendingPayment, à espera de "tentar cobrar".
     */
    public function fechar(Service $service): ApiSuccessResponse|ApiErrorResponse
    {
        return $this->correr($service, ServiceStatus::FINISHED, 'Só se fecha um serviço que o técnico deu por terminado.',
            fn () => (new CloseService($service))->close());
    }

    /** POST /v1/admin/services/{service}/tentar-cobrar — volta a tentar a captura de um ClosedPendingPayment. */
    public function tentarCobrar(Service $service): ApiSuccessResponse|ApiErrorResponse
    {
        return $this->correr($service, ServiceStatus::CLOSED_PENDING_PAYMENT, 'Este serviço não tem um pagamento por cobrar.',
            fn () => (new CloseService($service))->retryCapture());
    }

    /**
     * POST /v1/admin/services/{service}/desistir-e-devolver — a cobrança não
     * passa: cancela o serviço, devolve o crédito e liberta a pré-autorização,
     * SEM pagar ao técnico (o ServiceObserver trata do resto).
     */
    public function desistirEDevolver(Service $service): ApiSuccessResponse|ApiErrorResponse
    {
        return $this->correr($service, ServiceStatus::CLOSED_PENDING_PAYMENT, 'Este serviço não tem um pagamento por cobrar.',
            fn () => (new CloseService($service))->abandonAndRefund());
    }

    /**
     * Guarda de estado, a operação, e o aviso de "fechado e pago" quando o
     * serviço ficou mesmo fechado (como no fecho pelo cliente).
     */
    private function correr(Service $service, ServiceStatus $esperado, string $foraDeEstado, \Closure $operacao): ApiSuccessResponse|ApiErrorResponse
    {
        if ($service->status !== $esperado) {
            return new ApiErrorResponse(null, $foraDeEstado, 409);
        }

        try {
            $estado = $operacao();
        } catch (\Throwable $e) {
            report($e);
            $conflito = (int) $e->getCode() === 409;

            $mensagem = match (true) {
                $conflito => 'O serviço mudou entretanto de estado. Atualiza e volta a ver.',
                // retryCapture() lança isto quando o Payshop recusa a captura.
                $e->getMessage() === 'Payment capture failed' => 'O Payshop voltou a recusar a cobrança. Podes tentar mais tarde, ou desistir e devolver.',
                default => 'Não foi possível concluir: '.$e->getMessage(),
            };

            return new ApiErrorResponse(null, $mensagem, $conflito ? 409 : 502);
        }

        if ($estado === ServiceStatus::CLOSED) {
            try {
                CloseService::anunciarFecho($service->refresh());
            } catch (\Throwable $e) {
                // O dinheiro já está tratado; um aviso que falha não desfaz isso.
                report($e);
            }
        }

        return ApiSuccessResponse::make($this->resultado($service));
    }

    private function resultado(Service $service, array $extra = []): array
    {
        $service->refresh();

        return [
            'id' => (string) $service->id,
            'status' => $service->status?->value ?? (string) $service->status,
            'payment_status' => $service->payment_status?->value ?? (string) $service->payment_status,
            ...$extra,
        ];
    }
}
