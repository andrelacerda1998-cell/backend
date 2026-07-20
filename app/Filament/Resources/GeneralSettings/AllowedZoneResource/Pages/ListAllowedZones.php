<?php

namespace App\Filament\Resources\GeneralSettings\AllowedZoneResource\Pages;

use App\Filament\Resources\GeneralSettings\AllowedZoneResource;
use App\Models\GeneralSettings\AllowedZone;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAllowedZones extends ListRecords
{
    protected static string $resource = AllowedZoneResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Actions\CreateAction::make()
        ];
    }

}
