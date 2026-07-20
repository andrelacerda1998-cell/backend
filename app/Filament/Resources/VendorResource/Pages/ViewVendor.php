<?php

namespace App\Filament\Resources\VendorResource\Pages;

use App\Filament\Actions\Infolist\GenerateImpersonationCodeAction;
use App\Filament\Actions\Infolist\PasswordResetAction;
use App\Filament\Actions\Infolist\TestNotification;
use App\Filament\Actions\Infolist\ToggleVendorAutoAcceptAction;
use App\Filament\Actions\Infolist\ToggleVendorStatusAction;
use App\Filament\Actions\Infolist\ValidateAtCredentialsAction;
use App\Filament\Resources\VendorResource;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewVendor extends ViewRecord
{
    protected static string $resource = VendorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            ToggleVendorStatusAction::make('toggleVendorStatus'),
            ToggleVendorAutoAcceptAction::make('toggleVendorAutoAccept'),
            ValidateAtCredentialsAction::make('validateAtCredentials'),
            ActionGroup::make([
                PasswordResetAction::make('passwordReset')->color('gray')->icon(null),
                GenerateImpersonationCodeAction::make('generateImpersonationCode')->color('gray')->icon(null),
                TestNotification::make('Test Notification')->color('gray')->icon(null),
            ]),
        ];
    }
}
