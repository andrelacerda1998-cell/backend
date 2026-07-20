<?php

namespace App\Filament\Widgets\Wallet;

use Bavix\Wallet\Models\Transaction;
use Filament\Support\Enums\IconPosition;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

class WalletStats extends BaseWidget
{
    public static function canView(): bool
    {
        return auth()->user()->hasRole('super-admin');
    }

    protected function getStats(): array
    {
        $last24Hours = $this->getLast24Hours();
        $totalTransactions = $this->calculateTotalTransactions($last24Hours);
        $totalDepositsFormatted = $this->formatTotalDeposits($totalTransactions);

        list($description, $color, $icon) = $this->getDescriptionColorAndIcon($totalTransactions, $totalDepositsFormatted);

        return [
            Stat::make(__('backoffice/system_profit.wallet_balance'), system_wallet()->balance_float . '€')
                ->color($color)
                ->descriptionIcon($icon, IconPosition::Before)
                ->description($description),
        ];
    }

    protected function getLast24Hours(): Carbon
    {
        return Carbon::now()->subDay();
    }

    protected function calculateTotalTransactions(Carbon $last24Hours): float
    {
        return system_wallet()
            ->transactions()
            ->where('created_at', '>=', $last24Hours)
            ->sum('amount');
    }

    protected function formatTotalDeposits(float $totalTransactions): string
    {
        return number_format($totalTransactions / 100, 2, ',', '.');
    }

    protected function getDescriptionColorAndIcon(float $totalTransactions, string $totalDepositsFormatted): array
    {
        if ($totalTransactions > 0) {
            return [
                __('backoffice/system_profit.widget.increase', ['value' => $totalDepositsFormatted]),
                'success',
                'heroicon-m-arrow-trending-up'
            ];
        } elseif ($totalTransactions == 0) {
            return [
                __('backoffice/system_profit.widget.no_transactions'),
                '',
                ''
            ];
        } else {
            return [
                __('backoffice/system_profit.widget.decrease', ['value' => $totalDepositsFormatted]),
                'danger',
                'heroicon-m-arrow-trending-down'
            ];
        }
    }
}
