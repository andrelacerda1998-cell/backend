<?php

namespace App\Services\Matching;

use App\Models\GeneralSettings\ServicesType;
use App\Models\Service;

/**
 * O que o ranking precisa de saber sobre um pedido para escolher e para
 * cotar — sem depender de haver um tipo de catalogo.
 *
 * Ate aqui o ranking recebia um `ServicesType` e tirava dele tres coisas:
 * quem e elegivel (quem faz aquele tipo), em que area se leem as avaliacoes
 * (a do tipo) e quanto tempo leva (o `time` do tipo). Um pedido
 * personalizado nao tem tipo — tem as categorias e a duracao que o
 * backoffice definiu. Este objecto e o denominador comum dos dois.
 */
final class MatchingScope
{
    /**
     * @param  int[]  $operationAreaIds
     */
    public function __construct(
        public readonly ?ServicesType $serviceType,
        public readonly array $operationAreaIds,
        /** Duracao total a cotar e a reservar, em minutos (ja com a quantidade). */
        public readonly int $minutes,
    ) {}

    public static function forService(Service $service): self
    {
        if ($service->is_custom) {
            $minutes = (int) ($service->custom_duration_minutes ?? 0);
            $areas = $service->operationAreas()->pluck('operation_areas.id')->map(fn ($id) => (int) $id)->all();

            // Sem isto nao ha como cotar nem como saber quem convidar. Nao e
            // um caso a tratar em silencio: e o backoffice que ainda nao
            // preencheu o pedido, e o codigo que chegou aqui nao devia.
            if ($minutes <= 0 || $areas === []) {
                throw new \LogicException("Pedido personalizado #{$service->id} sem duracao ou sem categorias definidas.");
            }

            return new self(null, $areas, $minutes);
        }

        $service->loadMissing('serviceType');
        $type = $service->serviceType;

        if (! $type) {
            throw new \LogicException("Servico #{$service->id} sem tipo de servico.");
        }

        // A mesma conta do pricing (`effectiveMinutes`): tempo do tipo vezes
        // a quantidade pedida.
        $minutes = (int) round(((float) ($type->time ?? 0)) * max(1, (int) ($service->quantity ?? 1)));

        return new self($type, [(int) $type->operation_area_id], $minutes);
    }

    public function isCustom(): bool
    {
        return $this->serviceType === null;
    }

    /** Para logs. */
    public function label(): string
    {
        return $this->isCustom()
            ? 'personalizado[areas='.implode(',', $this->operationAreaIds).", {$this->minutes}min]"
            : "tipo#{$this->serviceType->id}";
    }
}
