<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SmsCodeResource\Pages;
use App\Models\Auth\PhoneNumberValidationCode;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SmsCodeResource extends Resource
{
    protected static ?string $model = PhoneNumberValidationCode::class;

    protected static ?string $slug = 'sms-codes';

    protected static ?string $label = 'Códigos SMS';

    protected static ?string $navigationIcon = 'heroicon-o-device-phone-mobile';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super-admin') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('backoffice/navigation.general_settings');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('phone_number')
                    ->label('Número')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('code')
                    ->label('Código')
                    ->badge()
                    ->copyable(),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge(),
                TextColumn::make('user.name')
                    ->label('Utilizador')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Criado')
                    ->dateTime('d/m H:i')
                    ->sortable(),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSmsCodes::route('/'),
        ];
    }
}
