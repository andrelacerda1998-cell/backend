<?php

namespace App\Filament\Pages;

use App\Filament\Actions\Table\Wallet\ViewTransaction;
use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\ServicesResource;
use App\Filament\Resources\VendorResource;
use App\Filament\Widgets\Wallet\WalletStats;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;

class SystemProfit extends Page implements HasForms, HasTable
{
    use InteractsWithTable;
    use InteractsWithForms;
    protected static ?string $navigationIcon = 'heroicon-o-wallet';

    protected static string $view = 'filament.pages.system-profit';

    protected static ?int $navigationSort = 5;

    public static function canAccess(): bool
    {
        $user = auth()->user();
        return $user->hasRole('super-admin');
    }

    /**
     * @return bool
     */
    public static function isShouldRegisterNavigation(): bool
    {
        $user = auth()->user();
        return $user->hasRole('super-admin');
    }

    public function getTitle(): string|Htmlable
    {
        return __('backoffice/system_profit.navigation');
    }
    public static function getNavigationLabel(): string
    {
        return __('backoffice/system_profit.navigation');
    }

    protected function getHeaderWidgets(): array
    {
        return [
            WalletStats::class
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(system_wallet()->transactions()->getQuery())
            ->columns([
                TextColumn::make('meta.type')->label(__('backoffice/system_profit.table.transaction_type'))->formatStateUsing(fn($state)=>__($state))->default('-'),
                TextColumn::make('meta.admin_description')
                    ->formatStateUsing(function ($state, $record){
                        if (isset($record['meta']['admin_description']))
                            return __($record['meta']['admin_description']);
                        return '-';
                    })
                    ->label(__('backoffice/system_profit.table.description'))
                    ->default('-'),
                TextColumn::make('meta.admin_id')->label(__('backoffice/system_profit.table.administrator'))->formatStateUsing(function ($record){
                    if (isset($record['meta']['admin_id']))
                        return $record['meta']['admin_id'] ? User::find($record['meta']['admin_id'])?->name: '-';
                    return '-';
                })->default('-')->label('Admin'),
                TextColumn::make('amount_float')->label(__('backoffice/system_profit.table.amount'))->sortable(),
                TextColumn::make('created_at')->label(__('backoffice/system_profit.table.date'))->dateTime()->sortable(),
            ])
            ->filters([
                // ...
            ])
            ->actions([
                ViewTransaction::make('view')->label(__('backoffice/system_profit.table.view'))->icon('heroicon-o-eye')
            ])
            ->bulkActions([
                // ...
            ])
            ->defaultSort('created_at', 'desc')
            ;
    }
}
