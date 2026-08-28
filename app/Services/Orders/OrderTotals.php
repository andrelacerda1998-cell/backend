<?php

namespace App\Services\Orders;

/**
 * Aritmética de um pedido com VÁRIOS serviços cobrados NUMA só transação.
 *
 * Hoje cada serviço tem o seu pagamento (1:1). Para "vários serviços, um só
 * pagamento" o cliente paga a SOMA de uma vez, mas cada técnico continua a
 * receber a parte do SEU serviço — a liquidação não muda por serviço, só a
 * cobrança é que passa a ser única.
 *
 * NÚCLEO PURO de propósito: só faz contas sobre cêntimos. Sem BD, sem Payshop.
 * É a peça que, se estiver errada, cobra a mais ou paga a menos — por isso vive
 * isolada e testada, e o resto (esquema, captura) apoia-se nela.
 *
 * Cada linha é: ['customer_amount' => int cêntimos, 'vendor_amount' => int cêntimos].
 */
class OrderTotals
{
    /**
     * Total que o cliente paga UMA vez = soma do que cada serviço custaria.
     */
    public static function customerTotal(array $lines): int
    {
        return array_sum(array_map(
            fn ($l) => max(0, (int) ($l['customer_amount'] ?? 0)),
            $lines
        ));
    }

    /**
     * Quanto vai para os técnicos no total = soma das partes de cada serviço.
     * (Cada técnico recebe a SUA na liquidação; isto é só a soma para conferência.)
     */
    public static function vendorTotal(array $lines): int
    {
        return array_sum(array_map(
            fn ($l) => max(0, (int) ($l['vendor_amount'] ?? 0)),
            $lines
        ));
    }

    /**
     * O que fica para a plataforma = total do cliente − total dos técnicos.
     * Nunca negativo: se por erro de dados a soma dos técnicos exceder o total,
     * corta-se a zero em vez de "criar" dívida da plataforma.
     */
    public static function platformTotal(array $lines): int
    {
        return max(0, self::customerTotal($lines) - self::vendorTotal($lines));
    }

    /**
     * Distribui um desconto de pedido (cupão aplicado ao TOTAL) pelos serviços,
     * proporcionalmente ao peso de cada um, sem criar nem perder cêntimos.
     *
     * O último serviço leva o resto do arredondamento, para a soma das partes
     * bater CERTO com o desconto total — repartir e arredondar cada um à parte
     * deixaria o pedido a somar 1 ou 2 cêntimos ao lado.
     *
     * @return int[] desconto por linha, na mesma ordem; soma == $discount
     */
    public static function distributeDiscount(array $lines, int $discount): array
    {
        $discount = max(0, $discount);
        $total = self::customerTotal($lines);
        if ($total <= 0 || $discount <= 0) {
            return array_fill(0, count($lines), 0);
        }

        $out = [];
        $allocated = 0;
        $n = count($lines);
        foreach (array_values($lines) as $i => $line) {
            if ($i === $n - 1) {
                // último: leva exatamente o que falta, absorve o arredondamento
                $out[] = $discount - $allocated;
                break;
            }
            $weight = max(0, (int) ($line['customer_amount'] ?? 0));
            $share = (int) floor($discount * $weight / $total);
            $out[] = $share;
            $allocated += $share;
        }

        return $out;
    }
}
