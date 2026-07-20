<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class IbanRule implements ValidationRule
{
    public function validate(string $attribute, $value, Closure $fail): void
    {
        // Remove spaces and make uppercase
        $iban = strtoupper(str_replace(' ', '', $value));

        // Portuguese IBAN must start with 'PT', be 25 characters long
        if (strlen($iban) !== 25 || substr($iban, 0, 2) !== 'PT') {
            $fail("The $attribute must be a valid Portuguese IBAN.");
            return;
        }

        // Move first 4 chars to the end
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);

        // Replace letters with numbers (A=10, B=11, ..., Z=35)
        $numericIban = '';
        foreach (str_split($rearranged) as $char) {
            if (ctype_alpha($char)) {
                $numericIban .= strval(ord($char) - 55);
            } else {
                $numericIban .= $char;
            }
        }

        /*// IBAN is valid if mod 97 == 1
        if (bcmod($numericIban, '97') !== '1') {
            $fail("The $attribute must be a valid Portuguese IBAN.");
        }*/
    }
}
