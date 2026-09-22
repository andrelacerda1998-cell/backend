<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * NIF portugues: nove digitos, primeiro digito valido e digito de controlo.
 *
 * O `$fail()` do Laravel REGISTA a falha e devolve o controlo — nao interrompe
 * o metodo. Esta regra tratava-o como se interrompesse: falhava por "NIF curto"
 * e continuava a correr, chegando ao ciclo do digito de controlo com um array
 * de 3 posicoes. O `$nifSplit[3]` dava ErrorException e a resposta ao cliente
 * era 500 em vez de 422.
 *
 * Ou seja: quem escrevesse o NIF a medias no ecra de faturacao nao via "NIF
 * invalido" — via "Something went wrong". Cada verificacao passou a sair com
 * `return`.
 */
class NifRule implements ValidationRule
{
    private const PRIMEIROS_DIGITOS_VALIDOS = ['1', '2', '3', '5', '6', '7', '8', '9'];

    public function validate(string $attribute, $value, Closure $fail): void
    {
        // Campo opcional: quem nao quer fatura com NIF deixa-o em branco.
        if (is_null($value) || $value === '') {
            return;
        }

        $nif = trim((string) $value);

        if (! ctype_digit($nif) || strlen($nif) !== 9) {
            $fail('The '.$attribute.' is invalid.');

            return;
        }

        $digitos = str_split($nif);

        if (! in_array($digitos[0], self::PRIMEIROS_DIGITOS_VALIDOS, true)) {
            $fail('The '.$attribute.' is invalid.');

            return;
        }

        $soma = 0;
        for ($i = 0; $i < 8; $i++) {
            $soma += ((int) $digitos[$i]) * (9 - $i);
        }

        $controlo = 11 - ($soma % 11);
        if ($controlo >= 10) {
            $controlo = 0;
        }

        if ($controlo !== (int) $digitos[8]) {
            $fail('The '.$attribute.' is invalid.');
        }
    }
}
