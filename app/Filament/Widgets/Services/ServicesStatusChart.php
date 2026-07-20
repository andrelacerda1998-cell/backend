<?php

namespace App\Filament\Widgets\Services;

use App\Enums\Services\ServiceStatus;
use App\Filament\Widgets\Services\Concerns\FiltersTestServices;
use Filament\Widgets\ChartWidget;

class ServicesStatusChart extends ChartWidget
{
    use FiltersTestServices;

    protected static ?int $sort = 4;

    protected static ?string $pollingInterval = '60s';

    protected static ?string $maxHeight = '300px';

    public function getHeading(): string
    {
        return __('backoffice/dashboard.status.heading');
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $counts = $this->serviceQuery()
            ->where('created_at', '>=', now()->subDays(30))
            ->toBase()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $labels = [];
        $data = [];
        $colors = [];

        foreach (ServiceStatus::cases() as $status) {
            $total = (int) ($counts[$status->value] ?? 0);

            if ($total === 0) {
                continue;
            }

            $labels[] = __($status->value);
            $data[] = $total;
            $colors[] = $this->statusColor($status);
        }

        return [
            'datasets' => [
                [
                    'data' => $data,
                    'backgroundColor' => $colors,
                ],
            ],
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
        ];
    }
}
