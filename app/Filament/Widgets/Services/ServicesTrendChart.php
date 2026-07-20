<?php

namespace App\Filament\Widgets\Services;

use App\Enums\Services\ServiceStatus;
use App\Filament\Widgets\Services\Concerns\FiltersTestServices;
use Filament\Widgets\ChartWidget;

class ServicesTrendChart extends ChartWidget
{
    use FiltersTestServices;

    protected static ?int $sort = 3;

    protected static ?string $pollingInterval = '60s';

    protected static ?string $maxHeight = '300px';

    public ?string $filter = '30';

    public function getHeading(): string
    {
        return __('backoffice/dashboard.trend.heading');
    }

    protected function getFilters(): ?array
    {
        return [
            '7' => __('backoffice/dashboard.trend.filters.7'),
            '30' => __('backoffice/dashboard.trend.filters.30'),
            '90' => __('backoffice/dashboard.trend.filters.90'),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $days = (int) ($this->filter ?? 30);
        $start = today()->subDays($days - 1);

        // All requests (incl. pending/cancelled/refused), grouped by day and status.
        $rows = $this->serviceQuery()
            ->where('created_at', '>=', $start)
            ->toBase()
            ->selectRaw('DATE(created_at) as day, status, COUNT(*) as total')
            ->groupBy('day', 'status')
            ->get();

        // counts[status][day] => total
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row->status][$row->day] = (int) $row->total;
        }

        // Ordered list of day-keys and their d/m labels (zero-filled range).
        $labels = [];
        $dayKeys = [];
        for ($date = $start->copy(); $date->lte(today()); $date->addDay()) {
            $labels[] = $date->format('d/m');
            $dayKeys[] = $date->toDateString();
        }

        // Total line (all statuses combined), always first.
        $total = [];
        foreach ($dayKeys as $dayKey) {
            $sum = 0;
            foreach ($counts as $byDay) {
                $sum += $byDay[$dayKey] ?? 0;
            }
            $total[] = $sum;
        }

        $datasets = [[
            'label' => __('backoffice/dashboard.trend.total'),
            'data' => $total,
            'borderColor' => '#111827',
            'backgroundColor' => '#111827',
            'fill' => false,
            'tension' => 0.3,
        ]];

        // One line per status that actually occurred in the range.
        foreach (ServiceStatus::cases() as $status) {
            $byDay = $counts[$status->value] ?? [];

            if (array_sum($byDay) === 0) {
                continue;
            }

            $datasets[] = [
                'label' => __($status->value),
                'data' => array_map(fn ($dayKey) => $byDay[$dayKey] ?? 0, $dayKeys),
                'borderColor' => $this->statusColor($status),
                'backgroundColor' => $this->statusColor($status),
                'fill' => false,
                'tension' => 0.3,
            ];
        }

        return [
            'datasets' => $datasets,
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'position' => 'bottom',
                ],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => [
                        'precision' => 0,
                    ],
                ],
            ],
        ];
    }
}
