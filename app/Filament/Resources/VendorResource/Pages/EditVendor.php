<?php

namespace App\Filament\Resources\VendorResource\Pages;

use App\Filament\Resources\VendorResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Hash;

class EditVendor extends EditRecord
{
    protected static string $resource = VendorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['user'] = $this->record->user->toArray();
        $data['user']['username'] = $this->record->username;

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->record->user()->update([
            'first_name' => $data['user']['first_name'],
            'last_name' => $data['user']['last_name'],
            'date_birthday' => $data['user']['date_birthday'],
            'email' => $data['user']['email'],
            'gender_id' => $data['user']['gender_id'],
            'phone_number' => $data['user']['phone_number'],
            'language' => 'PT',
            'nif' => $data['user']['nif'],
        ]);

        $this->record->update([
            'username' => $data['user']['username'],
            'company_name' => $data['company_name'],
            'iban' => $data['iban'],
        ]);

        return $data;
    }
}
