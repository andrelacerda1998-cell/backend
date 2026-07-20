<?php

namespace App\Enums\Services;

use Filament\Support\Contracts\HasLabel;

enum AddressType: string implements HasLabel
{
    case HOUSE_ADDRESS = 'house_address';
    case FISCAL_ADDRESS = 'fiscal_address';
    case SCHEDULE_ADDRESS = 'schedule_address';

    public function getLabel(): string
    {
        return __('backoffice/address.form.address_type.'.$this->value);
    }
}
