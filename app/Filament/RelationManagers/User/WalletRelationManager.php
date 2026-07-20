<?php

namespace App\Filament\RelationManagers\User;

use App\Filament\Actions\Table\Wallet\ViewTransaction;
use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\ServicesResource;
use App\Filament\Resources\VendorResource;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\IconPosition;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class WalletRelationManager extends RelationManager
{
    protected static string $relationship = 'transactions';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('backoffice/wallet.singular');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public Model $ownerRecord;

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('amount')
            ->columns([
                Tables\Columns\TextColumn::make('amountFloat')->label(__('backoffice/wallet.table.amount')),
                Tables\Columns\TextColumn::make('meta.type')
                    ->formatStateUsing(function ($state, $record) {
                        if (isset($record['meta']['type']))
                            return __($record['meta']['type']);
                        return '-';
                    })
                    ->default('-')->label(__('backoffice/wallet.table.type')),
                Tables\Columns\TextColumn::make('meta.description')
                    ->formatStateUsing(function ($state, $record) {
                        if (isset($record['meta']['description']))
                            return __($state);
                        return '-';
                    })
                    ->default('-')
                    ->label(__('backoffice/wallet.table.description')),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->since()->label(__('backoffice/wallet.table.created_at')),
            ])
            ->filters([
                //
            ])
            ->actions([
                ViewTransaction::make('view')->icon('heroicon-o-eye')->label(__('backoffice/wallet.table.view'))
            ])
            ->description(function () {
                if ($this->ownerRecord instanceof (new User)) {
                    $balance = $this->ownerRecord->balanceFloat;
                } else {
                    $balance = $this->ownerRecord->user->balanceFloat;

                }

                return __('backoffice/wallet.current_balance') . ': ' . $balance . '€';
            })
            ->headerActions([
                Tables\Actions\Action::make('Add Credit')
                    ->icon('heroicon-o-banknotes')
                    ->iconPosition(IconPosition::Before)
                    ->authorize(fn (): bool => auth()->user()?->hasRole('super-admin') ?? false)
                    ->form([
                        TextInput::make('amount')
                            ->required()
                            ->numeric()
                            ->dehydrateStateUsing(function ($state) {
                                $state = str_replace(',', '', $state);

                                return (int) round($state * 100);
                            })
                            ->afterStateHydrated(fn($component, $state) => $component->state($state / 100))
                            ->minValue(0),
                        Textarea::make('description')->required()->helperText('Justify this transaction')->maxLength(100),
                    ])
                    ->action(function ($data) {
                        /**
                         * @var User $wallet
                         */
                        if (system_wallet()->balance >= $data['amount']) {
                            $destination = $this->ownerRecord;
                            /**
                             * @var User|Vendor $destination
                             */
                            if (!($destination instanceof \Bavix\Wallet\Interfaces\Wallet)) {
                                $destination = $destination->user->wallet;
                            }
                            $class = format_class($destination);

                            system_wallet()->transfer($destination, $data['amount'], [
                                'type' => 'Credit',
                                'description' => $data['description'],
                                'admin_description' => 'Credit to ' . $class . ': ' . $destination->name,
                                'class' => get_class($destination),
                                'id' => $destination->getKey(),
                                'admin_id' => auth()->user()->id
                            ]);

                        } else {
                            Notification::make()->title('Error, System balance not have amount')->color('danger')->send();
                        }


                    }),
                Tables\Actions\Action::make('Remove Credit')
                    ->icon('heroicon-o-banknotes')
                    ->color('danger')
                    ->iconPosition(IconPosition::Before)
                    ->authorize(fn (): bool => auth()->user()?->hasRole('super-admin') ?? false)
                    ->form([
                        TextInput::make('amount')
                            ->required()
                            ->numeric()
                            ->dehydrateStateUsing(function ($state) {
                                $state = str_replace(',', '', $state);

                                return (int) round($state * 100);
                            })
                            ->afterStateHydrated(fn($component, $state) => $component->state($state / 100))
                            ->minValue(0),
                        Textarea::make('description')->required()->helperText('Justify this transaction')->maxLength(100),
                    ])
                    ->action(function ($data) {
                        /**
                         * @var User $wallet
                         */
                        $payer = $this->ownerRecord;
                        /**
                         * @var User|Vendor $payer
                         */
                        if (!($payer instanceof \Bavix\Wallet\Interfaces\Wallet)) {
                            $payer = $payer->user->wallet;
                        }

                        if ($payer->balance >= $data['amount']) {

                            $class = format_class($payer);

                            $payer->transfer(system_wallet(), $data['amount'], [
                                'type' => 'Debit',
                                'description' => $data['description'],
                                'admin_description' => 'Debit to ' . $class . ': ' . $payer->name,
                                'class' => get_class($payer),
                                'id' => $payer->getKey(),
                                'admin_id' => auth()->user()->id
                            ]);

                        } else {
                            Notification::make()->title('Error, User balance not have amount')->danger()->send();
                        }


                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
