<?php

namespace App\Jobs\Services;

use App\Exceptions\Api\Vendor\Invoicing\VendorInvalidAtCredentials;
use App\Exceptions\Api\Vendor\VendorDuplicatedSequence;
use App\Models\Service;
use App\Services\InvoiceXpress\InvoiceVendorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CreateInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public Service $service)
    {
    }

    public function handle(): void
    {
        $this->service->refresh();

        // Idempotência: se já existe fatura, não emitir uma 2ª na AT (reclaim/retry do job).
        if ($this->service->invoice_id) {
            return;
        }

        // Pelo contentor, como na FaturasEmAtraso: os testes trocam-no por um duplo.
        $invoiceExpressService = app(InvoiceVendorService::class, ['vendor' => $this->service->vendor]);
        $invoiceId = $invoiceExpressService->createServiceInvoice($this->service);

        $response = $invoiceExpressService->finalizeInvoice($invoiceId);
        if (str_contains($response['errors'][0]['error'] ?? '', 'Este documento não tem ATCUD')){
            try {
                $invoiceExpressService->createSequence();
            }catch (VendorInvalidAtCredentials $e){
                if (str_contains($e->getMessage(), 'As credenciais associadas à conta não são válidas.')){
                    $this->service->vendor->at_valid = false;
                    $this->service->vendor->save();

                    return;
                }
            }

            // Com a série criada, finaliza-se outra vez. Até aqui ia-se direto ao
            // PDF, e o PDF que vinha era o do RASCUNHO.
            $response = $invoiceExpressService->finalizeInvoice($invoiceId);
        }

        // O InvoiceXpress também gera o PDF de um rascunho (visto a 13/10/2026 na
        // conta de testes). Guardá-lo dava o serviço por faturado
        // (Service::temFaturaEmitida) com a fatura por finalizar. Sem finalizar,
        // fica o rascunho com o `invoice_id`, e a FaturasEmAtraso finaliza-o depois.
        if (! empty($response['errors'])) {
            Log::warning('[faturação] o InvoiceXpress recusou finalizar: a fatura fica em rascunho', [
                'service_id' => $this->service->id,
                'invoice_id' => $invoiceId,
                'erro' => $response['errors'][0]['error'] ?? $response['errors'],
            ]);

            return;
        }

        $invoiceExpressService->downloadPdf($invoiceId, $this->service);
    }
}
