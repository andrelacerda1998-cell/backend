<?php

namespace App\Filament\Resources\AdminsResource\Pages;

use App\Filament\Resources\AdminsResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateAdmins extends CreateRecord
{
    protected static string $resource = AdminsResource::class;

    protected function getHeaderActions(): array
    {
        return [

        ];
    }

    protected function handleRecordCreation(array $data): Model
    {
        $record = parent::handleRecordCreation($data);

        $record->assignRole('admin');

        return $record;
    }
}
