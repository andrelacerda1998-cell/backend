<?php

namespace App\Filament\Infolists\Sections;

use App\Models\User;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;

class CustomerEligibilitySection
{
    public static function make(): Section
    {
        return Section::make(__('backoffice/customer.infolist.eligibility.title'))
            ->icon(fn (User $record) => $record->can_request_service ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-circle')
            ->iconColor(fn (User $record) => $record->can_request_service ? 'success' : 'danger')
            ->schema([
                TextEntry::make('eligibility_ok')
                    ->hiddenLabel()
                    ->state(__('backoffice/customer.infolist.eligibility.can_request'))
                    ->icon('heroicon-c-check-circle')
                    ->iconColor('success')
                    ->color('success')
                    ->visible(fn (User $record) => (bool) $record->can_request_service),
                TextEntry::make('eligibility_reasons')
                    ->hiddenLabel()
                    ->state(fn (User $record) => $record->cannotRequestServiceReasons())
                    ->listWithLineBreaks()
                    ->icon('heroicon-o-exclamation-triangle')
                    ->iconColor('warning')
                    ->color('warning')
                    ->visible(fn (User $record) => ! $record->can_request_service),
            ]);
    }
}
