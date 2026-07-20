<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use OwenIt\Auditing\Contracts\Auditable;

class NotificationCampaignLog extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'notification_campaign_id',
        'user_id',
        'sent_at',
        'success',
        'error_message',
        'opened_at',
        'deep_link_clicked_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'success' => 'boolean',
        'opened_at' => 'datetime',
        'deep_link_clicked_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(NotificationCampaign::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function service(): HasOne
    {
        return $this->hasOne(Service::class, 'campaign_log_id');
    }
}
