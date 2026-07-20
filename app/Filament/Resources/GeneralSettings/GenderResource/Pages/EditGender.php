<?php

namespace App\Filament\Resources\GeneralSettings\GenderResource\Pages;

use App\Filament\Resources\GeneralSettings\GenderResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditGender extends EditRecord
{
    protected static string $resource = GenderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
