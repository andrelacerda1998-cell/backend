<?php

namespace App\Filament\Resources\GeneralSettings;

use App\Models\GeneralSettings\ServicesType;
use Exception;
use Filament\Forms;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ForceDeleteAction;
use Filament\Tables\Actions\ForceDeleteBulkAction;
use Filament\Tables\Actions\RestoreAction;
use Filament\Tables\Actions\RestoreBulkAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use SolutionForest\FilamentTranslateField\Forms\Component\Translate;
use Tapp\FilamentAuditing\RelationManagers\AuditsRelationManager;
use Tapp\FilamentValueRangeFilter\Filters\ValueRangeFilter;

class ServicesTypeResource extends Resource
{
    protected static ?string $model = ServicesType::class;

    protected static ?string $slug = 'services-types';

    protected static ?string $navigationIcon = 'heroicon-o-clipboard';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): ?string
    {
        return __('backoffice/navigation.general_settings');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->columns()
            ->schema([
                SpatieMediaLibraryFileUpload::make('image')
                    ->collection('image')
                    ->label(__('backoffice/service-type.form.image'))
                    ->image()
                    ->previewable()
                    ->imageEditor()
                    ->imageCropAspectRatio('1:1')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->maxSize(5120)
                    // A app corta as imagens em quadrado; dizê-lo aqui evita
                    // fotos que ficam bem no backoffice e mal no telemóvel.
                    ->helperText(__('backoffice/service-type.form.image_hint'))
                    ->columnSpan(2),

                // Ordem, visibilidade e destaque na Home. Antes a app decidia
                // sozinha o que mostrar e por que ordem.
                Toggle::make('is_active')
                    ->label(__('backoffice/service-type.form.is_active'))
                    ->default(true),
                TextInput::make('sort_order')
                    ->label(__('backoffice/service-type.form.sort_order'))
                    ->numeric()
                    ->default(0)
                    ->minValue(0),
                Toggle::make('is_popular')
                    ->label(__('backoffice/service-type.form.is_popular'))
                    ->helperText(__('backoffice/service-type.form.is_popular_hint'))
                    ->live()
                    ->default(false),
                TextInput::make('popular_order')
                    ->label(__('backoffice/service-type.form.popular_order'))
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->visible(fn ($get) => (bool) $get('is_popular')),
                Translate::make()
                    ->locales(['en', 'pt-pt'])
                    ->columnSpan(2)
                    ->schema([
                        TextInput::make('name')
                            ->label(__('backoffice/service-type.form.name'))
                            ->required()
                            ->maxLength(255),
                    ]),
                Select::make('operation_area_id')
                    ->label(__('backoffice/service-type.form.category'))
                    ->relationship('operationArea', 'name')
                    ->required()
                    ->createOptionForm([TextInput::make('name')]),
                TextInput::make('time')
                    ->required()
                    ->label(__('backoffice/service-type.form.service_time'))
                    ->numeric()
                    ->minValue(0)
                    ->suffix(__('backoffice/service-type.form.minutes')),
                TextInput::make('starts_from')
                    ->label(__('backoffice/service-type.form.starts_from'))
                    ->numeric()
                    ->integer()
                    ->minValue(0),

                Forms\Components\Tabs::make('includes_excludes')
                    ->tabs([
                        Forms\Components\Tabs\Tab::make(__('backoffice/service-type.form.includes'))
                            ->schema([
                                Forms\Components\Repeater::make('includes')
                                    ->hiddenLabel()
                                    ->columnSpanFull()
                                    ->schema([
                                        TextInput::make('en')
                                            ->label('EN')
                                            ->required()
                                            ->maxLength(255),
                                        TextInput::make('pt-pt')
                                            ->label('PT-PT')
                                            ->required()
                                            ->maxLength(255),
                                    ])
                                    ->collapsible()
                                    ->columns()
                                    ->minItems(0)
                                    ->maxItems(50)
                                    ->addActionLabel(__('backoffice/service-type.form.repeater.add_includes')),
                            ]),
                        Forms\Components\Tabs\Tab::make(__('backoffice/service-type.form.excludes'))
                            ->schema([
                                Forms\Components\Repeater::make('excludes')
                                    ->hiddenLabel()
                                    ->columnSpanFull()
                                    ->schema([
                                        TextInput::make('en')
                                            ->label('EN')
                                            ->required()
                                            ->maxLength(255),
                                        TextInput::make('pt-pt')
                                            ->label('PT-PT')
                                            ->required()
                                            ->maxLength(255),
                                    ])
                                    ->collapsible()
                                    ->columns()
                                    ->minItems(0)
                                    ->maxItems(50)
                                    ->addActionLabel(__('backoffice/service-type.form.repeater.add_excludes')),
                            ]),
                    ])
                    ->columnSpanFull(),
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
                    ->label(__('backoffice/service-type.table.image'))
                    ->getStateUsing(fn (ServicesType $record) => $record->image_url)
                    ->size(50),
                TextColumn::make('name')
                    ->label(__('backoffice/service-type.table.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('operationArea.name')
                    ->label(__('backoffice/service-type.table.category'))
                    ->badge()
                    ->sortable()
                    ->searchable(),
                TextColumn::make('time')
                    ->label(__('backoffice/service-type.table.service_time'))
                    ->suffix(__('backoffice/service-type.table.minutes'))
                    ->searchable()
                    ->sortable()
                    ->default('-'),
                TextColumn::make('starts_from')
                    ->label(__('backoffice/service-type.table.starts_from'))
                    ->sortable()
                    ->default('-'),
                TextColumn::make('vendors_count')
                    ->counts('vendors')
                    ->label(__('backoffice/service-type.table.vendors'))
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('includes')
                    ->label(__('backoffice/service-type.table.includes'))
                    ->formatStateUsing(function ($state) {
                        if (! $state || empty($state)) {
                            return '-';
                        }

                        if (is_string($state)) {
                            $items = preg_split('/},\s*{/', $state);
                            $count = count($items);
                        } else {
                            $count = is_array($state) ? count($state) : 0;
                        }

                        return "{$count} ".($count === 1 ? 'item' : 'items');
                    })
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('excludes')
                    ->label(__('backoffice/service-type.table.excludes'))
                    ->formatStateUsing(function ($state) {
                        if (! $state || empty($state)) {
                            return '-';
                        }

                        if (is_string($state)) {
                            $items = preg_split('/},\s*{/', $state);
                            $count = count($items);
                        } else {
                            $count = is_array($state) ? count($state) : 0;
                        }

                        return "{$count} ".($count === 1 ? 'item' : 'items');
                    })
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('operationArea.name')
                    ->label(__('backoffice/service-type.table.category'))
                    ->relationship('OperationArea', 'name'),
                ValueRangeFilter::make('time')
                    ->label(__('backoffice/service-type.table.service_time')),
                ValueRangeFilter::make('suggested_price')
                    ->label(__('backoffice/service-type.table.suggested_price'))
                    ->currency(),
                TrashedFilter::make(),
            ])
            ->actions([
                ViewAction::make()->slideOver(),
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

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            TextEntry::make('name')
                ->label(__('backoffice/service-type.infolist.name')),
            TextEntry::make('OperationArea.name')
                ->label(__('backoffice/service-type.infolist.category'))
                ->badge(),
            TextEntry::make('time')
                ->label(__('backoffice/service-type.infolist.service_time'))
                ->default('-')
                ->suffix(__('backoffice/service-type.infolist.minutes')),
            RepeatableEntry::make('vendors')
                ->label(__('backoffice/service-type.infolist.vendors'))
                ->columnSpanFull()
                ->grid()
                ->columns()
                ->schema([
                    TextEntry::make('user.name')
                        ->label(__('backoffice/vendor.infolist.name')),
                    TextEntry::make('user.email')
                        ->label(__('backoffice/vendor.infolist.email'))
                        ->copyable(),
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
            'index' => ServicesTypeResource\Pages\ListServicesTypes::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return ['Suggested price' => $record->suggested_price.'€'];
    }

    public static function getPluralLabel(): ?string
    {
        return __('backoffice/service-type.plural');
    }

    public static function getLabel(): ?string
    {
        return __('backoffice/service-type.singular');
    }
}
