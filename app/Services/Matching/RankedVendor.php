<?php

namespace App\Services\Matching;

use App\Models\Vendor;

/**
 * Um profissional já avaliado, orçamentado e posicionado para um serviço
 * concreto — ver docs/matching.md.
 *
 * O orçamento vem calculado de dentro: o preço que se mostra ao cliente tem de
 * ser o preço que se lhe cobra, e `calculateHourCommission()` muda com a hora do
 * dia. Recalcular mais tarde daria outro número.
 */
final class RankedVendor
{
    public function __construct(
        public readonly Vendor $vendor,
        /** null = ainda sem avaliações; NÃO é o mesmo que ter má avaliação. */
        public readonly ?float $ratingAverage,
        public readonly int $ratingCount,
        /** Índice da faixa: 0 é a melhor. null para quem ainda não tem avaliações. */
        public readonly ?int $ratingBand,
        public readonly float $distance,
        public readonly int $quotedAmount,
        public readonly int $quotedAmountForVendor,
        public bool $isNewVendorSlot = false,
        public int $rank = 0,
    ) {
    }

    public function isNewVendor(int $minRatings): bool
    {
        return $this->ratingCount < $minRatings;
    }

    /**
     * Chave de ordenação pela prioridade definida pelo negócio:
     * avaliação (por faixa), depois preço, depois distância.
     *
     * Quem não tem avaliações fica atrás de todas as faixas. Não é um castigo
     * — é a razão de existir a vaga reservada, que os traz de volta.
     */
    public function sortKey(int $bandCount): array
    {
        return [
            $this->ratingBand ?? $bandCount + 1,
            $this->quotedAmount,
            $this->distance,
        ];
    }
}
