<?php

namespace App\Filament\Infolists\Sections;

use App\Filament\Infolists\TextEntries\VendorContactTextEntry;
use App\Models\Vendor;
use Filament\Infolists\Components\Section;

class VendorContactsSection
{
    public static function make(): Section
    {
        return Section::make(__('backoffice/customer.form.contacts'))
            ->extraAttributes(['class' => 'h-full'])
            ->columnSpan(1)
            ->relationship('user')
            ->icon(fn(Vendor $record) => isset($record->user->email_verified_at) && isset($record->user->phone_number_verified_at) ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-circle')
            ->iconColor(fn(Vendor $record) => isset($record->user->email_verified_at) && isset($record->user->phone_number_verified_at) ? 'success' : 'danger')
            ->schema([
                VendorContactTextEntry::make('email', __('backoffice/vendor.infolist.email')),
                VendorContactTextEntry::make('phone_number', __('backoffice/vendor.infolist.phone_number')),
            ]);
    }
}
