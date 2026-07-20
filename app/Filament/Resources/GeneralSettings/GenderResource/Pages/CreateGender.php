<?php

namespace App\Filament\Resources\GeneralSettings\GenderResource\Pages;

use App\Filament\Resources\GeneralSettings\GenderResource;
use Filament\Resources\Pages\CreateRecord;

class CreateGender extends CreateRecord
{
    protected static string $resource = GenderResource::class;

    protected function getHeaderActions(): array
    {
        return [

        ];
    }
}
