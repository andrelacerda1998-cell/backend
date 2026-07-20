<?php

namespace App\Filament\Widgets\Services\Concerns;

use Filament\Support\Enums\IconPosition;
use Filament\Widgets\StatsOverviewWidget\Stat;

trait ComparesPeriods
{
    protected function applyComparison(Stat $stat, float $current, float $previous, string $periodKey): Stat
    {
        $period = __("backoffice/dashboard.comparison.$periodKey");

        if ($previous == 0) {
            return $stat->description(__('backoffice/dashboard.comparison.no_previous', ['period' => $period]));
        }

        $percent = round(abs($current - $previous) / $previous * 100);

        if ($current > $previous) {
            return $stat
                ->description(__('backoffice/dashboard.comparison.increase', ['value' => $percent, 'period' => $period]))
                ->descriptionIcon('heroicon-m-arrow-trending-up', IconPosition::Before)
                ->color('success');
        }

        if ($current < $previous) {
            return $stat
                ->description(__('backoffice/dashboard.comparison.decrease', ['value' => $percent, 'period' => $period]))
                ->descriptionIcon('heroicon-m-arrow-trending-down', IconPosition::Before)
                ->color('danger');
        }

        return $stat->description(__('backoffice/dashboard.comparison.equal', ['period' => $period]));
    }

    protected function formatMoney(float $cents): string
    {
        return number_format($cents / 100, 2, ',', ' ').'€';
    }
}
