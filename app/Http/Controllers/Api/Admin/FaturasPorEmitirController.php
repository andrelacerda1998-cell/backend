<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Service;
use App\Models\Vendor;
use App\Services\InvoiceXpress\FaturasEmAtraso;

/**
 * Faturas que ficaram por emitir (ver FaturasEmAtraso), para o backoffice.
 *
 * O dinheiro destes serviços fica retido na carteira do técnico até a fatura
 * sair (Vendor::ganhosPagaveis), por isso esta lista é também a resposta a
 * "porque é que este técnico ainda não recebeu?".
 */
class FaturasPorEmitirController extends Controller
{
    /**
     * GET /v1/admin/faturas-por-emitir
     *
     * Um serviço por linha, do mais antigo para o mais recente. `situacao` diz o
     * que falta e a quem:
     *
     *  - `sem_conta_de_faturacao`: a equipa tem de criar a conta (precisa do IBAN
     *    e da morada fiscal do técnico);
     *  - `sem_at`: o técnico tem de dar o subutilizador da AT;
     *  - `fatura_iniciada`: já há documento no InvoiceXpress (um rascunho, ou
     *    uma fatura finalizada sem o PDF guardado). A tarefa diária conclui-o;
     *    se continuar na lista, é ver esse documento no InvoiceXpress;
     *  - `pronta_a_emitir`: tem tudo; sai na próxima tarefa diária, ou já com o
     *    "emitir agora".
     */
    public function index(): ApiSuccessResponse
    {
        $servicos = Service::query()
            ->porFaturar()
            ->with(['vendor.user', 'serviceType'])
            ->orderBy('id')
            ->get();

        return ApiSuccessResponse::make([
            'items' => $servicos->map(fn (Service $s) => [
                'service_id' => $s->id,
                'vendor_id' => $s->vendor_id,
                'vendor_name' => trim(($s->vendor?->user?->first_name ?? '').' '.($s->vendor?->user?->last_name ?? '')) ?: null,
                'service_type' => $s->serviceType?->getTranslation('name', 'pt-pt'),
                'amount' => round(((int) $s->amount) / 100, 2),
                'amount_for_vendor' => round(((int) $s->amount_for_vendor) / 100, 2),
                // `finished_at` só existe desde 05/10/2026; antes disso, a data do pedido.
                'finished_at' => ($s->finished_at ?? $s->created_at)?->toIso8601String(),
                'dias_em_atraso' => (int) ($s->finished_at ?? $s->created_at)?->diffInDays(now()),
                'situacao' => $this->situacao($s),
                'invoice_id' => $s->invoice_id,
            ])->values()->all(),
            'total' => $servicos->count(),
        ]);
    }

    /**
     * POST /v1/admin/faturas-por-emitir/{vendor}/emitir
     *
     * Não espera pela tarefa diária: põe as faturas do técnico na fila agora.
     * Saem em segundo plano, uma de cada vez (o PDF de cada uma demora pelo
     * menos dez segundos).
     */
    public function emitir(Vendor $vendor): ApiSuccessResponse|ApiErrorResponse
    {
        if (! FaturasEmAtraso::podeEmitir($vendor)) {
            return new ApiErrorResponse(null, $vendor->invoice_workspace
                ? 'Este técnico ainda não deu um subutilizador da AT válido: sem ele a fatura não sai.'
                : 'Este técnico ainda não tem conta de faturação. Crie-a primeiro (precisa do IBAN e da morada fiscal).', 409);
        }

        $porEmitir = FaturasEmAtraso::agendar($vendor);

        if ($porEmitir === 0) {
            return new ApiErrorResponse(null, 'Este técnico não tem faturas por emitir.', 409);
        }

        return ApiSuccessResponse::make([
            'vendor_id' => $vendor->id,
            'por_emitir' => $porEmitir,
        ]);
    }

    private function situacao(Service $service): string
    {
        $vendor = $service->vendor;

        if (! $vendor?->invoice_workspace) {
            return 'sem_conta_de_faturacao';
        }

        if (! $vendor->at_ready) {
            return 'sem_at';
        }

        return $service->invoice_id ? 'fatura_iniciada' : 'pronta_a_emitir';
    }
}
