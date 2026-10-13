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

        // A fatura é emitida na conta de faturação do técnico. Desde 13/10/2026
        // ele pode fazer os três primeiros serviços sem ela (Vendor::faturacaoEmDia),
        // e sem conta não há onde a emitir: o pedido ia para uma conta vazia no
        // InvoiceXpress e o job rebentava. O serviço fica por faturar, com o
        // invoice_id a null, que é como se encontra para a emitir depois.
        if (! $this->service->vendor?->invoice_workspace) {
            Log::warning('[faturação] serviço fechado de um técnico sem conta de faturação: fatura por emitir', [
                'service_id' => $this->service->id,
                'vendor_id' => $this->service->vendor_id,
            ]);

            return;
        }

        $invoiceExpressService = new InvoiceVendorService($this->service->vendor);
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
        }

        $invoiceExpressService->downloadPdf($invoiceId, $this->service);
    }
}
