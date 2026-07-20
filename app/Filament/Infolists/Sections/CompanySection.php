<?php

namespace App\Filament\Infolists\Sections;

use App\Enums\Services\AddressType;
use App\Models\Vendor;
use App\Services\InvoiceXpress\SystemInvoiceService;
use Filament\Infolists\Components\Actions\Action;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;

class CompanySection
{
    public static function getWorkspaceDisabledReason(Vendor $record): ?string
    {
        if (! $record->user->hasVerifiedEmail() && ! $record->user->hasVerifiedPhoneNumber()) {
            return __('backoffice/vendor.infolist.workspace_disabled_no_verification');
        }

        if (! $record->all_documents_verified) {
            return __('backoffice/vendor.infolist.workspace_disabled_documents');
        }

        if (! $record->iban) {
            return __('backoffice/vendor.infolist.workspace_disabled_iban');
        }

        if ($record->invoice_workspace !== '') {
            return __('backoffice/vendor.infolist.workspace_disabled_already_exists');
        }

        if (! $record->addresses()->where('address_type', AddressType::FISCAL_ADDRESS)->exists()) {
            return __('backoffice/vendor.infolist.workspace_disabled_fiscal_address');
        }

        return null;
    }

    public static function shouldShowWorkspaceDisabledReason(Vendor $record): bool
    {
        $reason = self::getWorkspaceDisabledReason($record);

        return $reason !== null
            && $reason !== __('backoffice/vendor.infolist.workspace_disabled_already_exists');
    }

    public static function make(): Section
    {
        return Section::make(__('backoffice/vendor.infolist.company_section'))
            ->extraAttributes(['class' => 'h-full'])
            ->columnSpan(1)
            ->headerActions([
                Action::make(__('backoffice/vendor.infolist.create_invoice_workspace'))
                    ->button()
                    ->requiresConfirmation()
                    ->disabled(fn (Vendor $record) => self::getWorkspaceDisabledReason($record) !== null)
                    ->label(__('backoffice/vendor.infolist.create_invoice_workspace'))
                    ->action(function (Vendor $record) {
                        try {
                            $systemInvoiceService = new SystemInvoiceService;
                            $systemInvoiceService->createWorkspace($record);

                            $record->refresh();

                            Notification::make()
                                ->title(__('backoffice/vendor.infolist.workspace_created'))
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title(__('backoffice/vendor.infolist.workspace_error'))
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('markAtValid')
                    ->button()
                    ->color('warning')
                    ->icon('heroicon-o-shield-check')
                    ->label(__('backoffice/vendor.infolist.force_at_valid'))
                    ->requiresConfirmation()
                    ->modalHeading(__('backoffice/vendor.infolist.force_at_valid'))
                    ->modalDescription(__('backoffice/vendor.infolist.force_at_valid_description'))
                    ->authorize(fn () => auth()->user()?->hasRole('super-admin') ?? false)
                    ->disabled(fn (Vendor $record) => (bool) $record->at_valid)
                    ->action(function (Vendor $record) {
                        $record->at_valid = true;
                        $record->at_validated_at = now();
                        $record->save();

                        $record->refresh();

                        Notification::make()
                            ->title(__('backoffice/vendor.infolist.force_at_valid_success'))
                            ->success()
                            ->send();
                    }),
            ])
            ->schema([
                TextEntry::make('workspace_disabled_reason')
                    ->label('')
                    ->hiddenLabel()
                    ->state(fn (Vendor $record) => self::getWorkspaceDisabledReason($record))
                    ->visible(fn (Vendor $record) => self::shouldShowWorkspaceDisabledReason($record))
                    ->icon('heroicon-o-exclamation-triangle')
                    ->iconColor('warning')
                    ->color('warning')
                    ->columnSpanFull(),
                TextEntry::make('invoice_workspace')
                    ->label(__('backoffice/vendor.infolist.invoice_workspace')),
                TextEntry::make('iban')
                    ->label(__('backoffice/vendor.infolist.iban'))
                    ->formatStateUsing(function ($state) {
                        return preg_replace('/(\w{4})(?=\w)/', '$1 ', $state);
                    })
                    ->copyable(),
                TextEntry::make('company_name')
                    ->label(__('backoffice/vendor.infolist.company_name')),
                TextEntry::make('at_user')
                    ->label(__('backoffice/vendor.infolist.at_user')),
                TextEntry::make('at_valid')
                    ->label('AT Válido')
                    ->formatStateUsing(fn ($state) => $state ? 'Sim' : 'Não')
                    ->badge()
                    ->color(fn ($state) => $state ? 'success' : 'danger'),
                TextEntry::make('at_validated_at')
                    ->label(fn (Vendor $record) => $record->at_valid ? 'AT Validado em' : 'Última vez OK')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('-'),
            ]);
    }
}
