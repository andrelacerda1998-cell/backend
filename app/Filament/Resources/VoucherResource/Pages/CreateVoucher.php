<?php

namespace App\Filament\Resources\VoucherResource\Pages;

use App\Filament\Resources\VoucherResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateVoucher extends CreateRecord
{
    protected static string $resource = VoucherResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['valid_services']) || !is_array($data['valid_services']) || count($data['valid_services']) === 0) {
            throw ValidationException::withMessages([
                'valid_services' => 'Deve selecionar pelo menos um tipo de serviço (Agendado ou Imediato).',
            ]);
        }

        return $data;
    }
}

