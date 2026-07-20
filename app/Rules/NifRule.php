<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class NifRule implements ValidationRule
{
    public function validate(string $attribute, $value, Closure $fail): void
    {
        if (is_null($value)) {
            return;
        }
        if (strlen($value) < 9) {
            $fail('The '.$attribute.' is invalid.');
        }
        if (is_null($value)) {
            $fail('The '.$attribute.' is invalid.');
        }

        $nif = trim($value);

        if (! is_numeric($nif) || strlen($nif) != 9) {
            $fail('The '.$attribute.' is invalid.');
        }

        $nifSplit = str_split($nif);
        if (!is_array($nifSplit)){
            $fail('The '.$attribute.' is invalid.');
        }
        if (in_array($nifSplit[0], [1, 2, 3, 5, 6, 7, 8, 9])) {
            $checkDigit = 0;

            for ($i = 0; $i < 8; $i++) {
                $checkDigit += $nifSplit[$i] * (10 - $i - 1);
            }

            $checkDigit = 11 - ($checkDigit % 11);

            if ($checkDigit >= 10) {
                $checkDigit = 0;
            }

            if ($checkDigit == $nifSplit[8]) {
                return;
            }
        }
        $fail('The '.$attribute.' is invalid.');
    }
}
