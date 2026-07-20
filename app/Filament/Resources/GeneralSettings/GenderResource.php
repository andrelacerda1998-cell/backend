<?php

namespace App\Filament\Resources\GeneralSettings;

use App\Filament\Resources\GeneralSettings\GenderResource\Pages;
use App\Models\GeneralSettings\Gender;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use SolutionForest\FilamentTranslateField\Forms\Component\Translate;
use Tapp\FilamentAuditing\RelationManagers\AuditsRelationManager;

class GenderResource extends Resource
{
    protected static ?string $model = Gender::class;

    protected static ?string $slug = 'general-settings/genders';

    protected static ?string $navigationIcon = 'bi-gender-ambiguous';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): ?string
    {
        return __('backoffice/navigation.general_settings');
    }
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Translate::make()->locales(['en', 'pt-pt'])
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label(__('backoffice/gender.form.name'))
                            ->required()
                            ->columnSpan(2)
                            ->maxLength(255),
                    ])
                    ->columnSpan(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('backoffice/gender.table.name'))
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('backoffice/gender.table.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('backoffice/gender.table.updated_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make()->slideOver(),
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
            AuditsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGenders::route('/'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return [];
    }

    public static function getModelLabel(): string
    {
        return __('backoffice/gender.singular');
    }

    public static function getPluralLabel(): string
    {
        return __('backoffice/gender.plural');
    }
}
