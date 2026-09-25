<?php

namespace Tests\Unit;

use App\Services\Customer\Services\VendorSearchService;
use Tests\TestCase;

/**
 * O MOCK_LOCATION não pode pegar em produção.
 *
 * Ligado, o `VendorSearchService` devolve o índice inteiro: sem raio geográfico,
 * sem filtro de tipo de serviço, sem `status = Online`, sem a janela dos 60
 * minutos e sem ordenação nenhuma. Em desenvolvimento é o que se quer — poupa
 * ter um técnico a mandar localização. Em produção seria oferecer ao cliente
 * profissionais offline, de outra especialidade, a qualquer distância e por
 * ordem arbitrária.
 *
 * É a mesma guarda que o MOCK_SMS já usa. Este teste existe porque a diferença
 * entre as duas configurações não dá erro nenhum — dá resultados errados.
 */
class LocalizacaoSimuladaTest extends TestCase
{
    public function test_em_desenvolvimento_a_simulacao_pega(): void
    {
        config(['services.request.mock_location' => true]);
        $this->app->detectEnvironment(fn () => 'local');

        $this->assertTrue(app(VendorSearchService::class)->usaLocalizacaoSimulada());
    }

    public function test_em_producao_a_simulacao_nao_pega(): void
    {
        config(['services.request.mock_location' => true]);
        $this->app->detectEnvironment(fn () => 'production');

        $this->assertFalse(
            app(VendorSearchService::class)->usaLocalizacaoSimulada(),
            'MOCK_LOCATION ligado num deploy de produção não pode desligar os filtros da procura.',
        );
    }

    public function test_desligado_nao_pega_em_lado_nenhum(): void
    {
        config(['services.request.mock_location' => false]);

        foreach (['local', 'staging', 'production'] as $ambiente) {
            $this->app->detectEnvironment(fn () => $ambiente);

            $this->assertFalse(
                app(VendorSearchService::class)->usaLocalizacaoSimulada(),
                "MOCK_LOCATION desligado não devia pegar em {$ambiente}.",
            );
        }
    }
}
