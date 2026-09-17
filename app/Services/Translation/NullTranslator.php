<?php

namespace App\Services\Translation;

/** Sem fornecedor configurado. Nao traduz e diz porque. */
class NullTranslator implements Translator
{
    public function translate(string $text, string $from, string $to): ?string
    {
        return null;
    }

    public function isConfigured(): bool
    {
        return false;
    }
}
