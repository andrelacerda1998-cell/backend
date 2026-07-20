<?php

namespace App\Filament\Resources\GeneralSettings\AllowedZoneResource\Pages;

use App\Filament\Resources\GeneralSettings\AllowedZoneResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAllowedZone extends EditRecord
{
    protected static string $resource = AllowedZoneResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
