<?php

namespace App\Filament\Resources\GeneralSettings\AllowedZoneResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class VendorsRelationManager extends RelationManager
{
    protected static string $relationship = 'vendors';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('backoffice/allowed_zone.relation_managers.vendors_title');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label(__('backoffice/vendor.table.name'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.nif')
                    ->label(__('backoffice/vendor.table.nif'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('user.email')
                    ->label(__('backoffice/vendor.form.email'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('price_rate')
                    ->label(__('backoffice/vendor.table.price_rate'))
                    ->suffix('€')
                    ->default('-'),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('backoffice/vendor.table.status'))
                    ->badge(),
            ]);
    }
}
