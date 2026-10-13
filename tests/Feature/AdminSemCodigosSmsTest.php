<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Os códigos SMS de login já não saem pela API de admin: eram devolvidos em
 * texto simples, chegavam para entrar na conta de qualquer cliente, e o
 * backoffice deixou de os usar. Quem precisar mesmo continua a ter o Filament,
 * só para super-admin.
 */
class AdminSemCodigosSmsTest extends TestCase
{
    public function test_a_rota_dos_codigos_sms_ja_nao_existe(): void
    {
        config(['services.admin_api.token' => 'a-valid-token']);

        $this->withHeaders(['Authorization' => 'Bearer a-valid-token'])
            ->getJson('/api/v1/admin/sms-codes')
            ->assertNotFound();
    }
}
