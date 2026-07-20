<?php

namespace App\Filament\Resources\GeneralSettings\OperationAreaResource\Pages;

use App\Filament\Resources\GeneralSettings\OperationAreaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOperationAreas extends ListRecords
{
    protected static string $resource = OperationAreaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->slideOver(),
        ];
    }
}
