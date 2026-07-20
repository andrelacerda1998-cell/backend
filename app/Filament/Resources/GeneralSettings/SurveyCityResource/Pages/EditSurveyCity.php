<?php

namespace App\Filament\Resources\GeneralSettings\SurveyCityResource\Pages;

use App\Filament\Resources\GeneralSettings\SurveyCityResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSurveyCity extends EditRecord
{
    protected static string $resource = SurveyCityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
