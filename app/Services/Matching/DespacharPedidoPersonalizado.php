<?php

namespace App\Services\Matching;

use App\Enums\Services\ServiceStatus;
use App\Models\Service;
use App\Notifications\Customer\CustomRequestDispatchedNotification;
use Illuminate\Support\Facades\DB;

/**
 * Despachar um pedido personalizado: a parte da Piquet.
 *
 * Até aqui o pedido está em análise e nenhum profissional sabe que existe. Ao
 * despachar, fica com a duração e as categorias que a equipa definiu, entra em
 * seleção e sai a primeira onda de convites -- o mesmo caminho de um pedido de
 * catálogo. Se ninguém for elegível, falha já e o cliente é avisado, como o
 * start() faz num pedido de catálogo.
 *
 * Partilhado pelo Filament (ViewService) e pela API de admin do backoffice:
 * as duas portas fazem exatamente o mesmo.
 */
class DespacharPedidoPersonalizado
{
    /** Uma duração abaixo disto não é um trabalho, é um engano. */
    public const MINUTOS_MINIMOS = 15;

    public function __construct(private MatchingService $matching) {}

    /**
     * @param  array<int|string>  $areas  ids das categorias (áreas de operação)
     * @return int quantos profissionais foram convidados (0 = falhou por não haver ninguém)
     *
     * @throws \DomainException quando o pedido não está à espera de ser despachado
     */
    public function __invoke(Service $service, int $minutos, array $areas): int
    {
        if ($minutos < self::MINUTOS_MINIMOS) {
            throw new \InvalidArgumentException('A duração tem de ser de pelo menos '.self::MINUTOS_MINIMOS.' minutos.');
        }
        if ($areas === []) {
            throw new \InvalidArgumentException('Escolhe pelo menos uma categoria.');
        }

        return DB::transaction(function () use ($service, $minutos, $areas): int {
            // Trancar a linha: dois cliques (ou o Filament e o backoffice ao
            // mesmo tempo) não podem despachar o mesmo pedido duas vezes.
            $locked = Service::whereKey($service->getKey())->lockForUpdate()->first();
            if (! $locked || ! $locked->is_custom || $locked->status !== ServiceStatus::PENDING_REVIEW) {
                throw new \DomainException('Este pedido já não está à espera de ser despachado.', 409);
            }

            $service->refresh();
            $service->custom_duration_minutes = $minutos;
            $service->custom_dispatched_at = now();
            $service->status = ServiceStatus::MATCHING;
            $service->save();
            $service->operationAreas()->sync(array_map('intval', $areas));

            // O cliente esteve à espera sem saber de nada: este aviso marca o
            // momento em que uma PESSOA pegou no pedido dele.
            $service->customer?->notify(new CustomRequestDispatchedNotification($service));

            $candidates = $this->matching->dispatchNextWave($service->refresh());

            // Ninguém elegível: falha já e avisa o cliente. Deixá-lo em seleção
            // seria uma espera que nunca resolve.
            if ($candidates->isEmpty()) {
                $this->matching->fail($service);
            }

            return $candidates->count();
        });
    }
}
