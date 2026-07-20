<?php

namespace App\Filament\Infolists\TextEntries;

use App\Models\Vendor;
use App\Notifications\Vendor\Documents\AcceptNotification;
use App\Notifications\Vendor\Documents\DenyNotification;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;

class VendorDocumentTextEntry
{
    public static function make(callable $name): TextEntry
    {
        return TextEntry::make('document')
            ->label($name)
            ->getStateUsing(fn (Vendor\VendorDocuments $record) => $record->getFirstTemporaryUrl(now()->addMinutes(5)) != null ? 'Download' : '-')
            ->url(fn (Vendor\VendorDocuments $record) => $record->getFirstTemporaryUrl(now()->addMinutes(5)), true)
            ->icon(function (Vendor\VendorDocuments $record, TextEntry $component) use ($name) {
                switch ($record->status) {
                    case 'approved':
                        if ($record->expiration_date && Carbon::parse($record->expiration_date)->isPast()) {
                            $component->iconColor('danger');
                            $component->tooltip(__('backoffice/vendor.infolist.field_expired', ['field' => $record->type?->name]));

                            return 'heroicon-c-exclamation-circle';
                        }

                        $component->iconColor('success');
                        $component->tooltip(__('backoffice/vendor.infolist.field_verified', ['field' => $record->type?->name]));

                        return 'heroicon-c-check-circle';
                    case 'declined':
                        $component->iconColor('danger');
                        $component->tooltip(__('backoffice/vendor.infolist.field_refused', ['field' => $record->type?->name]));

                        return 'heroicon-c-x-circle';
                    case 'pending':
                        $component->iconColor('warning');
                        $component->tooltip(__('backoffice/vendor.infolist.field_pending', ['field' => $record->type?->name]));

                        return 'heroicon-c-exclamation-circle';
                    default:
                        return null;
                }
            })
            ->hint(fn (Vendor\VendorDocuments $record) => __('backoffice/vendor.infolist.documents.expire_in', [
                'date' => $record->expiration_date ? $record->expiration_date : '-',
            ]))
            ->hintActions([
                Action::make(__('backoffice/vendor.infolist.documents.verify'))
                    ->color('success')
                    ->button()
                    ->hidden(fn (Vendor\VendorDocuments $record) => $record?->getFirstMediaUrl() == null || $record?->status != 'pending')
                    ->icon('heroicon-o-check-circle')
                    ->requiresConfirmation()
                    ->modalIcon('heroicon-o-check-circle')
                    ->form([
                        DatePicker::make('expiration_date')
                            ->label(__('backoffice/vendor.infolist.documents.expiration_date')),
                    ])
                    ->action(function (Vendor\VendorDocuments $record, array $data) use ($name) {
                        $record->update(['status' => 'approved', 'expiration_date' => $data['expiration_date'] ?? null]);
                        $record->vendor->user->notify(new AcceptNotification($record));
                    }),
                Action::make(__('backoffice/vendor.infolist.documents.decline'))
                    ->color('danger')
                    ->button()
                    ->hidden(fn (Vendor\VendorDocuments $record) => $record?->getFirstMediaUrl() == null || $record?->status != 'pending')
                    ->icon('heroicon-o-x-circle')
                    ->requiresConfirmation()
                    ->modalIcon('heroicon-o-x-circle')
                    ->modalDescription(__('backoffice/vendor.infolist.documents.decline_justify'))
                    ->form([
                        Textarea::make('reason')
                            ->label(__('backoffice/vendor.infolist.documents.reason'))
                            ->required()
                            ->rows(4),
                    ])
                    ->action(function (Vendor\VendorDocuments $record, array $data) use ($name) {
                        $record->update(['status' => 'declined', 'reason' => $data['reason']]);
                        $record->vendor->user->notify(new DenyNotification($record));

                        Notification::make()->title('Document declined successfully')->success()->send();
                    }),
            ]);
    }
}
