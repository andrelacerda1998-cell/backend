<?php

namespace App\Services\Matching;

use App\Models\GeneralSettings\ServicesType;
use App\Models\Service;
use Carbon\CarbonImmutable;

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
        /**
         * Instante em que o trabalho comeca; null = imediato ("agora").
         *
         * Entra aqui porque a cotacao depende dele: a faixa horaria multiplica
         * a mao de obra e tem de ser a do servico, nao a do momento em que o
         * ranking correu. Sem isto, o mesmo pedido cotado as 22:00 e as 10:00
         * dava dois precos diferentes para o mesmo trabalho.
         */
        public readonly ?CarbonImmutable $serviceAt = null,
        /**
         * Visita com varios servicos (cesto): todos os tipos. Elegivel e quem
         * faz TODOS. Vazio num pedido de um tipo so ou personalizado; nesse
         * caso manda `serviceType`.
         *
         * @var int[]
         */
        public readonly array $serviceTypeIds = [],
    ) {}

    /**
     * Para varios tipos de uma vez, fora de um `Service` (o plano de visitas
     * conta tecnicos antes de haver pedido).
     *
     * @param  array<int, array{type: ServicesType, quantity: int}>  $linhas
     */
    public static function forTypes(array $linhas, ?CarbonImmutable $serviceAt = null): self
    {
        $linhas = array_values($linhas);
        $minutos = array_map(fn (array $l) => (int) round(((float) ($l['type']->time ?? 0)) * max(1, (int) $l['quantity'])), $linhas);

        // O principal e o que leva mais tempo: e o mesmo criterio que a visita
        // grava em `services_type_id`.
        $principal = $linhas[array_search(max($minutos), $minutos, true)]['type'];

        return new self(
            $principal,
            array_values(array_unique(array_map(fn (array $l) => (int) $l['type']->operation_area_id, $linhas))),
            array_sum($minutos),
            $serviceAt,
            count($linhas) > 1 ? array_map(fn (array $l) => (int) $l['type']->id, $linhas) : [],
        );
    }

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

            return new self(null, $areas, $minutes, $service->scheduledAt());
        }

        // Visita do cesto com varias linhas: os tipos e os minutos congelados
        // no pedido, nao os do catalogo de agora.
        $service->loadMissing('items.serviceType', 'serviceType');

        if ($service->items->count() > 1) {
            return new self(
                $service->serviceType,
                $service->items->map(fn ($i) => (int) $i->serviceType->operation_area_id)->unique()->values()->all(),
                (int) $service->items->sum('minutes'),
                $service->scheduledAt(),
                $service->items->map(fn ($i) => (int) $i->services_type_id)->values()->all(),
            );
        }

        $type = $service->serviceType;

        if (! $type) {
            throw new \LogicException("Servico #{$service->id} sem tipo de servico.");
        }

        // A mesma conta do pricing (`effectiveMinutes`): tempo do tipo vezes
        // a quantidade pedida.
        $minutes = (int) round(((float) ($type->time ?? 0)) * max(1, (int) ($service->quantity ?? 1)));

        return new self($type, [(int) $type->operation_area_id], $minutes, $service->scheduledAt());
    }

    public function isCustom(): bool
    {
        return $this->serviceType === null;
    }

    public function isBundle(): bool
    {
        return count($this->serviceTypeIds) > 1;
    }

    /** Para logs. */
    public function label(): string
    {
        return match (true) {
            $this->isCustom() => 'personalizado[areas='.implode(',', $this->operationAreaIds).", {$this->minutes}min]",
            $this->isBundle() => 'cesto[tipos='.implode(',', $this->serviceTypeIds).", {$this->minutes}min]",
            default => "tipo#{$this->serviceType->id}",
        };
    }
}
