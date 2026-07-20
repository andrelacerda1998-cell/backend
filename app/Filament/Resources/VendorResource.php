<?php

namespace App\Filament\Resources;

use App\Enums\Vendors\StatusVendor;
use App\Filament\Exports\VendorExporter;
use App\Filament\Infolists\Sections\CompanySection;
use App\Filament\Infolists\Sections\VendorContactsSection;
use App\Filament\Infolists\Sections\VendorDocumentsSection;
use App\Filament\Infolists\Sections\VendorEligibilitySection;
use App\Filament\Infolists\Sections\VendorScheduleAddressSection;
use App\Filament\RelationManagers\User\AddressesRelationManager;
use App\Filament\RelationManagers\User\ImpersonationCodesRelationManager;
use App\Filament\RelationManagers\User\WalletRelationManager;
use App\Filament\Resources\VendorResource\Pages;
use App\Filament\Resources\VendorResource\RelationManagers\ServicesRelationManager;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Vendor;
use App\Rules\NifRule;
use App\Services\Customer\Services\VendorSearchService;
use Exception;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists\Components\Actions\Action;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\ExportAction;
use Filament\Tables\Actions\RestoreAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nembie\IbanRule\ValidIban;
use Tapp\FilamentAuditing\RelationManagers\AuditsRelationManager;

class VendorResource extends Resource
{
    protected static ?string $model = Vendor::class;

    protected static ?string $slug = 'vendors';

    protected static ?string $navigationIcon = 'heroicon-o-wrench';

