<?php

namespace App\Filament\Resources\GeneralSettings\SurveyCityResource\Pages;

use App\Filament\Resources\GeneralSettings\SurveyCityResource;
use Filament\Resources\Pages\ListRecords;

class ListSurveyCities extends ListRecords
{
    protected static string $resource = SurveyCityResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
