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
    /**
     * Porque é que ainda não se pode criar o workspace, em português de admin.
     *
     * A REGRA vive no modelo (`Vendor::invoicingBlocker()`), porque a app do
     * técnico precisa da mesma e duas cópias divergiriam à primeira alteração.
     * Aqui fica só a tradução para quem está no backoffice -- que fala do
     * técnico na terceira pessoa, ao contrário da app, que fala com ele.
     */
    public static function getWorkspaceDisabledReason(Vendor $record): ?string
    {
        // Caso que só o backoffice tem: o botão está desativado porque já foi
        // criado. Para o técnico isso não é bloqueio nenhum -- é estar pronto.
        if ($record->invoice_workspace !== '' && $record->invoice_workspace !== null) {
            return __('backoffice/vendor.infolist.workspace_disabled_already_exists');
        }

        return match ($record->invoicingBlocker()) {
            'contact_unverified' => __('backoffice/vendor.infolist.workspace_disabled_no_verification'),
            'documents_pending' => __('backoffice/vendor.infolist.workspace_disabled_documents'),
            'iban_missing' => __('backoffice/vendor.infolist.workspace_disabled_iban'),
            'fiscal_address_missing' => __('backoffice/vendor.infolist.workspace_disabled_fiscal_address'),
            default => null,
        };
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
