<?php

namespace App\Filament\Resources\GeneralSettings;

use App\Filament\Components\Address\GoogleCityAutocomplete;
use App\Filament\Resources\GeneralSettings\AllowedZoneResource\Pages;
use App\Filament\Resources\GeneralSettings\AllowedZoneResource\RelationManagers;
use App\Models\GeneralSettings\AllowedZone;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AllowedZoneResource extends Resource
{
    protected static ?string $model = AllowedZone::class;

    protected static ?string $slug = 'general-settings/allowed-zones';

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $recordTitleAttribute = 'city';

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
                GoogleCityAutocomplete::make('city')
                    ->label('City')
                    ->countries([
                        'pt',
                    ])
                    ->language('pt'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                //
                Tables\Columns\TextColumn::make('city')
                    ->label(__('backoffice/allowed_zone.table.city'))
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('district')
                    ->label(__('backoffice/allowed_zone.table.district'))
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('vendors_count')
                    ->label(__('backoffice/allowed_zone.table.vendors'))
                    ->counts('vendors')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions(([
                Tables\Actions\CreateAction::make()
                    ->slideOver()
                    ->label(__('backoffice/allowed_zone.create_label')),
            ]))
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\VendorsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAllowedZones::route('/'),
            'edit' => Pages\EditAllowedZone::route('/{record}/edit'),
        ];
    }

    public static function getModelLabel(): string
    {
        return __('backoffice/allowed_zone.singular');
    }

    public static function getPluralLabel(): string
    {
        return __('backoffice/allowed_zone.plural');
    }
}
