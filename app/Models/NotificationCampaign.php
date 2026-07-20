<?php

namespace App\Models;

use App\Enums\Services\ServiceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;

class NotificationCampaign extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable, SoftDeletes;

    protected $fillable = [
        'name',
        'title',
        'body',
        'open_type',
        'open_id',
        'target_type', // 'vendor', 'customer', 'both'
        'user_status', // 'online', 'offline', 'both', null
        'frequency_type', // 'once', 'daily', 'weekly', 'custom'
        'frequency_value', // numeric value for custom frequency
        'frequency_unit', // 'minutes', 'hours', 'days' for custom frequency
        'is_active',
        'starts_at',
        'ends_at',
        'last_sent_at',
        'next_send_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'last_sent_at' => 'datetime',
        'next_send_at' => 'datetime',
        'title' => 'array',
        'body' => 'array',
    ];

    public function logs(): HasMany
    {
        return $this->hasMany(NotificationCampaignLog::class);
    }

    public function optOuts(): HasMany
    {
        return $this->hasMany(NotificationCampaignOptOut::class);
    }

    public function openRate(): float
    {
        $sent = $this->logs()->where('success', true)->count();
        if ($sent === 0) {
            return 0.0;
        }
        $opened = $this->logs()->whereNotNull('opened_at')->count();

        return round(($opened / $sent) * 100, 2);
    }

    public function conversionRate(): float
    {
        $opened = $this->logs()->whereNotNull('opened_at')->count();
        if ($opened === 0) {
            return 0.0;
        }
        $converted = Service::whereHas('campaignLog', function ($q) {
            $q->where('notification_campaign_id', $this->id);
        })->where('status', ServiceStatus::CLOSED)->count();

        return round(($converted / $opened) * 100, 2);
    }

    public function optOutRate(): float
    {
        $sent = $this->logs()->where('success', true)->count();
        if ($sent === 0) {
            return 0.0;
        }
        $sentUserIds = $this->logs()->where('success', true)->pluck('user_id');
        $optOuts = NotificationCampaignOptOut::where(function ($q) use ($sentUserIds) {
            $q->whereIn('user_id', $sentUserIds)
                ->where(function ($inner) {
                    $inner->whereNull('notification_campaign_id')
                        ->orWhere('notification_campaign_id', $this->id);
                });
        })->count();

        return round(($optOuts / $sent) * 100, 2);
    }

    public function revenuePerSend(): float
    {
        $sent = $this->logs()->where('success', true)->count();
        if ($sent === 0) {
            return 0.0;
        }
        $revenue = Service::whereHas('campaignLog', function ($q) {
            $q->where('notification_campaign_id', $this->id);
        })->where('status', ServiceStatus::CLOSED)->sum('amount');

        return round($revenue / $sent / 100, 2);
    }

    public function clickToServiceRate(): float
    {
        $clicks = $this->logs()->whereNotNull('deep_link_clicked_at')->count();
        if ($clicks === 0) {
            return 0.0;
        }
        $services = Service::whereHas('campaignLog', function ($q) {
            $q->where('notification_campaign_id', $this->id);
        })->count();

        return round(($services / $clicks) * 100, 2);
    }

    public function shouldSend(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $now = now();

        if ($this->starts_at && $now->lt($this->starts_at)) {
            return false;
        }

        if ($this->ends_at && $now->gt($this->ends_at)) {
            return false;
        }

        if ($this->next_send_at && $now->lt($this->next_send_at)) {
            return false;
        }

        return true;
    }

    public function calculateNextSend(): ?\DateTime
    {
        if ($this->frequency_type === 'once') {
            return null;
        }

        $now = now();

        return match ($this->frequency_type) {
            'daily' => $now->copy()->addDay(),
            'weekly' => $now->copy()->addWeek(),
            'custom' => match ($this->frequency_unit ?? 'minutes') {
                'minutes' => $now->copy()->addMinutes($this->frequency_value ?? 60),
                'hours' => $now->copy()->addHours($this->frequency_value ?? 1),
                'days' => $now->copy()->addDays($this->frequency_value ?? 1),
                default => $now->copy()->addMinutes($this->frequency_value ?? 60),
            },
            default => null,
        };
    }
}
