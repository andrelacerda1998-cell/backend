<?php

namespace App\Services\Translation;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google Cloud Translation. Alternativa para quem ja tem projecto no Google
 * Cloud — a chave do Maps NAO serve: e outro produto, e tem de ser activado e
 * autorizado a parte.
 *
 * Nao distingue portugues europeu de brasileiro na saida; para PT-PT o DeepL e
 * melhor. Fica aqui para nao obrigar a abrir conta nova.
 */
class GoogleTranslator implements Translator
{
    public function __construct(private readonly ?string $key) {}

    public function isConfigured(): bool
    {
        return filled($this->key);
    }

    public function translate(string $text, string $from, string $to): ?string
    {
        if (! $this->isConfigured() || trim($text) === '') {
            return null;
        }

        try {
            $response = Http::timeout(10)->post('https://translation.googleapis.com/language/translate/v2', [
                'key' => $this->key,
                'q' => $text,
                'source' => $this->lang($from),
                'target' => $this->lang($to),
                'format' => 'text',
            ]);

            if (! $response->successful()) {
                Log::warning('[translation] google recusou', ['status' => $response->status()]);

                return null;
            }

            return $response->json('data.translations.0.translatedText');
        } catch (\Throwable $e) {
            Log::warning('[translation] google falhou', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /** Aceita so o idioma base: `pt-pt` -> `pt`. */
    private function lang(string $locale): string
    {
        return strtolower(explode('-', str_replace('_', '-', $locale))[0]);
    }
}
