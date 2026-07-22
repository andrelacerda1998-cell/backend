<?php

namespace App\Support;

/**
 * Normalização de tags de idioma.
 *
 * O bug que isto resolve: o HandleLocale só aceitava a tag EXATA ('en' ou 'pt-pt').
 * Um telemóvel a enviar 'pt', 'pt-BR' ou sem cabeçalho Accept-Language caía no
 * APP_LOCALE (=en) e recebia as mensagens em inglês. O mesmo acontecia com os
 * utilizadores gravados com language='pt', para os quais não existe catálogo.
 *
 * Regra: casar pelo subtag primário ('pt-BR' -> 'pt-pt') e, quando nada casa,
 * assumir PORTUGUÊS por omissão (decisão do Ederico, 2026-07-18).
 */
class Locale
{
    public const DEFAULT = 'pt-pt';

    /**
     * @return string uma tag garantidamente presente em config('app.locales')
     */
    public static function normalize(?string $tag): string
    {
        /** @var array<int, string> $supported */
        $supported = config('app.locales', ['en', self::DEFAULT]);

        // 'pt-PT,pt;q=0.9' -> 'pt-pt'
        $tag = strtolower(trim((string) $tag));
        $tag = trim(explode(',', $tag)[0]);
        $tag = trim(explode(';', $tag)[0]);
        $tag = str_replace('_', '-', $tag);

        if ($tag !== '' && in_array($tag, $supported, true)) {
            return $tag;
        }

        // Casar pelo subtag primário: 'pt-br' -> 'pt-pt', 'en-us' -> 'en'.
        $primary = explode('-', $tag)[0];

        if ($primary !== '') {
            foreach ($supported as $candidate) {
                if (explode('-', $candidate)[0] === $primary) {
                    return $candidate;
                }
            }
        }

        return in_array(self::DEFAULT, $supported, true)
            ? self::DEFAULT
            : ($supported[0] ?? 'en');
    }
}
