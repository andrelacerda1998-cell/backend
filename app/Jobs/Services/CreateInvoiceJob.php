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
