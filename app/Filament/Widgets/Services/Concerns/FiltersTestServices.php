<?php

namespace App\Filament\Widgets\Services\Concerns;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\Service;
use Illuminate\Database\Eloquent\Builder;

trait FiltersTestServices
{
    /**
     * Mirrors ServicesResource::getEloquentQuery() (trashed included, test
     * services hidden from non-developers) so dashboard numbers match the listing.
     */
    protected function serviceQuery(): Builder
    {
        return Service::withTrashed()
            ->when(! auth()->user()->hasRole('developer'), fn (Builder $q) => $q->where('is_test', false));
    }

    /**
     * Only genuinely paid requests. A services row is inserted the moment a
     * customer submits (before payment confirms), so counting raw rows inflates
     * "requests" with unpaid/abandoned/timed-out attempts. Filtering on
     * payment_status = PAID (regardless of current status) counts real requests
     * on the day they were placed.
     */
    protected function paidServiceQuery(): Builder
    {
        return $this->serviceQuery()->where('payment_status', PaymentStatus::PAID);
    }

    /**
     * Hex equivalents of the status badge colors used in ServicesResource
     * (warning/danger/success/gray), so every dashboard chart and the services
     * list agree on the color for each status.
     */
    protected function statusColor(ServiceStatus $status): string
    {
        return match ($status) {
            ServiceStatus::PENDING => '#f59e0b',
            ServiceStatus::CLOSED_PENDING_PAYMENT => '#d97706',
            ServiceStatus::CANCELED => '#ef4444',
            ServiceStatus::REFUSED => '#f87171',
            ServiceStatus::ACCEPTED => '#22c55e',
            ServiceStatus::ARRIVED => '#4ade80',
            ServiceStatus::FINISHED => '#16a34a',
            ServiceStatus::CLOSED => '#15803d',
            ServiceStatus::SCHEDULED => '#86efac',
            ServiceStatus::PENDING_3DS => '#a1a1aa',
            ServiceStatus::ARCHIVED => '#71717a',
            ServiceStatus::REFUSED_MBWAY => '#dc2626',
            ServiceStatus::EXPIRED_MBWAY => '#9ca3af',
            ServiceStatus::CANCELED_MBWAY => '#fca5a5',
        };
    }
}
