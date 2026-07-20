<?php

namespace App\Filament\Resources\PaymentMethodsResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use const _PHPStan_62c6a0a8b\__;

class PaymentMethodsRelationManager extends RelationManager
{
    protected static string $relationship = 'paymentMethods';

    /**
     * @param Model $ownerRecord
     * @param string $pageClass
     * @return string
     */
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('backoffice/payment_method.plural');
    }
    public function isReadOnly():bool
    {
        return false;
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('last4')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('last4')
            ->columns([
                Tables\Columns\TextColumn::make('brand')
                    ->label(__('backoffice/payment_method.table.brand'))
                    ->formatStateUsing(fn ($state, $record) => $state . ' ' . $record->brand_description),
                Tables\Columns\TextColumn::make('last4')
                    ->label(__('backoffice/payment_method.table.last4'))
                    ->formatStateUsing(fn ($state, $record) => '**** **** **** ' . $state),
                Tables\Columns\TextColumn::make('expire_month')
                    ->label(__('backoffice/payment_method.table.exp'))
                    ->formatStateUsing(fn ($state, $record) => $state . '/' . $record->expire_year),
                Tables\Columns\TextColumn::make('holder')
                ->label(__('backoffice/payment_method.table.holder')),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('backoffice/payment_method.table.created_at'))
                    ->since()
                    ->dateTimeTooltip(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                #Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\DeleteAction::make()
                    ->authorize(fn (): bool => auth()->user()?->hasRole('super-admin') ?? false),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->authorize(fn (): bool => auth()->user()?->hasRole('super-admin') ?? false),
                ]),
            ]);
    }
}
