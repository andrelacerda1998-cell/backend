<?php

namespace App\Filament\Resources\GeneralSettings;

use App\Filament\Resources\GeneralSettings\DocumentResource\Pages;
use App\Models\GeneralSettings\Document;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use SolutionForest\FilamentTranslateField\Forms\Component\Translate;

class DocumentResource extends Resource
{
    protected static ?string $model = Document::class;

    protected static ?string $slug = 'general-settings/documents';

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function getNavigationGroup(): ?string
    {
        return __('backoffice/navigation.general_settings');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Translate::make([
                    TextInput::make('name')
                        ->label(__('backoffice/document.form.name'))
                        ->required()
                        ->maxLength(255)
                        ->placeholder(__('backoffice/document.form.name_placeholder')),
                    TextInput::make('description')
                        ->label(__('backoffice/document.form.description'))
                        ->maxLength(255)
                        ->placeholder(__('backoffice/document.form.description_placeholder')),
                ])
                    ->columnSpan(2)
                    ->locales(['en', 'pt-pt']),
                Checkbox::make('required')
                    ->label(__('backoffice/document.form.required')),

                Placeholder::make('created_at')
                    ->label(__('backoffice/document.form.created_at'))
                    ->content(fn(?Document $record): string => $record?->created_at?->diffForHumans() ?? '-'),

                Placeholder::make('updated_at')
                    ->label(__('backoffice/document.form.updated_at'))
                    ->content(fn(?Document $record): string => $record?->updated_at?->diffForHumans() ?? '-'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('backoffice/document.table.name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('description')
                    ->label(__('backoffice/document.table.description')),

                IconColumn::make('required')
                    ->label(__('backoffice/document.table.required'))
                    ->boolean(),
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDocuments::route('/'),
            'create' => Pages\CreateDocument::route('/create'),
            'edit' => Pages\EditDocument::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name'];
    }

    public static function getModelLabel(): string
    {
        return __('backoffice/document.singular');
    }

    public static function getPluralLabel(): string
    {
        return __('backoffice/document.plural');
    }
}
