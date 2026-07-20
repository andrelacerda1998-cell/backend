<?php

namespace App\Filament\Resources\GeneralSettings\DocumentResource\Pages;

use App\Filament\Resources\GeneralSettings\DocumentResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDocument extends CreateRecord
{
    protected static string $resource = DocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [

        ];
    }
}
