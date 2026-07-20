<?php

namespace App\Filament\Resources\ServicesResource\Pages;

use App\Filament\Resources\ServicesResource;
use App\Filament\Widgets\Services\ServiceStatusStats;
use Filament\Resources\Pages\ListRecords;

class ListServices extends ListRecords
{
    protected static string $resource = ServicesResource::class;

    protected function getHeaderActions(): array
    {
        return [
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ServiceStatusStats::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 4;
    }
}
