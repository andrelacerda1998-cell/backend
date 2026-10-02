<?php

namespace App\Services\InvoiceXpress;

use App\Enums\Services\AddressType;
use App\Exceptions\Api\Vendor\Invoicing\VendorInvalidAtCredentials;
use App\Exceptions\Api\Vendor\VendorDuplicatedSequence;
use App\Models\Service;
use App\Models\ServiceExtra;
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

        /**
         * `serviceType` PODE SER NULL — um pedido PERSONALIZADO não tem tipo de
         * serviço (`services_type_id` é null de propósito).
         *
         * Sem o `?->` isto era `getTranslation()` sobre null: Error fatal, e o
         * `CreateInvoiceJob` tem `tries = 1`. A fatura de um serviço
         * personalizado nunca chegava a ser emitida, e nada o dizia -- o job
         * morria em silêncio. O que o cliente descreveu é o nome que faz
         * sentido na fatura.
         */
        $payload = $this->generateInvoicePayload(
            $service->created_at,
            $service->created_at,
            $service->id,
            $customer,
            $this->linhasDaFatura($service, $amount),
        );
        $response = $this->sendRequest('/invoice_receipts.json', 'POST', $payload);
        $service->invoice_id = $response['invoice_receipt']['id'];
        $service->save();

        return $response['invoice_receipt']['id'];

    }

    /**
     * As linhas da fatura: o serviço base MAIS os extras que o cliente pagou.
     *
     * OS EXTRAS FALTAVAM. A fatura levava uma linha só, com `$service->amount`,
     * e nada soma os extras a esse valor. Mas o extra é COBRADO AO CLIENTE NA
     * APROVAÇÃO (captura imediata): num serviço de 60 € com uma peça de 50 € e
     * meia hora extra de 15 €, o cliente pagava 125 € e a fatura dizia 60 €.
     * Sessenta e cinco euros recebidos sem documento fiscal, emitidos sob o NIF
     * do técnico.
     *
     * O predicado é o MESMO que o `CloseService::settleExtras` usa para creditar
     * o técnico (`isCharged()`): assim o que se fatura é exactamente o que
     * entrou e foi pago a alguém. Divergirem era garantir que um dia não batiam
     * certo.
     *
     * IVA: 23% em tudo (decisão do André, 02/10/2026). A taxa é do documento
     * inteiro (`generateInvoicePayload`), por isso as linhas novas herdam-na
     * sem conta nenhuma à parte.
     */
    private function linhasDaFatura(Service $service, int $amount): array
    {
        // Um pedido PERSONALIZADO não tem tipo de serviço (`services_type_id`
        // null de propósito). Sem o `?->` isto era uma chamada de método sobre
        // null -- Error fatal -- e o `CreateInvoiceJob` tem `tries = 1`: a
        // fatura nunca saía e o job morria em silêncio.
        $descricao = $service->serviceType?->getTranslation('name', 'pt-pt')
            ?: ($service->custom_description ?: 'Serviço');

        $linhas = [$this->item('Serviço: '.$descricao, $amount)];

        foreach ($service->extras()->where('status', 'approved')->get() as $extra) {
            // Zero euros não é uma linha de fatura, e o que não foi cobrado não
            // se fatura.
            if (! $extra->isCharged() || (int) $extra->amount <= 0) {
                continue;
            }

            $linhas[] = $this->item($this->descricaoDoExtra($extra), (int) $extra->amount);
        }

        return $linhas;
    }

    /** O que o cliente lê na fatura por baixo do serviço. */
    private function descricaoDoExtra(ServiceExtra $extra): string
    {
        if ($extra->type === 'part') {
            return 'Peça/material: '.($extra->description ?: 'sem descrição');
        }

        return 'Tempo extra: '.((int) $extra->minutes).' min';
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
