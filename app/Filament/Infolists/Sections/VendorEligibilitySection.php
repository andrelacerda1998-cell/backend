<?php

namespace App\Filament\Infolists\Sections;

use App\Models\Vendor;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;

class VendorEligibilitySection
{
    public static function make(): Section
    {
        return Section::make(__('backoffice/vendor.infolist.eligibility.title'))
            ->icon(fn (Vendor $record) => $record->can_accept_service ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-circle')
            ->iconColor(fn (Vendor $record) => $record->can_accept_service ? 'success' : 'danger')
            ->schema([
                TextEntry::make('eligibility_ok')
                    ->hiddenLabel()
                    ->state(__('backoffice/vendor.infolist.eligibility.can_accept'))
                    ->icon('heroicon-c-check-circle')
                    ->iconColor('success')
                    ->color('success')
                    ->visible(fn (Vendor $record) => (bool) $record->can_accept_service),
                TextEntry::make('eligibility_reasons')
                    ->hiddenLabel()
                    ->state(fn (Vendor $record) => $record->cannotAcceptServiceReasons())
                    ->listWithLineBreaks()
                    ->icon('heroicon-o-exclamation-triangle')
                    ->iconColor('warning')
                    ->color('warning')
                    ->visible(fn (Vendor $record) => ! $record->can_accept_service),
            ]);
    }
}
