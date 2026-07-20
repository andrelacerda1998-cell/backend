<?php

namespace App\Filament\Resources;

use App\Filament\Infolists\Sections\CustomerEligibilitySection;
use App\Filament\RelationManagers\User\AddressesRelationManager;
use App\Filament\RelationManagers\User\ImpersonationCodesRelationManager;
use App\Filament\RelationManagers\User\WalletRelationManager;
use App\Filament\Resources\CustomerResource\Pages;
use App\Filament\Resources\CustomerResource\RelationManagers\ServicesRelationManager;
use App\Filament\Resources\PaymentMethodsResource\RelationManagers\PaymentMethodsRelationManager;
use App\Models\User;
use App\Rules\NifRule;
use App\Rules\UniquePhoneNumber;
use Exception;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Components\Actions\Action;
use Filament\Infolists\Components\Group;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\ForceDeleteAction;
use Filament\Tables\Actions\RestoreAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Tapp\FilamentAuditing\RelationManagers\AuditsRelationManager;

class CustomerResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make(__('backoffice/customer.form.personal_data'))->columns(2)->schema([
                    SpatieMediaLibraryFileUpload::make('avatar')
                        ->collection('avatar')
                        ->conversion('small')
                        ->label(__('backoffice/customer.form.avatar'))
                        ->placeholder(__('backoffice/customer.form.avatar_placeholder'))
                        ->previewable()
                        ->image()
                        ->columnSpan(2),
                    TextInput::make('first_name')
                        ->label(__('backoffice/customer.form.first_name'))
                        ->nullable()
                        ->maxLength(255),
                    TextInput::make('last_name')
                        ->label(__('backoffice/customer.form.last_name'))
                        ->nullable()
                        ->maxLength(255),
                    DatePicker::make('date_birthday')
                        ->label(__('backoffice/customer.form.date_birthday'))
                        ->nullable()
                        ->maxDate(now())
                        ->maxDate(now()->subYears(18)),
                    TextInput::make('nif')
                        ->label(__('backoffice/customer.form.nif'))
                        ->label('NIF')
                        ->nullable()
                        ->rules([new NifRule])
                        ->unique(ignoreRecord: true)
                        ->maxLength(255),
                    Select::make('gender_id')
                        ->label(__('backoffice/customer.form.gender'))
                        ->nullable()
                        ->relationship('gender', 'name'),
                    TextInput::make('password')
                        ->password()
                        ->nullable()
                        ->visibleOn('create')
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->label(__('backoffice/customer.form.password')),
                ]),
                Section::make(__('backoffice/customer.form.contacts'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('email')
                            ->label(__('backoffice/customer.form.email'))
                            ->nullable()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),

                        TextInput::make('phone_number')
                            ->label(__('backoffice/customer.form.phone_number'))
                            ->nullable()
                            ->rule(fn (?User $record) => new UniquePhoneNumber($record?->id))
                            ->maxLength(255),
                    ]),
            ]);
    }

    /**
     * @throws Exception
     */
    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('media')->whereDoesntHave('roles', fn ($query) => $query->whereIn('name', ['admin', 'super-admin', 'dev'])))
            ->columns([
                ImageColumn::make('avatar')
                    ->label(__('backoffice/customer.table.avatar'))
                    ->getStateUsing(fn (User $record) => $record->avatar['small'] ?? null)
                    ->circular()
                    ->size(32),
                TextColumn::make('name')
                    ->label(__('backoffice/customer.table.name'))
                    ->searchable(),
                TextColumn::make('nif')
                    ->label(__('backoffice/customer.table.nif'))
                    ->searchable(),
                TextColumn::make('email')
                    ->label(__('backoffice/customer.table.email'))
                    ->searchable(),
                TextColumn::make('phone_number')
                    ->label(__('backoffice/customer.table.phone_number'))
                    ->searchable(),
                IconColumn::make('canRequestService')
                    ->label(__('backoffice/customer.table.canRequestService'))
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TrashedFilter::make(),
                TernaryFilter::make('canRequestService')
                    ->label(__('backoffice/customer.table.canRequestService'))
                    ->queries(
                        true: function ($query) {
                            $users = (clone $query)->with(['addresses', 'services'])->get();
                            $filtered = $users->filter(fn ($user) => $user->can_request_service === true);

                            return $query->whereIn('id', $filtered->pluck('id'));
                        },
                        false: function ($query) {
                            $users = (clone $query)->with(['addresses', 'services'])->get();
                            $filtered = $users->filter(fn ($user) => $user->can_request_service === false);

                            return $query->whereIn('id', $filtered->pluck('id'));
                        },
                        blank: null,
                    )
                    ->placeholder(__('backoffice/customer.table.all')),
            ])
            ->actions([
                ViewAction::make()->hidden(fn (User $user) => $user->deleted_at),
                RestoreAction::make(),
                ForceDeleteAction::make()
                    ->action(function (User $user) {
                        if ($user->services->count() > 0) {
                            Notification::make()
                                ->title('Customer has requested services')
                                ->danger()
                                ->send();

                            return;
                        }

                        \DB::transaction(function () use ($user) {
                            $user->devices()->forceDelete();
                            $user->addresses()->forceDelete();
                            $user->billingInfo()->forceDelete();
                            $user->phoneNumberValidationCodes()->delete();

                            // Libertar as FKs RESTRICT de `schedule`/`schedule_available` sem alterar
                            // o schema (raiz do cluster de deleção), antes do forceDelete.
                            \App\Models\Schedule\Schedule::where('customer_id', $user->id)->delete();
                            if ($user->isVendor() && $user->vendor) {
                                \App\Models\Schedule\Schedule::where('vendor_id', $user->vendor->id)->delete();
                                $user->vendor->scheduleAvailable()->delete();
                            }

                            $user->forceDelete();
                        });
                        Notification::make()
                            ->title('Customer deleted successfully')
                            ->success()
                            ->send();

                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->modifyQueryUsing(function (Builder $query) {
                return $query->whereDoesntHave('vendor');
            });
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make(__('backoffice/customer.form.personal_data'))
                ->columns(4)
                ->headerActions([
                    Action::make('toggle_is_test')
                        ->label(fn (User $record) => $record->is_test ? 'Remover Test' : 'Marcar como Test')
                        ->icon(fn (User $record) => $record->is_test ? 'heroicon-o-x-circle' : 'heroicon-o-beaker')
                        ->color(fn (User $record) => $record->is_test ? 'gray' : 'warning')
                        ->button()
                        ->requiresConfirmation()
                        ->action(function (User $record) {
                            // Atribuição direta (não mass-assignment): 'is_test' está fora do
                            // $fillable do User de propósito (fecha o bypass público via ->all()),
                            // por isso ->update([...]) descartava-o em silêncio e o toggle não fazia nada.
                            $record->is_test = ! $record->is_test;
                            $record->save();

                            Notification::make()
                                ->title($record->is_test ? 'Cliente marcado como Test' : 'Cliente removido de Test')
                                ->success()
                                ->send();
                        }),
                ])
                ->schema([
                    ImageEntry::make('avatar')
                        ->label(__('backoffice/customer.form.avatar'))
                        ->getStateUsing(fn (User $record) => $record->avatar['src'] ?? null)
                        ->circular()
                        ->columnSpan(2)
                        ->size(200),
                    Group::make()
                        ->columnSpan(2)
                        ->schema([
                            TextEntry::make('name')
                                ->label(__('backoffice/customer.form.name')),
                            TextEntry::make('nif')
                                ->label(__('backoffice/customer.form.nif')),
                            TextEntry::make('date_birthday')
                                ->date('d/m/Y')
                                ->label(__('backoffice/customer.form.date_birthday')),
                            TextEntry::make('gender.name')
                                ->label(__('backoffice/customer.form.gender')),
                        ]),
                ]),
            CustomerEligibilitySection::make(),
            Infolists\Components\Section::make(__('backoffice/customer.form.contacts'))
                ->columns()
                ->schema([
                    TextEntry::make('email')
                        ->label(__('backoffice/customer.form.email'))
                        ->icon(function (User $record, TextEntry $component) {
                            if (! $record->hasVerifiedEmail()) {
                                $component->iconColor('danger');
                                $component->tooltip(__('backoffice/customer.form.email_not_verified'));

                                return 'heroicon-c-exclamation-circle';
                            }

                            return null;
                        })
                        ->suffixAction(Action::make(__('backoffice/customer.form.marked_as_verified'))
                            ->color('success')
                            ->button()
                            ->hidden(fn (User $record) => $record->hasVerifiedEmail())
                            ->icon('heroicon-o-check-circle')
                            ->action(fn (User $record) => $record->update(['email_verified_at' => now()])))
                        ->copyable(),
                    TextEntry::make('phone_number')
                        ->label(__('backoffice/customer.form.phone_number'))
                        ->icon(function (User $record, TextEntry $component) {
                            if (! $record->hasVerifiedPhoneNumber()) {
                                $component->iconColor('danger');
                                $component->tooltip(__('backoffice/customer.form.phone_not_verified'));

                                return 'heroicon-c-exclamation-circle';
                            }

                            return null;
                        })
                        ->suffixAction(Action::make(__('backoffice/customer.form.marked_as_verified'))
                            ->color('success')
                            ->button()
                            ->hidden(fn (User $record) => $record->hasVerifiedPhoneNumber())
                            ->icon('heroicon-o-check-circle')
                            ->action(fn (User $record) => $record->update(['phone_number_verified_at' => now()]))),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomers::route('/'),
            'edit' => Pages\EditCustomer::route('/{record}/edit'),
            'view' => Pages\ViewCustomer::route('/{record}'),
        ];
    }

    public static function getRelations(): array
    {
        return [
            ServicesRelationManager::class,
            AddressesRelationManager::class,
            WalletRelationManager::class,
            AuditsRelationManager::class,
            PaymentMethodsRelationManager::class,
            ImpersonationCodesRelationManager::class,
        ];
    }

    public static function getGlobalSearchResultUrl(Model $record): ?string
    {
        return self::getUrl('view', ['record' => $record->id]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereDoesntHave('roles', fn ($q) => $q->whereIn('name', ['admin', 'super-admin', 'dev']))
            ->when(! auth()->user()->hasRole('developer'), fn ($q) => $q->where('is_test', false));
    }

    public static function resolveRecordRouteBinding(int|string $key): ?Model
    {
        return app(static::getModel())
            ->resolveRouteBindingQuery(static::getEloquentQuery()->withTrashed(), $key, static::getRecordRouteKeyName())
            ->first();
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'email'];
    }

    public static function getModelLabel(): string
    {
        return __('backoffice/customer.singular');
    }

    public static function getPluralLabel(): string
    {
        return __('backoffice/customer.plural');
    }
}
