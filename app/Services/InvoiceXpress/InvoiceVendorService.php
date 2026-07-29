<?php

namespace App\Services\InvoiceXpress;

use App\Enums\Services\AddressType;
use App\Exceptions\Api\Vendor\Invoicing\VendorInvalidAtCredentials;
use App\Exceptions\Api\Vendor\VendorDuplicatedSequence;
use App\Models\Service;
use App\Models\Vendor;
use App\Services\InvoiceXpress\Contracts\HasInvoiceActions;
use App\Services\InvoiceXpress\Contracts\HasInvoicesItems;
use App\Services\InvoiceXpress\Contracts\HasInvoiceXpressRequests;

class InvoiceVendorService
{
    use HasInvoiceActions, HasInvoicesItems, HasInvoiceXpressRequests;

    private Vendor $vendor;

    public function __construct(Vendor $vendor)
    {
        $this->vendor = $vendor;

        $this->apiKey = $vendor->auth_token;
        $this->workspace = $vendor->invoice_workspace;
    }

    public function createCancellationServiceInvoice(Service $service)
    {
        $user = $service->customer;

        $address = $user->billingInfo;

        if ($address === null) {
            $address['address'] = $service->address?->street_name.' '.$service->address?->street_number;
            $address['postal_code'] = $service->address?->postal_code;
            $address['locality'] = $service->address?->city; // `locality` é a chave que o prepareClientData mapeia para "city" na
                // fatura; o modelo Address NÃO tem coluna `locality` (tem city,
                // municipality e state), por isso isto chegava sempre a null e as
                // faturas saíam sem localidade.
        } else {
            $address = $address->toArray();
        }

        $nif = $service->nif ?? $user->nif;
        $customer = $this->prepareClientData($user->name, $user->email, $nif, $address);

        $cancellationFee = $service->amount * 0.1;

        $payload = $this->generateInvoicePayload($service->created_at, $service->created_at, $service->id, $customer, [$this->item('Taxa de cancelamento', $cancellationFee)]);
        $response = $this->sendRequest('/invoice_receipts.json', 'POST', $payload);
        $service->invoice_id = $response['invoice_receipt']['id'];
        $service->save();

        return $response['invoice_receipt']['id'];

    }

    /**
     * @throws VendorInvalidAtCredentials
     */
    public function updateFiscalDetails(): array
    {
        $address = $this->vendor->addresses->where('address_type', AddressType::FISCAL_ADDRESS)->first()
            ?? $this->vendor->addresses->first();

        if ($address === null) {
            throw new \Exception('Address is not set. Please set fiscal address in the vendor profile.');
        }

        if (config('services.invoiceExpress.sandbox')) {
            $nif = config('services.invoiceExpress.nif');
        } else {
            $nif = explode('/', $this->vendor->at_user)[0];
        }

        $payload = [
            'organization_name' => $this->vendor->company_name,
            'fiscal_id' => $nif,
            'address' => $address->street_name.' '.$address->street_number,
            'postal_code' => $address->postal_code,
            'city' => $address->city,
            'email' => $this->vendor->user->email,
            'terms' => '1',
            'credentials' => [
                'username' => $this->vendor->at_user,
                'password' => base64_encode($this->vendor->at_password),
                'context' => 'Sequences',
                'type' => 'AT',
            ],
        ];

        $response = $this->sendRequest('/api/accounts/'.$this->vendor->invoice_account_id.'/update.json', 'POST', ['account' => $payload]);

        if (isset($response['errors'])) {
            if ($response['errors'][0]['error'] === 'Credentials are wrong.') {
                throw new VendorInvalidAtCredentials;
            }
            throw new \Exception($response['errors'][0]['error']);
        }

        return $response;
    }

    public function createServiceInvoice(Service $service)
    {
        $user = $service->customer;

        $address = $user->billingInfo;

        if ($address === null) {
            if (is_array($service->address)) {
                $address['address'] = $service->address['street_name'].' '.$service->address['street_number'];
                $address['postal_code'] = $service->address['postal_code'];
                // `state` é o distrito; a localidade da fatura é a cidade. O ramo
                // de objeto (abaixo) e o updateFiscalDetails já usam city — este era
                // o único caminho a preencher o campo "city" da fatura com o distrito.
                $address['locality'] = $service->address['city'] ?? null;
            } else {
                $address['address'] = $service->address?->street_name.' '.$service->address?->street_number;
                $address['postal_code'] = $service->address?->postal_code;
                $address['locality'] = $service->address?->city; // `locality` é a chave que o prepareClientData mapeia para "city" na
                // fatura; o modelo Address NÃO tem coluna `locality` (tem city,
                // municipality e state), por isso isto chegava sempre a null e as
                // faturas saíam sem localidade.
            }

        } else {
            $address = $address->toArray();
        }

        $nif = $service->nif ?? $user->nif;
        $customer = $this->prepareClientData($user->name, $user->email, $nif, $address);

        $amount = $service->amount;

        $payload = $this->generateInvoicePayload($service->created_at, $service->created_at, $service->id, $customer, [$this->item('Serviço: '.$service->serviceType->getTranslation('name', 'pt-pt'), $amount)]);
        $response = $this->sendRequest('/invoice_receipts.json', 'POST', $payload);
        $service->invoice_id = $response['invoice_receipt']['id'];
        $service->save();

        return $response['invoice_receipt']['id'];

    }

    public function createAtCommunications(): bool
    {
        $data = [
            'at_subuser' => $this->vendor->at_user,
            'at_password' => base64_encode($this->vendor->at_password),
            'communication_type' => 'auto',
        ];

        $response = $this->sendRequest('/api/v3/accounts/at_communication.json', 'POST', ['at_communication' => $data]);

        if (! ($response['success'] ?? false)) {
            $error = is_array($response['errors'] ?? null)
                ? implode(', ', $response['errors'])
                : ($response['errors'] ?? json_encode($response));
            throw new \Exception('AT communication failed: '.$error);
        }

        return true;
    }

    public function createSequence(): array
    {
        $sequence = sprintf(config('services.invoiceExpress.sequences'), now()->format('y'), $this->vendor->id);

        $data = [
            'serie' => $sequence,
            'default_sequence' => '1',
        ];

        $response = $this->sendRequest('/sequences.json', 'POST', ['sequence' => $data]);

        if (isset($response['errors'])) {
            $errorKey = is_array($response['errors']) && isset($response['errors']['error'])
                ? $response['errors']['error']
                : (is_string($response['errors']) ? $response['errors'] : '');
            $errorMessage = is_array($response['errors']) && isset($response['errors']['message'])
                ? $response['errors']['message']
                : (is_string($response['errors']) ? $response['errors'] : json_encode($response['errors']));

            if ($errorKey === 'Invalid API key') {
                throw new \Exception($errorMessage);
            }
            if ($errorMessage === 'Já existe uma série com este nome.') {
                throw new VendorDuplicatedSequence;
            }
            if ($errorMessage === 'As credenciais associadas à conta não são válidas.') {
                $this->vendor->at_valid = false;
                $this->vendor->save();

                throw new VendorInvalidAtCredentials;
            }
            throw new \Exception($errorMessage);
        }

        return $response;
    }
}
