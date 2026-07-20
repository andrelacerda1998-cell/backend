<?php

namespace App\Services\InvoiceXpress\Contracts;

use App\Models\User\UserBillingInfo;
use Carbon\Carbon;

trait HasInvoicesItems
{
    private const CURRENCY_CODE = 'EUR';
    private const UNIT_TYPE = 'service';

    private function calculateVatRate(): float
    {
        $vat = config('services.invoiceExpress.vat');
        return 1 + ($vat / 100);
    }

    private function generateInvoicePayload(Carbon $date, Carbon $dueDate, $serviceId, array $customer, array $items): array
    {
        return [
            'invoice' => [
                "date" => $date->format('d-m-Y'),
                "due_date" => $dueDate->format('d-m-Y'),
                "reference" => $serviceId,
                "client" => $customer,
                "items" => $items,
                "currency_code" => self::CURRENCY_CODE,
                "rate" => strval($this->calculateVatRate())
            ]
        ];
    }

    private function prepareClientData($name, $email, $fiscalId = null, array $address = null): array
    {
        return [
            "name" => $name,
            "email" => $email,
            'fiscal_id' => !$fiscalId ?999999990: $fiscalId,

            "address" => $address['address']??'',
            "city" => $address['locality']??'',
            "postal_code" => $address['postal_code']??'',
            "country" => "Portugal",
        ];
    }

    public function item($itemDescription, $itemPrice)
    {
        return [
            "name" => config('services.invoiceExpress.product_name'),
            "description" => $itemDescription,
            "unit_price" => ($itemPrice / 100) / $this->calculateVatRate(),
            "quantity" => 1,
            "unit" => self::UNIT_TYPE
        ];
    }
}
