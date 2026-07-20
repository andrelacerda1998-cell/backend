<?php

namespace App\Console\Commands;

use App\Models\Vendor;
use App\Services\InvoiceXpress\InvoiceVendorService;
use Illuminate\Console\Command;

class CreateInvoiceSequencesCommand extends Command
{
    protected $signature = 'create:invoice-sequences';

    protected $description = 'Create new invoice sequences';

    public function handle(): void
    {
        Vendor::whereNotNull('invoice_workspace')->whereNotNull('auth_token')->chunk(10, function ($vendors) {
            foreach ($vendors as $vendor) {
                try {
                    $service = new InvoiceVendorService($vendor);
                    $service->createSequence();
                }catch (\Exception $e) {
                    logger($e->getMessage());
                }

            }
        });
    }
}
