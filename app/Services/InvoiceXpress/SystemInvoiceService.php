<?php

namespace App\Services\InvoiceXpress;

use App\Jobs\Vendor\PrepareWorkspaceJob;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use App\Services\InvoiceXpress\Contracts\HasInvoiceActions;
use App\Services\InvoiceXpress\Contracts\HasInvoicesItems;
use App\Services\InvoiceXpress\Contracts\HasInvoiceXpressRequests;

class SystemInvoiceService
{
    use HasInvoiceXpressRequests, HasInvoicesItems, HasInvoiceActions;

    public function __construct()
    {
        $this->apiKey = config('services.invoiceExpress.api_key');
        $this->workspace = config('services.invoiceExpress.account_name');
    }

    public function createWorkspace(Vendor $vendor): void
    {
        if (config('services.invoiceExpress.sandbox')){
            $this->setSandboxWorkspace($vendor);
            return;
        }
        $data = [
            'account' => [
                'organization_name' => $vendor->company_name,
                'email' => config('services.invoiceExpress.email_address'),
                'password' => config('services.invoiceExpress.password'),
                'fiscal_id' => $vendor->user->nif,
                'tax_country' => "1",
                "language" => "pt",
                'terms' => "1",
                "marketing" => "0"
            ]
        ];


        $data = $this->sendRequest('/api/accounts/create_already_user.json', 'POST', $data);

        $account = $data['account'];

        $vendor->auth_token = $account['api_key'];

        $url = $account['url'];
        $host = parse_url($url, PHP_URL_HOST);
        $subdomains = explode('.', $host);

        $workspace = $subdomains[0];

        $vendor->invoice_workspace = $workspace;
        $vendor->invoice_account_id = $account['id'];

        $vendor->save();

        if ($vendor->at_user !== null && $vendor->at_password !== null){
            PrepareWorkspaceJob::dispatch($vendor);
        }
    }

    public function setSandboxWorkspace(Vendor $vendor): void
    {
        $vendor->auth_token = config('services.invoiceExpress.api_key');
        $vendor->invoice_workspace = config('services.invoiceExpress.account_name');
        $vendor->invoice_account_id = config('services.invoiceExpress.account_id');
        $vendor->save();
    }

    public function createCancellationServiceInvoice(Service $service)
    {
        $user = $service->vendor->user;
        $vendor = $this->prepareClientData($user->name, $user->email, $user->nif);

        $cancellationFee = $service->amount_for_vendor * 0.1;


        $payload = $this->generateInvoicePayload($service->created_at, $service->created_at, $service->id, $vendor, [$this->item('Cancellation fee', $cancellationFee)]);
        $response = $this->sendRequest('/invoice_receipts.json', 'POST',$payload);
        $service->invoice_id = $response['invoice_receipt']['id'];
        $service->save();

        return $response['invoice_receipt']['id'];

    }
}
