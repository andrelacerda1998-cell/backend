<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * A base de dados dos testes tem de ter o MESMO nome aqui e na CI.
 *
 * Enquanto o `phpunit.xml` dizia `testing` e a CI criava `piquet_test`,
 * qualquer acesso à base fora do `RefreshDatabase` passava na máquina de quem
 * escreveu o teste e falhava na CI com "Unknown database" — um erro de
 * configuração que se lê como código partido.
 *
 * Custou treze testes vermelhos e um deploy saltado a descobrir. Este teste
 * existe para não voltar a custar: se alguém mudar um dos lados, falha aqui,
 * com o nome dos dois lados à frente.
 */
class BaseDeTestesIgualNaCiTest extends TestCase
{
    private function baseDoPhpunit(): ?string
    {
        $xml = simplexml_load_file(base_path('phpunit.xml'));

        foreach ($xml->php->env ?? [] as $env) {
            if ((string) $env['name'] === 'DB_DATABASE') {
                return (string) $env['value'];
            }
        }

        return null;
    }

    private function baseDaCi(): ?string
    {
        $workflow = file_get_contents(base_path('.github/workflows/deploy.yml'));

        // O serviço de MySQL da CI declara a base que cria.
        preg_match('/MYSQL_DATABASE:\s*["\']?([a-z0-9_]+)["\']?/i', $workflow, $m);

        return $m[1] ?? null;
    }

    public function test_o_phpunit_e_a_ci_falam_da_mesma_base(): void
    {
        $phpunit = $this->baseDoPhpunit();
        $ci = $this->baseDaCi();

        $this->assertNotNull($phpunit, 'o phpunit.xml tem de fixar a base de dados dos testes');
        $this->assertNotNull($ci, 'o workflow tem de declarar a base que cria');

        $this->assertSame(
            $ci,
            $phpunit,
            "phpunit.xml usa '{$phpunit}' e a CI cria '{$ci}'. Com nomes diferentes, os testes que tocam a base fora do RefreshDatabase passam aqui e falham lá.",
        );
    }

    public function test_os_testes_nao_correm_contra_a_base_de_desenvolvimento(): void
    {
        // O `RefreshDatabase` apaga tudo o que encontra. Apontar os testes aos
        // dados de trabalho seria trocar o problema de cima por um pior.
        $this->assertNotSame(
            'piquet',
            $this->baseDoPhpunit(),
            'a base dos testes nunca pode ser a de desenvolvimento',
        );
    }

    public function test_e_essa_a_base_onde_os_testes_estao_mesmo_a_correr(): void
    {
        // Confirma que o valor do ficheiro chega mesmo à ligação: um override
        // no ambiente tornaria as duas verificações acima decorativas.
        $this->assertSame($this->baseDoPhpunit(), \DB::connection()->getDatabaseName());
    }
}
