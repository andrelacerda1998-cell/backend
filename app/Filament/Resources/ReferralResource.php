<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReferralResource\Pages\ListReferrals;
use App\Models\Referral\Referral;
use App\Services\Carteira\Convites;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Convites (5 € + 5 €): quem convidou quem e em que pé está. Só leitura, mais
 * uma ação para anular em caso de abuso — que devolve à Piquet o crédito que
 * ainda não foi gasto.
 */
class ReferralResource extends Resource
{
    protected static ?string $model = Referral::class;

    protected static ?string $slug = 'convites';

    protected static ?string $navigationIcon = 'heroicon-o-gift';

    protected static ?string $modelLabel = 'convite';

    protected static ?string $pluralModelLabel = 'convites';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('referrer.name')->label('Quem convidou')->searchable(),
                TextColumn::make('referred.name')->label('Amigo'),
                TextColumn::make('code')->label('Código')->searchable(),
                TextColumn::make('status')->label('Estado')->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Referral::CONCLUIDO => 'success',
                        Referral::PENDENTE => 'warning',
                        Referral::ANULADO => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('first_service_id')->label('1.º serviço')->placeholder('—'),
                TextColumn::make('created_at')->label('Usado em')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('completed_at')->label('Concluído em')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextColumn::make('cancel_reason')->label('Motivo da anulação')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label('Estado')->options([
                    Referral::PENDENTE => 'Pendente',
                    Referral::CONCLUIDO => 'Concluído',
                    Referral::SEM_RECOMPENSA => 'Sem recompensa (limite do ano)',
                    Referral::ANULADO => 'Anulado',
                ]),
            ])
            ->actions([
                Action::make('anular')
                    ->label('Anular')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('O crédito deste convite que ainda não foi gasto volta à Piquet. O que já foi gasto fica.')
                    ->form([TextInput::make('motivo')->label('Motivo')->required()->maxLength(120)])
                    ->visible(fn (Referral $record): bool => in_array($record->status, [Referral::PENDENTE, Referral::CONCLUIDO], true)
                        && (auth()->user()?->hasRole('super-admin') ?? false))
                    ->action(function (Referral $record, array $data) {
                        app(Convites::class)->anular($record, $data['motivo']);
                        Notification::make()->title('Convite anulado')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReferrals::route('/'),
        ];
    }
}
