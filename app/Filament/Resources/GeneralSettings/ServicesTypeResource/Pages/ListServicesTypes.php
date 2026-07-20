<?php

namespace App\Filament\Resources\GeneralSettings\ServicesTypeResource\Pages;

use App\Filament\Resources\GeneralSettings\ServicesTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListServicesTypes extends ListRecords
{
    protected static string $resource = ServicesTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->slideOver(),
        ];
    }
}
