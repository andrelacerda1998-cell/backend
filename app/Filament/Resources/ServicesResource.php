<?php

namespace App\Filament\Resources;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Filament\Resources\ServicesResource\Pages;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Service;
use App\Services\Common\Services\CloseService;
use Exception;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Mokhosh\FilamentRating\Columns\RatingColumn;
use Mokhosh\FilamentRating\Entries\RatingEntry;
use Tapp\FilamentAuditing\RelationManagers\AuditsRelationManager;
use Tapp\FilamentValueRangeFilter\Filters\ValueRangeFilter;

class ServicesResource extends Resource
{
    protected static ?string $model = Service::class;

    protected static ?string $slug = 'services';

    protected static ?string $navigationIcon = 'heroicon-o-cog';

    /**
     * @throws Exception
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('customer.name')
                    ->label(__('backoffice/service.table.customer'))
                    ->url(fn (Service $record): string => CustomerResource::getUrl('view', ['record' => $record->customer_id]))
                    ->color('primary')
                    ->hiddenOn(CustomerResource\RelationManagers\ServicesRelationManager::class)
                    ->searchable(),
                TextColumn::make('vendor.user.name')
                    ->label(__('backoffice/service.table.vendor'))
                    ->searchable()
                    ->url(fn (Service $record): string => VendorResource::getUrl('view', ['record' => $record->vendor_id]))
                    ->color('primary')
                    ->hiddenOn(VendorResource\RelationManagers\ServicesRelationManager::class)
                    ->label('Vendor'),
                TextColumn::make('schedule')
                    ->label('Type')
                    ->getStateUsing(fn (Service $record) => $record->schedule_exists ? __('backoffice/service.table.scheduled') : __('backoffice/service.table.immediate')),
                TextColumn::make('status')
                    ->label(__('backoffice/service.table.status'))
                    ->tooltip(fn (Service $record): string => $record->status_justification ?? '')
                    ->badge()
                    ->formatStateUsing(fn (ServiceStatus $state) => __("$state->value"))
                    ->color(fn (ServiceStatus $state): string => match ($state) {
                        ServiceStatus::PENDING, ServiceStatus::CLOSED_PENDING_PAYMENT => 'warning',
                        ServiceStatus::CANCELED, ServiceStatus::REFUSED, ServiceStatus::REFUSED_MBWAY => 'danger',
                        ServiceStatus::ACCEPTED, ServiceStatus::FINISHED, ServiceStatus::ARRIVED, ServiceStatus::CLOSED, ServiceStatus::SCHEDULED => 'success',
                        ServiceStatus::PENDING_3DS, ServiceStatus::ARCHIVED, ServiceStatus::EXPIRED_MBWAY, ServiceStatus::CANCELED_MBWAY => 'gray',
                    }),
                TextColumn::make('payment_status')
                    ->label(__('backoffice/service.table.payment_status'))
                    ->badge()
                    ->formatStateUsing(fn (PaymentStatus $state) => __("backoffice/service.infolist.payment_status_values.$state->value"))
                    ->color(fn (PaymentStatus $state): string => match ($state) {
                        PaymentStatus::PAID => 'success',
                        PaymentStatus::PENDING => 'warning',
                        PaymentStatus::REFUNDED => 'gray',
                        PaymentStatus::CANCELED => 'danger',
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('serviceType.name')
                    ->label(__('backoffice/service.table.service_type')),
                TextColumn::make('distance')
                    ->label(__('backoffice/service.table.distance'))
                    ->numeric(2)
                    ->suffix('KM'),
                RatingColumn::make('rating_by_customer')
                    ->label(__('backoffice/service.table.rating_by_customer'))
                    ->tooltip(fn (Service $record): string => $record->rating_by_customer ?? ''),
                RatingColumn::make('rating_by_vendor')
                    ->label(__('backoffice/service.table.rating_by_vendor'))
                    ->tooltip(fn (Service $record): string => $record->rating_by_vendor ?? ''),
                TextColumn::make('created_at')
                    ->label(__('backoffice/service.table.created_at'))
                    ->since(),
                TextColumn::make('amount')
                    ->label(__('backoffice/service.table.amount_without_vat'))
                    ->formatStateUsing(function (Service $record) {
                        $withVat = $record->amount ? number_format($record->amount / 100, 2, ',', ' ').'€' : '-';
                        $withoutVat = $record->amount_without_vat ? number_format($record->amount_without_vat / 100, 2, ',', ' ').'€' : '-';

                        return $withVat.' | '.$withoutVat;
                    })
                    ->sortable(query: function ($query, string $direction): Builder {
                        return $query->orderBy('amount', $direction);
                    }),
                TextColumn::make('commission_with_vat')
                    ->label(__('backoffice/service.table.commission_without_vat'))
                    ->formatStateUsing(function (Service $record) {
                        $withVat = $record->commission_with_vat ? number_format($record->commission_with_vat / 100, 2, ',', ' ').'€' : '-';
                        $withoutVat = $record->commission_without_vat ? number_format($record->commission_without_vat / 100, 2, ',', ' ').'€' : '-';

                        return $withVat.' | '.$withoutVat;
                    })
                    ->sortable(query: function ($query, string $direction): Builder {
                        return $query->orderByRaw("(amount - amount_for_vendor) $direction");
                    }),
                IconColumn::make('is_test')
                    ->label('Test')
                    ->boolean()
                    ->trueColor('warning')
                    ->falseColor('gray'),
                TextColumn::make('voucher.name')
                    ->label('Voucher')
                    ->badge()
                    ->color('success')
                    ->default('-'),
            ])
            ->filters([
                SelectFilter::make('schedule')
                    ->options([
                        'scheduled' => __('backoffice/service.table.scheduled'),
                        'immediate' => __('backoffice/service.table.immediate'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if ($data['value'] === 'scheduled') {
                            return $query->whereHas('schedule');
                        }
                        if ($data['value'] === 'immediate') {
                            return $query->whereDoesntHave('schedule');
                        }

                        return $query;
                    }),
                SelectFilter::make('status')
                    ->multiple()
                    ->options(ServiceStatus::class),
                SelectFilter::make('serviceType')
                    ->multiple()
                    ->relationship('serviceType', 'name', fn ($query) => $query->withTrashed())
                    ->getOptionLabelFromRecordUsing(fn (ServicesType $record) => $record->name ?? '')
                    ->preload(),
                SelectFilter::make('customer')
                    ->multiple()
                    ->relationship('customer', 'name', fn ($query) => $query->withTrashed()->whereDoesntHave('vendor'))
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->name ?? '-')
                    ->preload(),
                SelectFilter::make('vendor')
                    ->multiple()
                    ->relationship('vendor.user', 'name', fn ($query) => $query->withTrashed()->whereHas('vendor'))
                    ->preload(),
                ValueRangeFilter::make('rating_by_customer'),
                ValueRangeFilter::make('rating_by_vendor'),
            ])
            ->actions([
                ViewAction::make()->url(fn (Service $record): string => static::getUrl('view', ['record' => $record])),
                Action::make('retryCapture')
                    ->label(__('backoffice/service.actions.retry_capture'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->authorize(fn (): bool => auth()->user()?->hasRole('super-admin') ?? false)
                    ->visible(fn (Service $record): bool => $record->status === ServiceStatus::CLOSED_PENDING_PAYMENT)
                    ->action(function (Service $record): void {
                        try {
                            (new CloseService($record))->retryCapture();

                            Notification::make()
                                ->title(__('backoffice/service.actions.retry_capture_success'))
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title(__('backoffice/service.actions.retry_capture_failed'))
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('abandonAndRefund')
                    ->label(__('backoffice/service.actions.abandon_and_refund'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->authorize(fn (): bool => auth()->user()?->hasRole('super-admin') ?? false)
                    ->visible(fn (Service $record): bool => $record->status === ServiceStatus::CLOSED_PENDING_PAYMENT)
                    ->action(function (Service $record): void {
                        try {
                            (new CloseService($record))->abandonAndRefund();

                            Notification::make()
                                ->title(__('backoffice/service.actions.abandon_and_refund_success'))
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title(__('backoffice/service.actions.abandon_and_refund_failed'))
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->bulkActions([

            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Details')
                ->heading(__('backoffice/service.infolist.details'))
                ->columns(3)
                ->schema([
                    TextEntry::make('customer.name')
                        ->label(__('backoffice/service.infolist.customer')),
                    TextEntry::make('vendor.user.name')
                        ->label(__('backoffice/service.infolist.vendor')),
                    TextEntry::make('serviceType.name')
                        ->label(__('backoffice/service.infolist.service_type')),
                    TextEntry::make('status')
                        ->label(__('backoffice/service.infolist.status'))
                        ->badge()
                        ->formatStateUsing(fn (ServiceStatus $state) => __("$state->value"))
                        ->color(fn (ServiceStatus $state): string => match ($state) {
                            ServiceStatus::PENDING => 'warning',
                            ServiceStatus::CANCELED, ServiceStatus::REFUSED, ServiceStatus::REFUSED_MBWAY => 'danger',
                            ServiceStatus::ACCEPTED, ServiceStatus::FINISHED, ServiceStatus::ARRIVED, ServiceStatus::CLOSED, ServiceStatus::SCHEDULED => 'success',
                            ServiceStatus::PENDING_3DS, ServiceStatus::ARCHIVED, ServiceStatus::EXPIRED_MBWAY, ServiceStatus::CANCELED_MBWAY => 'gray',
                        }),
                    TextEntry::make('payment_status')
                        ->label(__('backoffice/service.infolist.payment_status'))
                        ->badge()
                        ->formatStateUsing(fn (PaymentStatus $state) => __("backoffice/service.infolist.payment_status_values.$state->value"))
                        ->color(fn (PaymentStatus $state): string => match ($state) {
                            PaymentStatus::PAID => 'success',
                            PaymentStatus::PENDING => 'warning',
                            PaymentStatus::REFUNDED => 'gray',
                            PaymentStatus::CANCELED => 'danger',
                        })
                        ->default('-'),
                    TextEntry::make('status_justification')
                        ->label(__('backoffice/service.infolist.status_justification'))
                        ->formatStateUsing(fn ($state) => $state != '' ? __($state) : '')
                        ->default('-'),
                    TextEntry::make('distance')
                        ->label(__('backoffice/service.infolist.distance'))
                        ->formatStateUsing(fn ($state) => number_format($state, 2).'KM'),
                    TextEntry::make('created_at')
                        ->label(__('backoffice/service.infolist.created_at'))
                        ->dateTime('d/m/Y H:i'),
                    TextEntry::make('nif')
                        ->label(__('backoffice/service.infolist.nif'))
                        ->default('-'),
                    TextEntry::make('price_rate')
                        ->label(__('backoffice/service.infolist.price_rate'))
                        ->default('-'),
                    IconEntry::make('is_test')
                        ->label(__('backoffice/service.infolist.is_test'))
                        ->boolean(),
                    TextEntry::make('schedule.scheduled_day')
                        ->label(__('backoffice/service.infolist.scheduled_day'))
                        ->default('-')
                        ->hidden(fn (Service $record): bool => ! $record->schedule),
                    TextEntry::make('schedule.scheduled_time_start')
                        ->label(__('backoffice/service.infolist.scheduled_time_start'))
                        ->default('-')
                        ->hidden(fn (Service $record): bool => ! $record->schedule),
                    TextEntry::make('schedule.scheduled_time_end')
                        ->label(__('backoffice/service.infolist.scheduled_time_end'))
                        ->default('-')
                        ->hidden(fn (Service $record): bool => ! $record->schedule),
                ]),
            /*
             * O que o cliente enviou com o pedido: descrição do problema e
             * fotografias.
             *
             * Secção própria, e logo a seguir aos detalhes, porque é informação
             * de OPERAÇÃO: quem atende uma reclamação ou despacha um serviço
             * precisa de ver o que foi pedido antes de tudo o resto. As notas
             * estavam arrumadas dentro de "Avaliações" — que é sobre o que se
             * achou do serviço depois de feito, e não sobre o que foi pedido
             * antes. Estavam no sítio onde ninguém as ia procurar.
             */
            Section::make('CustomerRequest')
                ->heading(__('backoffice/service.infolist.customer_request'))
                ->schema([
                    // Unidades: "2 × Reparação de torneira". Só aparece quando é
                    // mais do que uma — escrever "1 unidade" em todos os outros
                    // pedidos seria ruído em 95% dos casos.
                    TextEntry::make('quantity')
                        ->label(__('backoffice/service.infolist.quantity'))
                        ->formatStateUsing(fn ($state, Service $record): string => $state.' × '.($record->serviceType?->name ?? ''))
                        ->hidden(fn (Service $record): bool => (int) ($record->quantity ?? 1) <= 1),
                    TextEntry::make('customer_notes')
                        ->label(__('backoffice/service.infolist.customer_notes'))
                        ->placeholder(__('backoffice/service.infolist.customer_notes_empty'))
                        ->columnSpanFull(),
                    SpatieMediaLibraryImageEntry::make('customer_photos')
                        ->collection('customer')
                        ->label(__('backoffice/service.infolist.customer_photos'))
                        ->height(120)
                        ->columnSpanFull()
                        // Sem fotos a entrada some-se: uma grelha vazia num
                        // pedido sem fotografias só faria pensar que falharam.
                        ->hidden(fn (Service $record): bool => $record->getMedia('customer')->isEmpty()),
                ]),
            Section::make('Addresses')
                ->heading(__('backoffice/service.infolist.addresses'))
                ->columns(2)
                ->schema([
                    TextEntry::make('customer_address')
                        ->label(__('backoffice/service.infolist.customer_address'))
                        ->default('-')
                        ->state(fn (Service $record) => static::formatServiceAddress($record->address)),
                    TextEntry::make('customer_address_map')
                        ->label(__('backoffice/service.infolist.view_on_map'))
                        ->color('primary')
                        ->state(fn (Service $record) => static::coordinatesLabel($record->address['latitude'] ?? null, $record->address['longitude'] ?? null))
                        ->url(fn (Service $record) => static::mapsUrl($record->address['latitude'] ?? null, $record->address['longitude'] ?? null))
                        ->openUrlInNewTab()
                        ->visible(fn (Service $record) => filled($record->address['latitude'] ?? null) && filled($record->address['longitude'] ?? null)),
                    TextEntry::make('vendor_location')
                        ->label(__('backoffice/service.infolist.vendor_location'))
                        ->default('-')
                        ->state(fn (Service $record) => static::coordinatesLabel($record->vendor?->currentLocation?->latitude, $record->vendor?->currentLocation?->longitude)),
                    TextEntry::make('vendor_location_map')
                        ->label(__('backoffice/service.infolist.view_on_map'))
                        ->color('primary')
                        ->state(fn (Service $record) => static::coordinatesLabel($record->vendor?->currentLocation?->latitude, $record->vendor?->currentLocation?->longitude))
                        ->url(fn (Service $record) => static::mapsUrl($record->vendor?->currentLocation?->latitude, $record->vendor?->currentLocation?->longitude))
                        ->openUrlInNewTab()
                        ->visible(fn (Service $record) => filled($record->vendor?->currentLocation?->latitude) && filled($record->vendor?->currentLocation?->longitude)),
                ]),
            Section::make('Voucher')
                ->heading('Informação do Voucher')
                ->visible(fn (Service $record) => $record->voucher_id !== null)
                ->columns(3)
                ->schema([
                    TextEntry::make('voucher.name')
                        ->label('Voucher Aplicado')
                        ->badge()
                        ->color('success'),
                    TextEntry::make('original_amount')
                        ->label('Valor Inicial')
                        ->money('EUR', 100),
                    TextEntry::make('discount_amount')
                        ->label('Desconto Aplicado')
                        ->money('EUR', 100)
                        ->color('success'),
                    TextEntry::make('amount')
                        ->label('Valor Efetivamente Pago')
                        ->money('EUR', 100)
                        ->color('primary'),
                ]),
            Section::make('Totals')
                ->heading(__('backoffice/service.infolist.totals'))
                ->columns(4)
                ->schema([
                    TextEntry::make('amount')
                        ->label(__('backoffice/service.infolist.amount_with_vat'))
                        ->money('EUR', 100),
                    TextEntry::make('commission_with_vat')
                        ->label(__('backoffice/service.infolist.commission_with_vat'))
                        ->money('EUR', 100),
                    TextEntry::make('amount_for_vendor')
                        ->label(__('backoffice/service.infolist.amount_for_vendor'))
                        ->money('EUR', 100),
                    TextEntry::make('invoice_id')
                        ->label(__('backoffice/service.infolist.invoice_id'))
                        ->formatStateUsing(fn ($state) => $state != '' ? __('backoffice/service.infolist.invoice_created') : __('backoffice/service.infolist.invoice_not_created'))
                        ->default('')
                        ->color('primary')
                        ->url(fn (Service $record) => $record->getFirstTemporaryUrl(now()->addHour(), 'invoices'), true),
                    TextEntry::make('amount_without_vat')
                        ->label(__('backoffice/service.infolist.amount_without_vat'))
                        ->money('EUR', 100),
                    TextEntry::make('commission_without_vat')
                        ->label(__('backoffice/service.infolist.commission_without_vat'))
                        ->money('EUR', 100),
                    TextEntry::make('credit_used')
                        ->label(__('backoffice/service.infolist.credit_used'))
                        ->money('EUR', 100),
                    TextEntry::make('paymentOrder.uuid')
                        ->label(__('backoffice/service.infolist.payment_reference'))
                        ->copyable()
                        ->default('-'),
                    TextEntry::make('paymentOrder.type')
                        ->label(__('backoffice/service.infolist.payment_method'))
                        ->default('-'),
                    TextEntry::make('paymentOrder.refunded')
                        ->label(__('backoffice/service.infolist.refunded'))
                        ->money('EUR', 100)
                        ->default(0),
                ]),
            Section::make('Ratings')
                ->heading(__('backoffice/service.infolist.ratings'))
                ->columns()
                ->schema([
                    RatingEntry::make('rating_by_customer')
                        ->label(__('backoffice/service.infolist.rating_by_customer')),
                    RatingEntry::make('rating_by_vendor')
                        ->label(__('backoffice/service.infolist.rating_by_vendor')),
                    // customer_notes saiu daqui para a secção "Pedido do
                    // cliente": é o que foi pedido antes, não o que se achou
                    // depois. O vendor_notes fica, que é do técnico sobre a
                    // execução.
                    TextEntry::make('vendor_notes')
                        ->label(__('backoffice/service.infolist.vendor_notes')),
                ]),
        ]);
    }

    /**
     * Build a human-readable single-line address from the service's stored
     * `address` JSON snapshot (no geocoding — shows exactly what was saved).
     */
    protected static function formatServiceAddress(mixed $address): ?string
    {
        if (! is_array($address)) {
            return null;
        }

        $street = trim(implode(' ', array_filter([
            $address['street_name'] ?? null,
            $address['street_number'] ?? null,
        ])));

        $locality = trim(implode(' ', array_filter([
            $address['postal_code'] ?? null,
            $address['city'] ?? null,
        ])));

        $parts = array_filter([
            $address['name'] ?? null,
            $street,
            $address['additional_info'] ?? null,
            $locality,
            $address['state'] ?? null,
            $address['country'] ?? null,
        ], fn ($value) => filled($value));

        return $parts === [] ? null : implode(' · ', $parts);
    }

    protected static function coordinatesLabel(mixed $latitude, mixed $longitude): ?string
    {
        if (! filled($latitude) || ! filled($longitude)) {
            return null;
        }

        return $latitude.', '.$longitude;
    }

    protected static function mapsUrl(mixed $latitude, mixed $longitude): ?string
    {
        if (! filled($latitude) || ! filled($longitude)) {
            return null;
        }

        return 'https://www.google.com/maps/search/?api=1&query='.$latitude.','.$longitude;
    }

    public static function getRelations(): array
    {
        return [
            AuditsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListServices::route('/'),
            'view' => Pages\ViewService::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            // withExists evita a query schedule()->exists() por linha na coluna "Type".
            ->withExists('schedule')
            ->when(! auth()->user()->hasRole('developer'), fn ($q) => $q->where('is_test', false));
    }

    public static function getGloballySearchableAttributes(): array
    {
        return [];
    }

    public static function getModelLabel(): string
    {
        return __('backoffice/service.singular');
    }

    public static function getPluralLabel(): string
    {
        return __('backoffice/service.plural');
    }
}
