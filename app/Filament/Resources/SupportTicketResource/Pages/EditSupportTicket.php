<?php

namespace App\Filament\Resources\SupportTicketResource\Pages;

use App\Filament\Resources\SupportTicketResource;
use Filament\Resources\Pages\EditRecord;

class EditSupportTicket extends EditRecord
{
    protected static string $resource = SupportTicketResource::class;

    /** Ao responder, marca como respondido e carimba a data. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (! empty($data['admin_reply'])) {
            $data['replied_at'] = now();
            if (($data['status'] ?? 'open') === 'open') {
                $data['status'] = 'answered';
            }
        }

        return $data;
    }
}
