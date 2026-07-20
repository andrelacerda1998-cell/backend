<?php

namespace App\Jobs\Services;

use App\Models\Service;
use App\Services\InvoiceXpress\SystemInvoiceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CreateVendorCancellationInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Emite uma fatura fiscal REAL na AT (irreversível). Sem retry: um retry após uma falha
    // pós-emissão duplicaria a fatura. O guard invoice_id abaixo é a segunda linha de defesa.
    public int $tries = 1;

    public function __construct(private Service $service)
    {
    }

    public function handle(): void
    {
        // Idempotência: se já existe fatura para este serviço, não emitir outra (defesa contra
        // dispatch duplo / re-execução). SerializesModels recarrega o modelo da BD ao correr.
        if ($this->service->invoice_id) {
            return;
        }

        $service = new SystemInvoiceService();

        $invoice = $service->createCancellationServiceInvoice($this->service);
        $service->finalizeInvoice($invoice);
        $service->downloadPdf($invoice, $this->service);
    }
}
