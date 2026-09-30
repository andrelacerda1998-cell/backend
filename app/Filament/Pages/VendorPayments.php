<?php

namespace App\Filament\Pages;

use App\Filament\Resources\VendorResource;
use App\Mail\Vendor\PaymentSentMail;
use App\Models\Vendor;
use App\Notifications\Vendor\PaymentSentNotification;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Mail;

class VendorPayments extends Page implements HasForms, HasTable
{
    use InteractsWithTable;
    use InteractsWithForms;
    protected static ?string $navigationIcon = 'vaadin-money-withdraw';

    protected static string $view = 'filament.pages.vendor-payments';

    public function getTitle(): string|Htmlable
    {
        return __('backoffice/vendor.payments.title');
    }
    public static function getNavigationLabel(): string
    {
        return __('backoffice/vendor.payments.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Vendor::whereHas('user.wallet', function($query) {
                $query->where('balance', '>', 0);
            }))
            ->columns([
                TextColumn::make('user.name'),
                TextColumn::make('iban')
                    ->formatStateUsing(function ($state) {
                        return preg_replace('/(\w{4})(?=\w)/', '$1 ', $state);
                    })
                    ->copyable(),
                TextColumn::make('user.wallet.balanceFloatNum')->label('Amount')->formatStateUsing(function ($state) {
                    return number_format($state, 2).'€';
                }),
                // Porque e que o botao esta desligado. Sem esta coluna o admin ve um
                // "pay" cinzento e nao tem como saber que falta a AT ao tecnico.
                TextColumn::make('payout_blocked_by_at')
                    ->label('Retido')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? 'Falta AT' : '—')
                    ->color(fn ($state) => $state ? 'danger' : 'gray'),
            ])
            ->filters([
                // ...
            ])
            ->actions([
                Action::make('pay')
                ->requiresConfirmation()
                /*
                 * Sem acesso a AT nao ha fatura, e sem fatura nao sai dinheiro. O saldo
                 * fica na carteira do tecnico (o trabalho foi feito, o cliente foi
                 * cobrado) e a app dele explica-lhe porque e que ainda nao recebeu.
                 *
                 * Desligar o botao E verificar dentro da acao: o botao e para o admin
                 * ver, a verificacao e para o dinheiro. Um record que fique em cache no
                 * ecra aberto enquanto o tecnico muda de estado passa pelo primeiro e
                 * nao passa pelo segundo.
                 */
                ->disabled(fn (Vendor $record) => $record->payout_blocked_by_at)
                ->tooltip(fn (Vendor $record) => $record->payout_blocked_by_at
                    ? 'Retido: falta o subutilizador da AT deste tecnico.'
                    : null)
                ->action(function (Vendor $record) {
                    if ($record->payout_blocked_by_at) {
                        Notification::make()
                            ->title('Pagamento retido')
                            ->body('O tecnico ainda nao deu o acesso de subutilizador da AT. O saldo fica na carteira dele.')
                            ->danger()
                            ->send();

                        return;
                    }

                    Mail::to($record->user->email)->send(new PaymentSentMail($record, $record->user->wallet->balanceFloat));
                    $record->user->notify(new PaymentSentNotification($record->user->wallet->balanceFloat));
                    $record->user->wallet->withdraw($record->user->wallet->balance, [
                        'type' => 'Debit',
                        'description' => "Transfer to account",
                        'admin_description' => 'Transfer to account',
                        'class' => get_class($record),
                        'id' => $record->getKey(),
                        'admin_id' => auth()->user()->id
                    ]);
                    Notification::make()
                        ->title('Payment sent successfully')
                        ->success()
                        ->send();
                })
            ])
            ->bulkActions([
                // ...
            ]);
    }

    public function getResource()
    {
        return VendorResource::class;
    }
}
