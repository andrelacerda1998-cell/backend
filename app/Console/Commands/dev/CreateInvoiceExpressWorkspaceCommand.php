<?php

namespace App\Console\Commands\dev;

use App\Models\Vendor;
use App\Services\InvoiceXpress\InvoiceVendorService;
use Illuminate\Console\Command;

class CreateInvoiceExpressWorkspaceCommand extends Command
{
    protected $signature = 'create:invoice-express-workspace';

    protected $description = 'Command description';

    public function handle(): void
    {
        $vendor = Vendor::find(7);

        #$service = new SystemInvoiceService();
        #$service->createWorkspace($vendor);

        #$vendor->refresh();

        $vendor->auth_token = config('services.invoiceExpress.api_key');
        #$vendor->auth_token = 'a96d7460ec5f82574fcaec3136acfb8a6425efc1';
        #$vendor->invoice_account_id = '152634';
        #$vendor->invoice_workspace = 'devdev';
        $vendor->invoice_account_id = '152518';
        $vendor->invoice_workspace = config('services.invoiceExpress.account_name');
        $vendor->save();


        $service = new InvoiceVendorService($vendor);

        $service->createAtCommunications();
        $service->updateFiscalDetails();



        $service->createSequence();
    }
}
