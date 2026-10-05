<?php

namespace App\Support;

use App\Models\Address;
use Illuminate\Support\Str;

/**
 * Quando é que duas moradas são A MESMA morada.
 *
 * O `AddressesController::store` criava sempre uma linha nova, sem ver se o
 * cliente já tinha aquela morada -- e a lista de moradas guardadas enchia-se de
 * repetidas. Para as reconhecer é preciso uma chave, e a chave tem de ignorar o
 * que é forma e manter o que é substância:
 *
 *  - IGNORA maiúsculas, acentos, pontuação e espaços a mais: "Rua da Prata, 80"
 *    e "rua da prata 80" são a mesma porta;
 *  - IGNORA o nome que o cliente lhe deu ("Casa", "Escritório"): é um rótulo, a
 *    porta é a mesma;
 *  - MANTÉM o andar (`additional_info`). O controlador existe para "um
 *    proprietário de vários alojamentos" que guarda uma morada por casa: o 3.º
 *    Esq e o 2.º Dto do mesmo prédio são casas diferentes, e fundi-las fazia um
 *    técnico bater à porta errada.
 *
 * O código postal fica só com os dígitos: "1100-414" e "1100414" são o mesmo.
 */
final class ChaveDeMorada
{
    /** @param  array<string, mixed>|Address  $morada */
    public static function de(array|Address $morada): string
    {
        $m = $morada instanceof Address ? $morada->getAttributes() : $morada;

        return implode('|', [
            self::texto($m['street_name'] ?? null),
            self::texto($m['street_number'] ?? null),
            preg_replace('/\D/', '', (string) ($m['postal_code'] ?? '')),
            self::texto($m['additional_info'] ?? null),
        ]);
    }

    /** Sem acentos, sem pontuação, minúsculas, um espaço entre palavras. */
    private static function texto(mixed $valor): string
    {
        // Ordinais fora ANTES do ascii: senão "3º" vira "3o" e deixa de casar
        // com "3", que é como metade das pessoas o escreve.
        $s = preg_replace('/[ºª°]/u', '', (string) ($valor ?? ''));
        $s = Str::ascii($s);
        $s = mb_strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);

        return trim(preg_replace('/\s+/', ' ', $s));
    }
}
