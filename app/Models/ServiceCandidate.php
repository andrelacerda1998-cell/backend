<?php

namespace App\Models;

use App\Enums\Services\CandidateStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um profissional considerado para um serviço — ver docs/matching.md.
 */
class ServiceCandidate extends Model
{
    protected $fillable = [
        'service_id',
        'vendor_id',
        'rank',
        'wave',
        'status',
        'rating_band',
        'rating_average',
        'rating_count',
        'quoted_amount',
        'quoted_amount_for_vendor',
        'quoted_distance',
        'is_new_vendor_slot',
        'notified_at',
        'responded_at',
        'expires_at',
    ];

    protected $casts = [
        'status' => CandidateStatus::class,
        'is_new_vendor_slot' => 'boolean',
        'quoted_distance' => 'decimal:2',
        'notified_at' => 'datetime',
        'responded_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** Ainda pode vir a ficar com o serviço. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            CandidateStatus::SHORTLISTED,
            CandidateStatus::NOTIFIED,
            CandidateStatus::ACCEPTED,
        ]);
    }

    public function scopeAccepted(Builder $query): Builder
    {
        return $query->where('status', CandidateStatus::ACCEPTED);
    }

    /**
     * Notificado, sem resposta, e com a janela já fechada.
     *
     * A expiração é avaliada por leitura e não por um job a marcar linhas: um
     * candidato cuja janela fechou não deve poder aceitar, mesmo que o job que
     * o havia de marcar esteja atrasado.
     */
    public function scopeStale(Builder $query): Builder
    {
        return $query->where('status', CandidateStatus::NOTIFIED)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
