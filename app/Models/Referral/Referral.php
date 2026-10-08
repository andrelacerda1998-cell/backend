<?php

namespace App\Models\Referral;

use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Um convite: quem convidou, quem foi convidado, e se já valeu a recompensa. */
class Referral extends Model
{
    public const PENDENTE = 'pendente';

    public const CONCLUIDO = 'concluido';

    public const ANULADO = 'anulado';

    /** O amigo pagou, mas quem convidou já tinha as recompensas todas do ano. */
    public const SEM_RECOMPENSA = 'sem_recompensa';

    protected $fillable = [
        'referrer_user_id', 'referred_user_id', 'referred_phone', 'code',
        'first_service_id', 'status', 'cancel_reason', 'completed_at', 'canceled_at',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
        'canceled_at' => 'datetime',
    ];

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_user_id');
    }

    public function referred(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }

    public function firstService(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'first_service_id');
    }
}
