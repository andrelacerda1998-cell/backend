<?php

namespace App\Filament\Resources\GeneralSettings;

use App\Models\GeneralSettings\Document;
use App\Models\GeneralSettings\OperationArea;
use Exception;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ForceDeleteAction;
use Filament\Tables\Actions\ForceDeleteBulkAction;
use Filament\Tables\Actions\RestoreAction;
use Filament\Tables\Actions\RestoreBulkAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use SolutionForest\FilamentTranslateField\Forms\Component\Translate;
use Tapp\FilamentAuditing\RelationManagers\AuditsRelationManager;

class OperationAreaResource extends Resource
{
    protected static ?string $model = OperationArea::class;

    protected static ?string $slug = 'operation-areas';

    protected static ?string $navigationIcon = 'heroicon-o-square-3-stack-3d';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): ?string
    {
        return __('backoffice/navigation.general_settings');
    }
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                SpatieMediaLibraryFileUpload::make('image')
                    ->collection('image')
                    ->label(__('backoffice/operation-area.form.image'))
                    ->image()
                    ->previewable()
                    ->maxSize(5120)
                    ->helperText(__('backoffice/operation-area.form.image_max_size'))
                    ->columnSpan(2),
                Translate::make()
                    ->columnSpan(2)
                    ->locales(['en', 'pt-pt'])
                    ->schema([
                        TextInput::make('name')
                            ->label(__('backoffice/operation-area.form.name'))
                            ->required(),
                    ]),
                Select::make('documents')
                    ->label(__('backoffice/operation-area.form.documents'))
                    ->multiple()
                    ->options(function (){
                        return Document::where('required', 'false')->pluck('name', 'id');
                    })
            ]);
    }

    /**
     * @throws Exception
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image')
                    ->label(__('backoffice/operation-area.table.image'))
                    ->getStateUsing(fn(OperationArea $record) => $record->image_url)
                    ->size(50),
                TextColumn::make('name')
                    ->label(__('backoffice/operation-area.table.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('certifications')
                    ->formatStateUsing(function ($record) {
                        $documents = '';
                        if (isset($record->certifications)) {
                            foreach ($record->certifications as $document) {
                                $documents .= $document['name'] . ', ';
                            }
                            return $documents;
                        }
                        return '-';
                    })
                    ->label(__('backoffice/operation-area.table.certifications'))
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->actions([
                EditAction::make()->slideOver(),
                DeleteAction::make(),
                RestoreAction::make(),
                ForceDeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                    ForceDeleteBulkAction::make(),
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
            'index' => OperationAreaResource\Pages\ListOperationAreas::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name'];
    }

    public static function getModelLabel(): string
    {
        return __('backoffice/operation-area.singular');
    }

    public static function getPluralLabel(): string
    {
        return __('backoffice/operation-area.plural');
    }
}
