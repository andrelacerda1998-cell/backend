<?php

namespace App\Filament\Resources\GeneralSettings\GenderResource\Pages;

use App\Filament\Resources\GeneralSettings\GenderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListGenders extends ListRecords
{
    protected static string $resource = GenderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->slideOver()
                ->label(__('backoffice/gender.create_label'))
                ->modalHeading(__('backoffice/gender.create_label')),
        ];
    }
}
