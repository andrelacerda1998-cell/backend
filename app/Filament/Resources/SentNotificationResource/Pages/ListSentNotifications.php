<?php

namespace App\Filament\Resources\SentNotificationResource\Pages;

use App\Filament\Resources\SentNotificationResource;
use Filament\Resources\Pages\ListRecords;

class ListSentNotifications extends ListRecords
{
    protected static string $resource = SentNotificationResource::class;

    protected function getHeaderActions(): array
    {
        return [
        ];
    }
}
