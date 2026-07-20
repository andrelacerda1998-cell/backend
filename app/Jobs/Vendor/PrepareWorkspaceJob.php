<?php

namespace App\Jobs\Vendor;

use App\Exceptions\Api\Vendor\VendorDuplicatedSequence;
use App\Models\Vendor;
use App\Services\InvoiceXpress\InvoiceVendorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PrepareWorkspaceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly Vendor $vendor)
    {
    }

    public function handle(): void
    {
        $vendor = $this->vendor;

        try {
            $service = new InvoiceVendorService($vendor);

            $service->updateFiscalDetails();

            $service->createSequence();
        }catch (VendorDuplicatedSequence){
            return;
        }
        catch (\Exception $e) {
            logger($e->getMessage());
        }

    }
}
