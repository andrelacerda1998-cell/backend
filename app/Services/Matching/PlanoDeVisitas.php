<?php

namespace App\Services\Matching;

use App\DTO\Services\AddressCoordinatesDTO;
use App\Models\GeneralSettings\ServicesType;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Divide um cesto em visitas, antes de pedir.
 *
 * Uma visita é um técnico a fazer vários serviços de uma vez — uma
 * deslocação, um pagamento. Só se juntam serviços que alguém na zona faz
 * TODOS, e só enquanto houver escolha: com um técnico possível, um "não" dele
 * deita a visita inteira abaixo, e dividir à partida é melhor do que falhar
 * depois de o cliente esperar.
 *
 * Quem é "possível" é decidido pelo ranking a sério (online, documentos,
 * agenda, raio, cidades de trabalho, pausa por cancelamentos) — a mesma
 * pergunta que o matching vai fazer, para o plano não prometer o que o
 * matching depois não encontra.
 *
 * O cliente vê o plano antes de pedir, e o servidor volta a fazê-lo no
 * momento de pedir: se mudou, devolve o novo e não cria nada.
 */
class PlanoDeVisitas
{
    /**
     * Técnicos que têm de fazer a visita inteira para se juntarem serviços.
     *
     * Decisão do André (06/10/2026): três. Com os técnicos de hoje, em Lisboa
     * isto junta a maior parte dos pares da mesma categoria e divide muitas
     * vezes entre categorias. Mais baixo junta mais e falha mais.
     */
    public const MIN_TECNICOS_PARA_JUNTAR = 3;

    public function __construct(private VendorRankingService $ranking) {}

    /**
     * @param  array<int, array{type: ServicesType, quantity: int}>  $linhas  pela ordem do cesto
     * @return array{visits: array<int, array{lines: array<int, array{type: ServicesType, quantity: int}>, eligible_vendors: int, from_price: ?int}>, unavailable: int[]}
     */
    public function planear(
        array $linhas,
        AddressCoordinatesDTO $morada,
        ?string $cidade,
        User $cliente,
        bool $imediato,
        ?CarbonImmutable $quando = null,
    ): array {
        $linhas = array_values($linhas);

        // Quem pode fazer cada serviço sozinho. É daqui que saem os
        // indisponíveis e as interseções; o ranking corre uma vez por linha.
        $quem = [];
        $indisponiveis = [];

        foreach ($linhas as $i => $linha) {
            $ids = $this->tecnicos([$linha], $morada, $cidade, $cliente, $imediato, $quando);

            if ($ids === []) {
                $indisponiveis[] = (int) $linha['type']->id;

                continue;
            }

            $quem[$i] = $ids;
        }

        // Pela duração, do maior para o menor: o serviço que mais pesa decide
        // a visita, e os pequenos juntam-se-lhe. A ordem do cesto desempata.
        $porFazer = array_keys($quem);
        usort($porFazer, fn (int $a, int $b) => [$this->minutos($linhas[$b]), $a] <=> [$this->minutos($linhas[$a]), $b]);

        $visitas = [];

        while ($porFazer !== []) {
            $primeiro = array_shift($porFazer);
            $naVisita = [$primeiro];
            $comuns = $quem[$primeiro];

            foreach ($porFazer as $k => $outro) {
                $juntos = array_values(array_intersect($comuns, $quem[$outro]));

                if ($this->podeJuntar(count($comuns), count($quem[$outro]), count($juntos))) {
                    $naVisita[] = $outro;
                    $comuns = $juntos;
                    unset($porFazer[$k]);
                }
            }

            $porFazer = array_values($porFazer);
            sort($naVisita);
            $visitas[] = $naVisita;
        }

        return [
            'visits' => $this->confirmar($visitas, $linhas, $morada, $cidade, $cliente, $imediato, $quando),
            'unavailable' => $indisponiveis,
        ];
    }

    /**
     * As linhas a partir do que a app manda, pela ordem do cesto.
     *
     * @param  array<int, array{service_type_id: int, quantity?: int}>  $items
     * @return array<int, array{type: ServicesType, quantity: int}>
     */
    public static function linhasDe(array $items): array
    {
        $tipos = ServicesType::whereIn('id', array_column($items, 'service_type_id'))->get()->keyBy('id');

        return array_values(array_map(fn (array $i) => [
            'type' => $tipos[(int) $i['service_type_id']],
            'quantity' => max(1, (int) ($i['quantity'] ?? 1)),
        ], $items));
    }

