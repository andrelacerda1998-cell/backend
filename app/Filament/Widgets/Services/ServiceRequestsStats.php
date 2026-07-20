<?php

namespace App\Filament\Widgets\Services;

use App\Enums\Services\ServiceStatus;
use App\Filament\Widgets\Services\Concerns\ComparesPeriods;
use App\Filament\Widgets\Services\Concerns\FiltersTestServices;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ServiceRequestsStats extends BaseWidget
{
    use ComparesPeriods, FiltersTestServices;

    protected static ?int $sort = 1;

    protected static ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        return [
            $this->todayStat(),
            $this->thisMonthStat(),
            $this->pendingNowStat(),
            $this->upcomingScheduledStat(),
            $this->completionRateStat(),
        ];
    }

    protected function todayStat(): Stat
    {
        $today = $this->paidServiceQuery()->whereDate('created_at', today())->count();
        $yesterday = $this->paidServiceQuery()->whereDate('created_at', today()->subDay())->count();

        return $this->applyComparison(
            Stat::make(__('backoffice/dashboard.requests.today'), $today),
            $today,
            $yesterday,
            'yesterday'
        );
    }

    protected function thisMonthStat(): Stat
    {
        $thisMonth = $this->paidServiceQuery()
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        $previousMonth = $this->paidServiceQuery()
            ->whereBetween('created_at', [
                now()->subMonthNoOverflow()->startOfMonth(),
                now()->subMonthNoOverflow()->endOfMonth(),
            ])
            ->count();

        return $this->applyComparison(
            Stat::make(__('backoffice/dashboard.requests.this_month'), $thisMonth),
            $thisMonth,
            $previousMonth,
            'previous_month'
        );
    }

    protected function pendingNowStat(): Stat
    {
        $pending = $this->serviceQuery()->pendingPaid()->count();

        return Stat::make(__('backoffice/dashboard.requests.pending_now'), $pending)
            ->description(__('backoffice/dashboard.requests.pending_now_description'))
            ->color('warning');
    }

    protected function upcomingScheduledStat(): Stat
    {
        $upcoming = $this->serviceQuery()
            ->where('status', ServiceStatus::SCHEDULED)
            ->whereHas('schedule', fn ($q) => $q->where('scheduled_day', '>=', today()->toDateString()))
            ->count();

        return Stat::make(__('backoffice/dashboard.requests.upcoming_scheduled'), $upcoming)
            ->description(__('backoffice/dashboard.requests.upcoming_scheduled_description'))
            ->color('info');
    }

    protected function completionRateStat(): Stat
    {
        $last30Days = now()->subDays(30);

        $closed = $this->paidServiceQuery()
            ->where('created_at', '>=', $last30Days)
            ->where('status', ServiceStatus::CLOSED)
            ->count();

        $lost = $this->paidServiceQuery()
            ->where('created_at', '>=', $last30Days)
            ->whereIn('status', [ServiceStatus::CANCELED, ServiceStatus::REFUSED])
            ->count();

        $total = $closed + $lost;
        $rate = $total > 0 ? (int) round($closed / $total * 100) : null;

        return Stat::make(
            __('backoffice/dashboard.requests.completion_rate'),
            $rate === null ? '-' : $rate.'%'
        )
            ->description(__('backoffice/dashboard.requests.completion_rate_description', [
                'closed' => $closed,
                'lost' => $lost,
            ]))
            ->color(match (true) {
                $rate === null => 'gray',
                $rate >= 70 => 'success',
                $rate >= 40 => 'warning',
                default => 'danger',
            });
    }
}
