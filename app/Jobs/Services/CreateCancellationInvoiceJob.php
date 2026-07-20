<?php

namespace App\Jobs\Services;

use App\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CreateCancellationInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private Service $service)
    {
    }

    public function handle(): void
    {
        // Emissão de fatura de cancelamento desativada intencionalmente.
        return;
    }
}
