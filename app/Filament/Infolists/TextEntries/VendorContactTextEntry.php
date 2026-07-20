<?php

namespace App\Filament\Infolists\TextEntries;

use App\Models\Vendor;
use Filament\Infolists\Components\Actions\Action;
use Filament\Infolists\Components\TextEntry;

class VendorContactTextEntry
{
    public static function make(string $name, string $label): TextEntry
    {
        return TextEntry::make($name)
            ->label($label)
            ->icon(function (Vendor $record, TextEntry $component) use ($name, $label) {
                if ($name == 'email' ? !$record->user->hasVerifiedEmail() : !$record->user->hasVerifiedPhoneNumber()) {
                    $component->iconColor('warning');
                    $component->tooltip(__('backoffice/vendor.infolist.field_not_verified', ['field' => $label]));

                    return 'heroicon-c-exclamation-circle';
                } else {
                    $component->iconColor('success');
                    $component->tooltip(__('backoffice/vendor.infolist.field_verified', ['field' => $label]));

                    return 'heroicon-c-check-circle';
                }
            })->hintAction(Action::make('Verify')
                ->color('success')
                ->button()
                ->hidden(fn(Vendor $record) => $name == 'email' ? $record->user->hasVerifiedEmail() : $record->user->hasVerifiedPhoneNumber())
                ->requiresConfirmation()
                ->icon('heroicon-o-check-circle')
                ->action(fn(Vendor $record) => $record->user->update([$name == 'email' ? 'email_verified_at' : 'phone_number_verified_at' => now()])))
            ->copyable();
    }
}
