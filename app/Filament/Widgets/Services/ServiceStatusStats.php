<?php

namespace App\Filament\Widgets\Services;

use App\Enums\Services\ServiceStatus;
use App\Filament\Widgets\Services\Concerns\FiltersTestServices;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ServiceStatusStats extends BaseWidget
{
    use FiltersTestServices;

    protected static ?string $pollingInterval = '60s';

    protected int|string|array $columnSpan = 'full';

    /**
     * This widget is auto-discovered by the panel, which would also render it on
     * the Dashboard. There it would duplicate ServicesStatusChart, so restrict it
     * to the Services listing (where it is attached via getHeaderWidgets()).
     */
    public static function canView(): bool
    {
        return ! request()->routeIs('filament.backoffice.pages.dashboard');
    }

    /**
     * Main-flow statuses, in display order. The MBWAY/3DS/archived edge states are
     * intentionally omitted to keep the header compact (see plan decision).
     */
    protected function mainStatuses(): array
    {
        return [
            ServiceStatus::PENDING,
            ServiceStatus::ACCEPTED,
            ServiceStatus::SCHEDULED,
            ServiceStatus::ARRIVED,
            ServiceStatus::FINISHED,
            ServiceStatus::CLOSED,
            ServiceStatus::CANCELED,
            ServiceStatus::REFUSED,
        ];
    }

    protected function getStats(): array
    {
        // Single grouped query (same pattern as ServicesStatusChart) so all counts
        // come from one DB round-trip. serviceQuery() mirrors the resource query.
        $counts = $this->serviceQuery()
            ->toBase()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return array_map(function (ServiceStatus $status) use ($counts): Stat {
            return Stat::make(__($status->value), (int) ($counts[$status->value] ?? 0))
                ->color($this->statusBadgeColor($status));
        }, $this->mainStatuses());
    }

    /**
     * Mirrors the status badge colors in ServicesResource so the cards agree with
     * the table (warning/danger/success/gray).
     */
    protected function statusBadgeColor(ServiceStatus $status): string
    {
        return match ($status) {
            ServiceStatus::PENDING, ServiceStatus::CLOSED_PENDING_PAYMENT => 'warning',
            ServiceStatus::CANCELED, ServiceStatus::REFUSED, ServiceStatus::REFUSED_MBWAY => 'danger',
            ServiceStatus::ACCEPTED, ServiceStatus::FINISHED, ServiceStatus::ARRIVED, ServiceStatus::CLOSED, ServiceStatus::SCHEDULED => 'success',
            ServiceStatus::PENDING_3DS, ServiceStatus::ARCHIVED, ServiceStatus::EXPIRED_MBWAY, ServiceStatus::CANCELED_MBWAY => 'gray',
        };
    }

    protected function getColumns(): int
    {
        return 4;
    }
}