    /** O plano como a app o lê. */
    public static function payload(array $plano, ?string $language = null): array
    {
        $language = $language ?? app()->getLocale();

        return [
            'visits' => array_map(fn (array $v) => [
                'items' => array_map(fn (array $l) => [
                    'service_type_id' => (int) $l['type']->id,
                    'name' => $l['type']->getTranslation('name', $language),
                    'quantity' => $l['quantity'],
                ], $v['lines']),
                'eligible_vendors' => $v['eligible_vendors'],
                'from_price' => $v['from_price'],
            ], $plano['visits']),
            'unavailable' => $plano['unavailable'],
            'min_vendors_to_combine' => self::MIN_TECNICOS_PARA_JUNTAR,
        ];
    }

    /**
     * Duas formas do mesmo plano são o mesmo plano? Só conta que serviços vão
     * em que visita — não a ordem, nem quantos técnicos há (isso muda a cada
     * minuto sem mudar o que o cliente aceitou).
     *
     * @param  array<int, int[]>  $visitas  tipos por visita
     */
    public static function assinatura(array $visitas): array
    {
        $visitas = array_map(function (array $tipos) {
            $tipos = array_map('intval', $tipos);
            sort($tipos);

            return implode(',', $tipos);
        }, $visitas);
        sort($visitas);

        return $visitas;
    }

    /** @return array<int, int[]> */
    public static function tiposPorVisita(array $plano): array
    {
        return array_map(fn (array $v) => array_map(fn (array $l) => (int) $l['type']->id, $v['lines']), $plano['visits']);
    }

    /**
     * Juntar não pode tirar escolha a nenhum dos lados.
     *
     * Junta-se se, juntos, ficarem com três ou mais técnicos. Quando os DOIS
     * lados já tinham menos de três sozinhos, basta que juntos fiquem com
     * tantos como o maior deles tinha — não há escolha a perder. Nunca se
     * junta um serviço com quatro técnicos a outro com dois para dar uma
     * visita com dois: o de quatro perdia a escolha que tinha.
     */
    private function podeJuntar(int $daVisita, int $doServico, int $juntos): bool
    {
        if ($juntos === 0) {
            return false;
        }

        return $juntos >= min(self::MIN_TECNICOS_PARA_JUNTAR, max($daVisita, $doServico));
    }

    /**
     * A interseção diz quem faz todos os serviços; não diz se a agenda dele
     * tem espaço para os minutos somados, nem quanto custa a visita junta. O
     * ranking da visita inteira responde às duas. Se ninguém couber, a visita
     * desfaz-se em visitas de um serviço — que, sozinhos, já se sabe que têm
     * alguém.
     *
     * @param  array<int, int[]>  $visitas  índices das linhas
     */
    private function confirmar(array $visitas, array $linhas, AddressCoordinatesDTO $morada, ?string $cidade, User $cliente, bool $imediato, ?CarbonImmutable $quando): array
    {
        $resultado = [];

        foreach ($visitas as $indices) {
            $daVisita = array_map(fn (int $i) => $linhas[$i], $indices);
            $ranked = $this->ranked($daVisita, $morada, $cidade, $cliente, $imediato, $quando);

            if ($ranked->isEmpty() && count($indices) > 1) {
                foreach ($daVisita as $linha) {
                    $sozinha = $this->ranked([$linha], $morada, $cidade, $cliente, $imediato, $quando);
                    $resultado[] = $this->visita([$linha], $sozinha);
                }

                continue;
            }

            $resultado[] = $this->visita($daVisita, $ranked);
        }

        return $resultado;
    }

    private function visita(array $linhas, $ranked): array
    {
        return [
            'lines' => $linhas,
            'eligible_vendors' => $ranked->count(),
            // O mais barato de quem pode, como na lista de técnicos. Não é o
            // preço final: esse só existe quando alguém aceita.
            'from_price' => $ranked->isEmpty() ? null : (int) $ranked->min(fn (RankedVendor $r) => $r->quotedAmount),
        ];
    }

    /** @return int[] */
    private function tecnicos(array $linhas, AddressCoordinatesDTO $morada, ?string $cidade, User $cliente, bool $imediato, ?CarbonImmutable $quando): array
    {
        return $this->ranked($linhas, $morada, $cidade, $cliente, $imediato, $quando)
            ->map(fn (RankedVendor $r) => (int) $r->vendor->id)
            ->values()
            ->all();
    }

    private function ranked(array $linhas, AddressCoordinatesDTO $morada, ?string $cidade, User $cliente, bool $imediato, ?CarbonImmutable $quando)
    {
        return $this->ranking->rank(
            scope: MatchingScope::forTypes($linhas, $quando),
            address: $morada,
            customer: $cliente,
            immediate: $imediato,
            scheduledFor: $quando,
            cidadeDaMorada: $cidade,
        );
    }

    private function minutos(array $linha): int
    {
        return (int) round(((float) ($linha['type']->time ?? 0)) * max(1, (int) $linha['quantity']));
    }
}
