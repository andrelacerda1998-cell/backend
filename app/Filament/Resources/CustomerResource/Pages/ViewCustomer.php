<?php

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Filament\Actions\Infolist\GenerateImpersonationCodeAction;
use App\Filament\Actions\Infolist\PasswordResetAction;
use App\Filament\Resources\CustomerResource;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            ActionGroup::make([
                PasswordResetAction::make('passwordReset')->color('gray')->icon(null),
                GenerateImpersonationCodeAction::make('generateImpersonationCode')->color('gray')->icon(null),
            ]),
        ];
    }
}
