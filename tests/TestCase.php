<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Nenhum teste fala com o Meilisearch — e a CI nem sequer o sobe (só MySQL
     * e Redis, ver .github/workflows/deploy.yml).
     *
     * Criar um Vendor dispara uma cadeia de observers
     * (VendorObserver → ScheduleAvailable → ScheduleAvailableObserver) que tenta
     * indexar. Sem servidor, cada um desses testes rebenta com
     * "cURL error 7: Failed to connect to 127.0.0.1 port 7700" — um erro de
     * infraestrutura que se lê como se o código estivesse partido.
     *
     * Catorze classes já desligavam isto uma a uma; as que se esqueciam
     * falhavam na CI sem ninguém perceber porquê. Fica aqui, uma vez, para
     * ninguém mais tropeçar: um teste que precise mesmo do Meilisearch
     * sobrepõe-no no seu próprio setUp.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['scout.driver' => 'null']);
    }
}
