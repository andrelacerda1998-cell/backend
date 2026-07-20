<?php

namespace App\Filament\RelationManagers\User;

use App\Enums\Services\AddressType;
use App\Filament\Components\Address\GoogleAutocomplete;
use App\Models\Vendor;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class AddressesRelationManager extends RelationManager
{
    protected static string $relationship = 'addresses';

    /*public function isReadOnly(): bool
    {
        return true;
    }*/

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                GoogleAutocomplete::make('google_search')
                    ->label('Google look-up')
                    ->countries([
                        'pt',
                    ])
                    ->withFields([
                        Forms\Components\TextInput::make('name')
                            ->label(__('backoffice/address.form.name'))
                            ->required()
                            ->extraInputAttributes([
                                'data-google-field' => 'formatted_address',
                            ])
                            ->columnSpan(2)
                            ->maxLength(255),
                        Forms\Components\TextInput::make('street_name')
                            ->label(__('backoffice/address.form.street_name'))
                            ->required()
                            ->extraInputAttributes([
                                'data-google-field' => 'route',
                            ])
                            ->maxLength(255),
                        Forms\Components\TextInput::make('street_number')
                            ->label(__('backoffice/address.form.street_number'))
                            ->required()
                            ->extraInputAttributes([
                                'data-google-field' => 'street_number',
                            ])
                            ->maxLength(255),
                        Forms\Components\TextInput::make('postal_code')
                            ->label(__('backoffice/address.form.postal_code'))
                            ->required()
                            ->mask('9999-999')
                            ->extraInputAttributes([
                                'data-google-field' => 'postal_code_prefix',
                            ])
                            ->maxLength(255),
                        Forms\Components\TextInput::make('city')
                            ->label(__('backoffice/address.form.city'))
                            ->required()
                            ->extraInputAttributes([
                                'data-google-field' => 'administrative_area_level_2',
                            ])
                            ->maxLength(255),
                        Forms\Components\TextInput::make('state')
                            ->label(__('backoffice/address.form.state'))
                            ->required()
                            ->extraInputAttributes([
                                'data-google-field' => 'administrative_area_level_1',
                            ])
                            ->maxLength(255),
                        Forms\Components\TextInput::make('latitude')
                            ->label(__('backoffice/address.form.latitude'))
                            ->extraInputAttributes([
                                'data-google-field' => 'latitude',
                            ])
                            ->maxLength(255),
                        Forms\Components\TextInput::make('longitude')
                            ->label(__('backoffice/address.form.longitude'))
                            ->extraInputAttributes([
                                'data-google-field' => 'longitude',
                            ])
                            ->maxLength(255),
                        Forms\Components\TextInput::make('country')
                            ->label(__('backoffice/address.form.country'))
                            ->required()
                            ->extraInputAttributes([
                                'data-google-field' => 'country',
                            ])
                            ->maxLength(255),
                    ]),
                Forms\Components\Select::make('type')
                    ->label(__('backoffice/address.form.type'))
                    ->required()
                    ->options(AddressType::class),
            ]);

    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('address_type')
                    ->formatStateUsing(fn($state) =>  __('backoffice/address.form.address_type.'.$state->value))
                    ->label(__('backoffice/address.table.type')),
                Tables\Columns\TextColumn::make('name')
                    ->label(__('backoffice/address.table.name')),
                Tables\Columns\TextColumn::make('street_name')
                    ->label(__('backoffice/address.table.street_name')),
                Tables\Columns\TextColumn::make('street_number')
                    ->label(__('backoffice/address.table.street_number')),
                Tables\Columns\TextColumn::make('postal_code')
                    ->label(__('backoffice/address.table.postal_code')),
                Tables\Columns\TextColumn::make('city')
                    ->label(__('backoffice/address.table.city')),
                Tables\Columns\IconColumn::make('main_address')
                    ->label(__('backoffice/address.table.main_address'))
                    ->boolean(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->slideOver()
                    ->mutateFormDataUsing(function (array $data): array {
                        if ($this->ownerRecord instanceof Vendor) {
                            $data['address_type'] = AddressType::FISCAL_ADDRESS->value;
                        } else {
                            $data['address_type'] = AddressType::HOUSE_ADDRESS->value;
                        }

                        if ($this->ownerRecord instanceof Vendor) {
                            $data['user_id'] = $this->ownerRecord->user_id;
                        } else {
                            $data['user_id'] = $this->ownerRecord->id;
                        }

                        return $data;
                    })
                    ->label(__('backoffice/address.create_label')),
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

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('backoffice/address.plural');
    }

    public static function getModelLabel(): string
    {
        return __('backoffice/address.singular');
    }
}
