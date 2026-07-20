<?php

namespace App\Filament\Widgets\Services;

use App\Enums\Services\PaymentStatus;
use App\Filament\Widgets\Services\Concerns\ComparesPeriods;
use App\Filament\Widgets\Services\Concerns\FiltersTestServices;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

class ServiceFinancialStats extends BaseWidget
{
    use ComparesPeriods, FiltersTestServices;

    protected static ?int $sort = 2;

    protected static ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        return auth()->user()->hasRole('super-admin');
    }

    protected function getStats(): array
    {
        $current = $this->aggregatePaid(now()->startOfMonth(), now());
        $previous = $this->aggregatePaid(
            now()->subMonthNoOverflow()->startOfMonth(),
            now()->subMonthNoOverflow()->endOfMonth()
        );

        return [
            $this->applyComparison(
                Stat::make(__('backoffice/dashboard.financial.volume'), $this->formatMoney($current->volume)),
                $current->volume,
                $previous->volume,
                'previous_month'
            ),
            $this->applyComparison(
                Stat::make(__('backoffice/dashboard.financial.commission'), $this->formatMoney($current->commission)),
                $current->commission,
                $previous->commission,
                'previous_month'
            ),
            $this->applyComparison(
                Stat::make(__('backoffice/dashboard.financial.vendor_payout'), $this->formatMoney($current->vendor_amount)),
                $current->vendor_amount,
                $previous->vendor_amount,
                'previous_month'
            ),
            $this->applyComparison(
                Stat::make(__('backoffice/dashboard.financial.average_ticket'), $this->formatMoney($current->average)),
                $current->average,
                $previous->average,
                'previous_month'
            ),
        ];
    }

    protected function aggregatePaid(Carbon $from, Carbon $until): object
    {
        return $this->serviceQuery()
            ->where('payment_status', PaymentStatus::PAID)
            ->whereBetween('created_at', [$from, $until])
            ->toBase()
            ->selectRaw('
                COALESCE(SUM(amount), 0) as volume,
                COALESCE(SUM(amount - amount_for_vendor), 0) as commission,
                COALESCE(SUM(amount_for_vendor), 0) as vendor_amount,
                COALESCE(AVG(amount), 0) as average
            ')
            ->first();
    }
}
