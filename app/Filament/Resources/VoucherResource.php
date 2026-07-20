<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VoucherResource\Pages;
use App\Models\Voucher;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class VoucherResource extends Resource
{
    protected static ?string $model = Voucher::class;

    protected static ?string $slug = 'vouchers';

    protected static ?string $navigationIcon = 'heroicon-o-ticket';

    protected static ?string $recordTitleAttribute = 'name';

    // Criar/editar/apagar vouchers (descontos 100%/ilimitados = emissão de dinheiro) é só para
    // super-admin. A leitura fica aberta ao staff `admin`.
    public static function canCreate(): bool
    {
        return auth()->user()?->hasRole('super-admin') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->hasRole('super-admin') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->hasRole('super-admin') ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Informações Básicas')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('Ex: BlackFriday 25'),
                    ]),
                Section::make('Duração')
                    ->schema([
                        DatePicker::make('start_date')
                            ->label('Data de Início')
                            ->native(false)
                            ->default(now()),
                        DatePicker::make('end_date')
                            ->label('Data de Fim (deixe vazio para eterno)')
                            ->native(false)
                            ->after('start_date')
                            ->placeholder('Eterno'),
                    ]),
                Section::make('Utilizações')
                    ->schema([
                        TextInput::make('max_uses')
                            ->label('Nº máximo de utilizações por utilizador (deixe vazio para ilimitado)')
                            ->numeric()
                            ->minValue(1)
                            ->placeholder('Ilimitado'),
                    ]),
                Section::make('Desconto')
                    ->schema([
                        TextInput::make('discount_percentage')
                            ->label('Percentagem de Desconto')
                            ->required()
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(100)
                            ->suffix('%'),
                    ]),
                Section::make('Tipo de Agendamento')
                    ->schema([
                        CheckboxList::make('valid_services')
                            ->label('Válido para')
                            ->options([
                                'scheduled' => 'Serviço Agendado',
                                'immediate' => 'Serviço Imediato',
                            ])
                            ->required()
                            ->columns(2),
                    ]),
                Section::make('Estado')
                    ->schema([
                        Checkbox::make('is_active')
                            ->label('Ativo')
                            ->default(true),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('discount_percentage')
                    ->label('Desconto')
                    ->formatStateUsing(fn ($state) => $state.'%')
                    ->sortable(),
                TextColumn::make('duration')
                    ->label('Duração')
                    ->formatStateUsing(function (Voucher $record) {
                        if (! $record->end_date) {
                            return 'Eterno';
                        }

                        return $record->start_date->format('d/m/Y').' - '.$record->end_date->format('d/m/Y');
                    }),
                TextColumn::make('max_uses')
                    ->label('Utilizações por Utilizador')
                    ->formatStateUsing(function (Voucher $record) {
                        if (! $record->max_uses) {
                            return 'Ilimitado';
                        }

                        return $record->max_uses;
                    }),
                TextColumn::make('valid_services')
                    ->label('Válido Para')
                    ->formatStateUsing(function (Voucher $record) {
                        $validServices = $record->valid_services ?? [];
                        $labels = [
                            'scheduled' => 'Agendado',
                            'immediate' => 'Imediato',
                        ];
                        $types = array_map(fn ($service) => $labels[$service] ?? $service, $validServices);

                        return implode(', ', $types) ?: '-';
                    }),
                IconColumn::make('is_active')
                    ->label('Ativo')
                    ->boolean(),
                TextColumn::make('usages_count')
                    ->label('Total de Utilizações')
                    ->counts('usages')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('is_active')
                    ->label('Estado')
                    ->options([
                        1 => 'Ativo',
                        0 => 'Inativo',
                    ]),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVouchers::route('/'),
            'create' => Pages\CreateVoucher::route('/create'),
            'view' => Pages\ViewVoucher::route('/{record}'),
            'edit' => Pages\EditVoucher::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name'];
    }

    public static function getModelLabel(): string
    {
        return 'Voucher';
    }

    public static function getPluralLabel(): string
    {
        return 'Vouchers';
    }
}
