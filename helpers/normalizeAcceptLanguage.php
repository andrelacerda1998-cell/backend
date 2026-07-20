<?php

if (! function_exists('normalizeAcceptLanguage')) {
    /**
     * Normalize the given "Accept-Language" header value to a supported locale.
     *
     * This method takes an optional "Accept-Language" header string and determines
     * the best matching locale based on the application's configured locale options.
     * If no match is found, a default "pt-pt" locale is returned.
     *
     * Os valores devolvidos ('en'/'pt-pt') correspondem sempre a uma pasta de
     * tradução (resources/lang/*) e a config('app.locales'), garantindo que o
     * __() resolve o idioma correto e que LocaleRequest (in:en,pt-pt) passa.
     *
     * @param string|null $acceptLanguage Optionally, the "Accept-Language" header value.
     * @return string The normalized locale ('en' or 'pt-pt').
     */
    function normalizeAcceptLanguage(?string $acceptLanguage): string
    {
        if (! $acceptLanguage) {
            return 'pt-pt';
        }

        $locales = config('app.locales', ['en', 'pt-pt']);

        $languages = array_map(function ($item) {
            $parts = array_map('trim', explode(';', $item));

            return $parts[0];
        }, explode(',', $acceptLanguage));

        foreach ($languages as $lang) {
            if (in_array($lang, $locales)) {
                if ($lang === 'pt-pt' || $lang === 'pt-PT') {
                    return 'pt-pt';
                }

                return $lang;
            }

            $primaryLang = explode('-', $lang)[0];
            if ($primaryLang === 'pt') {
                return 'pt-pt';
            }
            if ($primaryLang === 'en') {
                return 'en';
            }
        }

        return 'pt-pt';
    }
}

