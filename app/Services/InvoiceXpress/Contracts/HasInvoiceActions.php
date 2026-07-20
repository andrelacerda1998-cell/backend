<?php

namespace App\Services\InvoiceXpress\Contracts;

use App\Models\Service;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

trait HasInvoiceActions
{
    public function finalizeInvoice(int $invoiceId)
    {
        $url = '/invoices/' . $invoiceId . '/change-state.json';
        $payload = $this->generateFinalizedInvoicePayload();

        $response = $this->sendRequest($url, 'PUT', $payload,);

        return $response;
    }

    private function generateFinalizedInvoicePayload(): array
    {
        return [
            'invoice' => [
                'state' => 'finalized'
            ]
        ];
    }

    public function downloadPdf(int $invoiceId, Service $service): Media
    {
        $pdfUrl = null;
        $tries = 0;

        while ($pdfUrl === null) {
            $url = '/api/pdf/' . $invoiceId . '.json';
            $response = $this->sendRequest($url, 'GET', [], [
                'second_copy' => "false"
            ]);
            Log::info($response);
            if (isset($response['output'])) {
                $pdfUrl = $response['output']['pdfUrl'];
            }else{
                $tries++;
            }
            if ($tries > 5){
                throw new \Exception('Could not download PDF');
            }
            sleep(10);
        }


        return $service->addMediaFromUrl($pdfUrl)->toMediaCollection('invoices');
    }
}
