<?php

namespace App\Models;

use App\Enums\Services\ServiceStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * O cesto depois de pedido: uma ou mais visitas que nasceram juntas.
 *
 * Não guarda dinheiro. Cada visita é um `Service` com o seu técnico, a sua
 * cativação e a sua cobrança — o caminho do dinheiro é o de qualquer pedido.
 * A encomenda existe para a app mostrar as visitas juntas e para se poderem
 * cancelar juntas enquanto ninguém pagou nada.
 */
class ServiceOrder extends Model
{
    use HasFactory, SoftDeletes;

    public const MODO_IMEDIATO = 'immediate';

    public const MODO_AGENDADO = 'scheduled';

    public const ABERTA = 'open';

    public const CONCLUIDA = 'done';

    public const CANCELADA = 'canceled';

    protected $fillable = [
        'customer_id',
        'address',
        'mode',
        'scheduled_day',
        'scheduled_time_start',
        'status',
        'is_test',
    ];

    protected $casts = [
        'address' => 'array',
        'scheduled_day' => 'date:Y-m-d',
        'is_test' => 'boolean',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Service::class, 'service_order_id')->orderBy('id');
    }

    public function isScheduled(): bool
    {
        return $this->mode === self::MODO_AGENDADO;
    }

    /**
     * Volta a ler o estado a partir das visitas.
     *
     * Guardado e não calculado à leitura porque a lista de encomendas do
     * cliente filtra por ele. Chama-se sempre que uma visita muda de estado
     * de uma forma que pode fechar a encomenda.
     */
    public function refreshStatus(): void
    {
        $estados = $this->visits()->pluck('status')
            ->map(fn ($s) => $s instanceof ServiceStatus ? $s : ServiceStatus::from($s));

        if ($estados->isEmpty()) {
            return;
        }

        $terminados = [ServiceStatus::CLOSED, ServiceStatus::CANCELED, ServiceStatus::MATCHING_FAILED];

        $status = match (true) {
            $estados->every(fn (ServiceStatus $s) => $s === ServiceStatus::CANCELED) => self::CANCELADA,
            $estados->every(fn (ServiceStatus $s) => in_array($s, $terminados, true)) => self::CONCLUIDA,
            default => self::ABERTA,
        };

        if ($status !== $this->status) {
            $this->forceFill(['status' => $status])->save();
        }
    }
}
