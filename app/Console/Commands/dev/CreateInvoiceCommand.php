<?php

namespace App\Console\Commands\dev;

use App\Jobs\Services\CreateInvoiceJob;
use App\Models\Service;
use Illuminate\Console\Command;

class CreateInvoiceCommand extends Command
{
    protected $signature = 'dev:create-invoice';

    protected $description = 'Command description';

    public function handle(): void
    {
        $serviceId = 150;

        $service = Service::find($serviceId);
        CreateInvoiceJob::dispatchSync($service);
    }
}
