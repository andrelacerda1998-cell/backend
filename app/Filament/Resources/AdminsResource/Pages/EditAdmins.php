<?php

namespace App\Filament\Resources\AdminsResource\Pages;

use App\Filament\Actions\Infolist\PasswordResetAction;
use App\Filament\Resources\AdminsResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAdmins extends EditRecord
{
    protected static string $resource = AdminsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            PasswordResetAction::make('passwordReset')
        ];
    }
}
