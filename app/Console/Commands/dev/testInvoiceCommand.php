<?php

namespace App\Console\Commands\dev;

use App\Models\Service;
use App\Services\InvoiceXpress\SystemInvoiceService;
use Illuminate\Console\Command;

class testInvoiceCommand extends Command
{
    protected $signature = 'test:invoice';

    protected $description = 'Command description';

    public function handle(): void
    {
        $service = Service::find(113);


        $invoiceExpressService = new SystemInvoiceService();
        //$invoiceExpressService->createWorkspace($service->vendor);

        $invoiceId = $invoiceExpressService->createCancellationServiceInvoice($service);
        $invoiceExpressService->finalizeInvoice($invoiceId);
        $invoiceExpressService->downloadPdf($invoiceId, $service);

    }
}
