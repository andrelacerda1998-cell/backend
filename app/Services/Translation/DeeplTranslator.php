<?php

namespace App\Services\Translation;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * DeepL. Escolhido por defeito para PT-PT: distingue portugues europeu de
 * brasileiro (`PT-PT` vs `PT-BR`), o que a maioria dos tradutores nao faz — e
 * um push em brasileiro nota-se de imediato.
 *
 * O host muda entre a conta gratuita (`api-free`) e a paga (`api`); fica em
 * config para se trocar sem mexer em codigo.
 */
class DeeplTranslator implements Translator
{
    public function __construct(
        private readonly ?string $key,
        private readonly string $host,
    ) {}

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
            $response = Http::asForm()
                ->timeout(10)
                ->withHeaders(['Authorization' => 'DeepL-Auth-Key '.$this->key])
                ->post($this->host.'/v2/translate', [
                    'text' => $text,
                    'source_lang' => $this->lang($from),
                    'target_lang' => $this->lang($to),
                ]);

            if (! $response->successful()) {
                Log::warning('[translation] deepl recusou', ['status' => $response->status()]);

                return null;
            }

            return $response->json('translations.0.text');
        } catch (\Throwable $e) {
            // Nao rebenta o ecra por causa de uma conveniencia.
            Log::warning('[translation] deepl falhou', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /** `pt-pt` -> `PT-PT`, `en` -> `EN`. A origem nao aceita variante. */
    private function lang(string $locale): string
    {
        return strtoupper(str_replace('_', '-', $locale));
    }
}
