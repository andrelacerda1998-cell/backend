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
        'english_reviewed_at',
        'open_type',
        'open_id',
        'target_type', // 'vendor', 'customer', 'both'
        'user_status', // 'online', 'offline', 'both', null — SO se aplica a tecnicos
        // Filtros de estado. Todos anulaveis: a null nao filtram.
        'vendor_eligibility', // 'ready', 'incomplete', null
        'vendor_missing_schedule_address',
        'inactive_days',
        'customer_never_requested',
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
        'vendor_missing_schedule_address' => 'boolean',
        'customer_never_requested' => 'boolean',
        'inactive_days' => 'integer',
        'english_reviewed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Mexer no portugues invalida a revisao do ingles.
        //
        // Sem isto, alguem corrigia o texto original depois de a traducao estar
        // aprovada e o ingles ficava a dizer outra coisa — aprovado, e errado.
        // Um "revisto" que nao acompanha o que foi revisto e pior do que nao ter
        // revisao nenhuma: da confianca sem a merecer.
        // `updating` e nao `saving`: numa campanha NOVA nao ha revisao anterior
        // para invalidar. E ha uma razao pratica por cima da semantica — no
        // `saving`, o hook escrevia a coluna tambem no INSERT, e uma migracao
        // antiga que cria campanhas pelo modelo passava a inserir uma coluna
        // que, nesse ponto da historia, ainda nao existe. Modelos dentro de
        // migracoes veem sempre o schema de HOJE; as migracoes repetem o de
        // ontem.
        static::updating(function (self $campanha) {
            if ($campanha->isDirty(['title', 'body']) && ! $campanha->isDirty('english_reviewed_at')) {
                $campanha->english_reviewed_at = null;
            }
        });
    }

    /**
     * Ha ingles por rever?
     *
     * So conta como rascunho quando ha mesmo texto ingles: um campo vazio nao e
     * um rascunho, e nada.
     */
    public function englishIsDraft(): bool
    {
        $temIngles = filled(data_get($this->title, 'en')) || filled(data_get($this->body, 'en'));

        return $temIngles && $this->english_reviewed_at === null;
    }

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

        // Traducao por rever trava a CAMPANHA, nao o idioma de cada um.
        //
        // A alternativa era mandar portugues a quem tem o telemovel em ingles
        // enquanto ninguem revisse — e isso e esconder o problema no unico
        // sitio onde ja nao tem conserto. Assim, ou sai bem para todos, ou nao
        // sai; e quem faltava era uma pessoa a carregar num botao.
        if ($this->englishIsDraft()) {
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
