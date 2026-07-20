<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AdminsResource\Pages;
use App\Models\User;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;
use Tapp\FilamentAuditing\RelationManagers\AuditsRelationManager;

class AdminsResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'admins';

    protected static ?string $label = 'Administrators';

    protected static ?string $navigationIcon = 'heroicon-o-user';

    public static function canAccess(): bool
    {
        $user = auth()->user();
        return $user->hasRole('super-admin');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('backoffice/navigation.general_settings');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make(__('backoffice/customer.form.personal_data'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('first_name')
                            ->placeholder(__('backoffice/administrator.form.first_name'))
                            ->label(__('backoffice/administrator.form.first_name'))
                            ->required(),
                        TextInput::make('last_name')
                            ->placeholder(__('backoffice/administrator.form.last_name'))
                            ->label(__('backoffice/administrator.form.last_name'))
                            ->required(),
                        TextInput::make('email')
                            ->placeholder(__('backoffice/administrator.form.email'))
                            ->label(__('backoffice/administrator.form.email'))
                            ->required()
                            ->columnSpan(2)
                            ->unique(User::class, 'email', ignoreRecord: true),
                        TextInput::make('password')
                            ->required()
                            ->visibleOn('create')
                            ->password()
                            ->confirmed()
                            ->label(__('backoffice/administrator.form.password'))
                            ->placeholder(__('backoffice/administrator.form.password')),
                        TextInput::make('password_confirmation')
                            ->password()
                            ->visibleOn('create')
                            ->label(__('backoffice/administrator.form.password_confirmation'))
                            ->placeholder(__('backoffice/administrator.form.password_confirmation')),
                        Select::make('roles')
                            ->columnSpan(2)
                            ->multiple()
                            ->preload()
                            ->relationship('roles', 'name')
                    ])
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn(Builder $query) => $query->whereHas('roles', fn($query) => $query->whereIn('name', ['admin', 'super-admin', 'dev'])))
            ->columns([
                TextColumn::make('name')
                    ->placeholder(__('backoffice/administrator.table.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->placeholder(__('backoffice/administrator.table.email'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('roles.name')
                    ->placeholder(__('backoffice/administrator.table.email'))
                    ->searchable()
                    ->badge()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            #AuditsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAdmins::route('/'),
            'create' => Pages\CreateAdmins::route('/create'),
            'edit' => Pages\EditAdmins::route('/{record}/edit'),
        ];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['gender']);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'email', 'gender.name'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        $details = [];

        if ($record->gender) {
            $details['Gender'] = $record->gender->name;
        }

        return $details;
    }

    public static function getModelLabel(): string
    {
        return __('backoffice/administrator.singular');
    }

    public static function getPluralLabel(): string
    {
        return __('backoffice/administrator.singular');
    }
}
