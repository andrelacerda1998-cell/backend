<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleLocale;
use App\Models\User;
use App\Support\Locale;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\TestCase;

/**
 * Regressão do bug "mensagens em inglês para utilizadores portugueses".
 *
 * O HandleLocale só aceitava a tag exata ('en' | 'pt-pt'); qualquer telemóvel a
 * enviar 'pt' ou 'pt-BR' — ou sem cabeçalho — caía no APP_LOCALE (=en). O mesmo
 * para os utilizadores gravados com language='pt', sem catálogo correspondente.
 */
class LocaleNormalizationTest extends TestCase
{
    public static function tagProvider(): array
    {
        return [
            'exata pt-pt'          => ['pt-pt', 'pt-pt'],
            'maiusculas pt-PT'     => ['pt-PT', 'pt-pt'],
            'underscore pt_PT'     => ['pt_PT', 'pt-pt'],
            'bare pt (o bug)'      => ['pt', 'pt-pt'],
            'brasileiro pt-BR'     => ['pt-BR', 'pt-pt'],
            'header com q-values'  => ['pt-PT,pt;q=0.9,en;q=0.8', 'pt-pt'],
            'ingles en'            => ['en', 'en'],
            'ingles en-US'         => ['en-US', 'en'],
            'ausente (null)'       => [null, 'pt-pt'],
            'vazio'                => ['', 'pt-pt'],
            'desconhecido'         => ['de-DE', 'pt-pt'],
        ];
    }

    /**
     * @dataProvider tagProvider
     */
    public function test_normalize_maps_language_tags_to_a_supported_locale(?string $input, string $expected): void
    {
        $this->assertSame($expected, Locale::normalize($input));
    }

    /**
     * @dataProvider tagProvider
     */
    public function test_middleware_sets_the_normalized_locale(?string $input, string $expected): void
    {
        $request = Request::create('/api/v1/common/app-version', 'GET');

        // Request::create() injeta um Accept-Language por omissão ('en-us,en;q=0.5'),
        // por isso o caso "sem cabeçalho" tem de o remover explicitamente.
        if ($input === null) {
            $request->headers->remove('Accept-Language');
        } else {
            $request->headers->set('Accept-Language', $input);
        }

        (new HandleLocale)->handle($request, fn () => new Response('ok'));

        $this->assertSame($expected, app()->getLocale());
    }

    /**
     * O accessor User::language normaliza o valor gravado. É isto que corrige as
     * ~24 notificações, que resolvem o idioma via `$notifiable->language` (cru) e
     * não via preferredLocale() — sem o accessor, um user com language='pt' recebe
     * as push em inglês.
     *
     * @dataProvider tagProvider
     */
    public function test_user_language_accessor_normalizes_the_stored_value(?string $input, string $expected): void
    {
        $user = new User;
        $user->setRawAttributes(['language' => $input]);

        $this->assertSame($expected, $user->language);
    }

    public function test_normalize_always_returns_a_supported_locale(): void
    {
        foreach (['pt', 'pt-BR', 'zz', '', null, 'en-GB'] as $tag) {
            $this->assertContains(Locale::normalize($tag), config('app.locales'));
        }
    }

    public function test_portuguese_catalogue_resolves_instead_of_falling_back_to_english(): void
    {
        // A chave existia só em en; o pt-pt tinha 'service_in_not_pending' (typo).
        $this->assertSame(
            'O serviço não está pendente.',
            __('exceptions.vendor.service.service_is_not_pending', [], 'pt-pt')
        );
        $this->assertSame(
            'O serviço não foi aceite.',
            __('exceptions.vendor.service.service_is_not_accepted', [], 'pt-pt')
        );
    }
}
