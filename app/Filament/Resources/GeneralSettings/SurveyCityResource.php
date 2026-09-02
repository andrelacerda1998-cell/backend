<?php

namespace App\Filament\Resources\GeneralSettings;

use App\Filament\Components\Address\GoogleCityAutocomplete;
use App\Filament\Resources\GeneralSettings\SurveyCityResource\Pages;
use App\Models\GeneralSettings\SurveyCity;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SurveyCityResource extends Resource
{
    protected static ?string $model = SurveyCity::class;

    protected static ?string $slug = 'general-settings/survey-cities';

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $recordTitleAttribute = 'city';

    public static function canAccess(): bool
    {
        return auth()->user()->hasRole('super-admin');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('backoffice/navigation.general_settings');
    }

    public static function getModelLabel(): string
    {
        return __('backoffice/survey_city.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('backoffice/survey_city.plural');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                GoogleCityAutocomplete::make('city')
                    ->countries(['pt'])
                    ->language('pt'),
                Forms\Components\Toggle::make('active')
                    ->label(__('backoffice/survey_city.table.active'))
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('city')
                    ->label(__('backoffice/survey_city.table.city'))
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('district')
                    ->label(__('backoffice/survey_city.table.district'))
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('voters_count')
                    ->label(__('backoffice/survey_city.table.votes'))
                    ->counts('voters')
                    ->sortable(),
                Tables\Columns\IconColumn::make('active')
                    ->label(__('backoffice/survey_city.table.active'))
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('active')
                    ->label(__('backoffice/survey_city.table.active')),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->slideOver()
                    ->label(__('backoffice/survey_city.create_label')),
            ])
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
            SurveyCityResource\RelationManagers\VotersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSurveyCities::route('/'),
            'edit' => Pages\EditSurveyCity::route('/{record}/edit'),
        ];
    }
}
