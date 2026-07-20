<?php

namespace App\Filament\Resources\VoucherResource\Pages;

use App\Filament\Resources\VoucherResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditVoucher extends EditRecord
{
    protected static string $resource = VoucherResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (empty($data['valid_services']) || !is_array($data['valid_services']) || count($data['valid_services']) === 0) {
            throw ValidationException::withMessages([
                'valid_services' => 'Deve selecionar pelo menos um tipo de serviço (Agendado ou Imediato).',
            ]);
        }

        return $data;
    }
}

