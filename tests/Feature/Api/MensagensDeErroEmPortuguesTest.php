<?php

namespace Tests\Feature\Api;

use App\Http\Responses\Api\ApiErrorResponse;
use Exception;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * As mensagens de erro da API chegam em portugues.
 *
 * O ApiErrorResponse passa a mensagem por __(), mas nao havia catalogo de
 * strings: `ApiErrorResponse(new Exception, 'Service is not accepted')` saia
 * tal e qual, e a app do tecnico mostrava-a ao utilizador. Um canalizador lia
 * "Service is not in execution".
 *
 * O catalogo e resources/lang/pt-pt.json, com a frase inglesa como chave —
 * assim nenhum controlador precisou de mudar e os que ainda nao existem ficam
 * cobertos assim que alguem acrescentar a chave.
 *
 * Este teste existe para o catalogo nao ficar para tras: se alguem acrescentar
 * uma mensagem nova em ingles e se esquecer da traducao, falha aqui.
 */
class MensagensDeErroEmPortuguesTest extends TestCase
{
    private function mensagemDe(string $original): string
    {
        $resposta = (new ApiErrorResponse(new Exception, $original, 422))
            ->toResponse(Request::create('/'));

        return json_decode($resposta->getContent(), true)['message'];
    }

    public static function mensagensUsadasNaApp(): array
    {
        return [
            ['Service is not accepted'],
            ['Service is not in execution'],
            ['Service not found'],
            ['Schedule not found'],
            ['Schedule is not confirmed yet'],
            ['Already disputed'],
            ['No-show not found'],
            ['Invitation not found'],
            ['Candidate not found'],
            ['Document not found'],
            ['Photo not found'],
            ['User not found'],
            ['Not found'],
            ['Something went wrong'],
            ['This professional is no longer available'],
            ['This request was already resolved'],
            ['You have already rated this service'],
            ['Only pending requests can be withdrawn'],
        ];
    }

    /**
     * @dataProvider mensagensUsadasNaApp
     */
    public function test_a_mensagem_sai_traduzida(string $original): void
    {
        app()->setLocale('pt-pt');

        $this->assertNotSame(
            $original,
            $this->mensagemDe($original),
            "A mensagem \"{$original}\" nao esta em resources/lang/pt-pt.json e ".
            'vai aparecer em ingles ao utilizador.',
        );
    }

    public function test_em_ingles_continua_a_sair_em_ingles(): void
    {
        // O catalogo traduz; nao substitui. Quem pedir 'en' recebe o original.
        app()->setLocale('en');

        $this->assertSame('Service is not accepted', $this->mensagemDe('Service is not accepted'));
    }

    public function test_o_catalogo_nao_tem_traducoes_vazias(): void
    {
        $catalogo = json_decode(file_get_contents(lang_path('pt-pt.json')), true);

        $this->assertIsArray($catalogo);

        foreach ($catalogo as $chave => $traducao) {
            $this->assertNotSame('', trim((string) $traducao), "Traducao vazia para \"{$chave}\".");
            $this->assertNotSame($chave, $traducao, "A traducao de \"{$chave}\" e igual ao original.");
        }
    }
}