    protected static ?string $recordTitleAttribute = 'user.name';

    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        return $record?->user?->name ?? '';
    }

    // Só o super-admin pode MUTAR vendors (o IBAN redireciona payouts — exposição direta de
    // dinheiro se um admin simples o editar). A leitura fica aberta ao staff `admin` para não
    // partir o workflow de gestão de vendors; apenas a edição/eliminação é restrita.
    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->hasRole('super-admin') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->hasRole('super-admin') ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                \Filament\Forms\Components\Section::make('Personal Data')
                    ->columns()
                    ->schema([
                        TextInput::make('user.first_name')
                            ->label(__('backoffice/vendor.form.first_name'))
                            ->required(),
                        TextInput::make('user.last_name')
                            ->label(__('backoffice/vendor.form.last_name'))
                            ->required(),
                        TextInput::make('user.username')
                            ->label(__('backoffice/vendor.form.username'))
                            ->required(),
                        TextInput::make('user.password')
                            ->label(__('backoffice/vendor.form.password'))
                            ->password()
                            ->visibleOn('create')
                            ->required(),
                        DatePicker::make('user.date_birthday')
                            ->label(__('backoffice/vendor.form.date_birthday'))
                            ->required()
                            ->maxDate(now()->subYears(18)),
                        TextInput::make('user.nif')
                            ->label(__('backoffice/vendor.form.nif'))
                            ->required()
                            ->rules(function (?Vendor $record) {
                                if (! $record && ! is_null($record)) {
                                    return ['unique:users,nif,'.$record->user->id, new NifRule];
                                }

                                return [];
                            })
                            ->maxLength(255),
                        Select::make('user.gender_id')
                            ->label(__('backoffice/vendor.form.gender'))
                            ->relationship('user.gender', 'name'),
                        Select::make('operation_area_id')
                            ->label(__('backoffice/vendor.form.operation_area'))
                            ->relationship('operationAreas', 'name')
                            ->multiple()
                            ->searchable()
                            ->preload(),
                        TextInput::make('price_rate')
                            ->label(__('backoffice/vendor.form.price_rate'))
                            ->required()
                            ->suffix('€')
                            ->numeric()
                            // price_rate = 0 fazia o preço depender só da distância e amplificava a
                            // comissão negativa. Exigir > 0 no backoffice (vendors legados com 0
                            // precisam de ser normalizados ao serem editados).
                            ->minValue(0.01),
                    ]),
                \Filament\Forms\Components\Section::make('Personal Data')
                    ->columns()
                    ->schema([
                        TextInput::make('iban')
                            ->label(__('backoffice/vendor.form.iban'))
                            ->visible(fn (): bool => auth()->user()?->hasRole('super-admin') ?? false)
                            ->rules([new ValidIban]),
                        TextInput::make('company_name')
                            ->required()
                            ->label(__('backoffice/vendor.form.company_name')),
                    ]),
                \Filament\Forms\Components\Section::make(__('backoffice/vendor.form.contacts'))->columns()->schema([
                    TextInput::make('user.email')
                        ->rules(function (?Vendor $record) {
                            if (! $record && ! is_null($record)) {
                                return ['unique:users,email,'.$record->user->id];
                            }

                            return [];
                        })
                        ->required(),
                    TextInput::make('user.phone_number')
                        ->label(__('backoffice/vendor.form.phone_number'))
                        ->rule(fn (?Vendor $record) => new \App\Rules\UniquePhoneNumber($record?->user_id))
                        ->required(),
                ]),
            ]);
    }

    /**
     * @throws Exception
     */
    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function ($query) {
                return $query->whereHas('user');
            })
            ->columns([
                TextColumn::make('user.name')
                    ->label(__('backoffice/vendor.table.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('user.nif')
                    ->label(__('backoffice/vendor.table.nif'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('user.phone_number')
                    ->label(__('backoffice/vendor.table.phone_number'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('price_rate')
                    ->label(__('backoffice/vendor.table.price_rate'))
                    ->suffix('€')
                    ->default('-'),
                TextColumn::make('operationAreas.name')
                    ->label(__('backoffice/vendor.table.operation_areas'))
                    ->badge(),

                IconColumn::make('can_accept_service')
                    ->label(__('backoffice/vendor.table.can_accept_service'))
                    ->boolean(),
                IconColumn::make('at_valid')
                    ->label('AT')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),
                TextColumn::make('at_validated_at')
                    ->label('Última vez OK')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('backoffice/vendor.table.status'))
                    ->badge()
                    ->color(fn ($state) => $state === StatusVendor::ONLINE ? 'success' : 'gray'),
            ])
            ->filters([
                TrashedFilter::make(),
                SelectFilter::make('status')->options(StatusVendor::class),
                TernaryFilter::make('at_valid')
                    ->label('AT Válido')
                    ->placeholder('Todos')
                    ->trueLabel('Válido')
                    ->falseLabel('Com problemas'),
                TernaryFilter::make('can_accept_service')
                    ->label(__('backoffice/vendor.table.can_accept_service'))
                    ->queries(
                        true: function ($query) {
                            $vendors = (clone $query)->with(['user', 'documents', 'services', 'operationAreas'])->get();
                            $filtered = VendorSearchService::filterByCanAcceptService($vendors, true);

                            return $query->whereIn('id', $filtered->pluck('id'));
                        },
                        false: function ($query) {
                            $vendors = (clone $query)->with(['user', 'documents', 'services', 'operationAreas'])->get();
                            $filtered = VendorSearchService::filterByCanAcceptService($vendors, false);

                            return $query->whereIn('id', $filtered->pluck('id'));
                        },
                        blank: null,
                    )
                    ->placeholder(__('backoffice/vendor.table.all')),
            ])
            ->headerActions([
                ExportAction::make()
                    ->label('Exportar Excel')
                    ->authorize(fn (): bool => auth()->user()?->hasRole('super-admin') ?? false)
                    ->exporter(VendorExporter::class)
                    ->formats([ExportFormat::Xlsx]),
            ])
            ->actions([
                ViewAction::make(),
                RestoreAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make(__('backoffice/vendor.infolist.personal_data'))
                ->columns(5)
                ->headerActions([
                    Action::make('toggle_is_test')
                        ->label(fn (Vendor $record) => $record->user->is_test ? 'Remover Test' : 'Marcar como Test')
                        ->icon(fn (Vendor $record) => $record->user->is_test ? 'heroicon-o-x-circle' : 'heroicon-o-beaker')
                        ->color(fn (Vendor $record) => $record->user->is_test ? 'gray' : 'warning')
                        ->button()
                        ->requiresConfirmation()
                        ->authorize(fn (): bool => auth()->user()?->hasRole('super-admin') ?? false)
                        ->action(function (Vendor $record) {
                            // Atribuição direta: 'is_test' está fora do $fillable (fecha o bypass
                            // público) — ->update([...]) descartava-o em silêncio (toggle morto).
                            $record->user->is_test = ! $record->user->is_test;
                            $record->user->save();

                            Notification::make()
                                ->title($record->user->is_test ? 'Técnico marcado como Test' : 'Técnico removido de Test')
                                ->success()
                                ->send();
                        }),
                ])
                ->schema([
                    TextEntry::make('user.name')
                        ->label(__('backoffice/vendor.infolist.name')),
                    TextEntry::make('user.nif')
                        ->label(__('backoffice/vendor.infolist.nif')),
                    TextEntry::make('user.date_birthday')
                        ->label(__('backoffice/vendor.infolist.date_birthday'))
                        ->date('d/m/Y'),
                    TextEntry::make('user.gender.name')
                        ->label(__('backoffice/vendor.infolist.gender'))
                        ->default('-'),
                    TextEntry::make('price_rate')
                        ->label(__('backoffice/vendor.infolist.price_rate'))
                        ->default('-')
                        ->suffix('€'),
                ]),
            VendorEligibilitySection::make(),
            VendorScheduleAddressSection::make(),
            Grid::make()
                ->schema([
                    Grid::make(1)
                        ->columnSpan(1)
                        ->schema([
                            VendorContactsSection::make(),
                            CompanySection::make(),
                        ]),
                    VendorDocumentsSection::make(),
                ]),
            Section::make(__('backoffice/vendor.infolist.services.title'))
                ->columns()
                ->headerActions([
                    Action::make('Change services')
                        ->label(__('backoffice/vendor.infolist.services.change_services'))
                        ->authorize(fn (): bool => auth()->user()?->hasRole('super-admin') ?? false)
                        ->slideOver()
                        ->form([
                            Repeater::make('services')
                                ->reorderableWithDragAndDrop(false)
                                ->hintAction(\Filament\Forms\Components\Actions\Action::make('Add all services')->action(fn (Get $get, Set $set) => self::addAllServices($get, $set))->button())
                                ->schema([
                                    Select::make('services_type_id')
                                        ->label('Service Type')
                                        ->options(fn (Vendor $record) => ServicesType::whereIn('operation_area_id', $record->operationAreas->pluck('id'))->pluck('name', 'id'))
                                        ->searchable()
                                        ->preload()
                                        ->required(),
                                ])
                                ->defaultItems(0),
                        ])
                        ->mountUsing(function (Form $form, Vendor $record) {
                            $form->model($record);

                            $form->fill([
                                'services' => $record->servicesTypes->map(fn ($serviceType) => [
                                    'services_type_id' => $serviceType->pivot->services_type_id,
                                ])->toArray(),
                            ]);
                        })
                        ->action(function (Vendor $record, array $data) {
                            $record->setServices($data['services']);
                            Notification::make()->title('Update successfully')->success()->send();
                            $record->refresh();
                        }),
                ])
                ->schema([
                    TextEntry::make('operationAreas.name')
                        ->label(__('backoffice/vendor.infolist.services.operation_areas'))
                        ->badge(),
                    RepeatableEntry::make('servicesTypes')
                        ->label('')
                        ->contained()
                        ->grid()
                        ->columns()
                        ->columnSpan(2)
                        ->schema([
                            TextEntry::make('name')
                                ->label('')
                                ->helperText(fn (ServicesType $record) => $record->operationArea?->name)
                                ->tooltip(fn (ServicesType $record) => __('backoffice/vendor.infolist.services.service_time', ['time' => $record->time])),
                        ]),
                ]),
        ]);
    }

    public static function addAllServices(Get $get, Set $set): void
    {
        $services_in_use_id = [];
        $services_in_use = [];
        foreach ($get('services') as $service) {
            $services_in_use[] = [
                'services_type_id' => $service['services_type_id'],
            ];
            $services_in_use_id[] = $service['services_type_id'];
        }

        $services = ServicesType::select(['id'])->whereNotIn('id', $services_in_use_id)->get();
        foreach ($services as $service_bd) {
            $services_in_use[] = [
                'services_type_id' => $service_bd->id,
            ];
        }

        $set('services', $services_in_use);
        Notification::make()->title('All services are added with successfully')->success()->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVendors::route('/'),
            'edit' => Pages\EditVendor::route('/{record}/edit'),
            'view' => Pages\ViewVendor::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->when(! auth()->user()->hasRole('developer'), fn ($q) => $q->whereHas('user', fn ($u) => $u->where('is_test', false)));
    }

    public static function resolveRecordRouteBinding(int|string $key): ?Model
    {
        return app(static::getModel())
            ->resolveRouteBindingQuery(static::getEloquentQuery()->withTrashed(), $key, static::getRecordRouteKeyName())
            ->first();
    }

    public static function getGlobalSearchResultUrl(Model $record): ?string
    {
        return self::getUrl('view', ['record' => $record->id]);
    }

    public static function getRelations(): array
    {
        return [
            ServicesRelationManager::class,
            AddressesRelationManager::class,
            WalletRelationManager::class,
            AuditsRelationManager::class,
            ImpersonationCodesRelationManager::class,
        ];
    }

    public static function getModelLabel(): string
    {
        return __('backoffice/vendor.singular');
    }

    public static function getPluralLabel(): string
    {
        return __('backoffice/vendor.plural');
    }
}
