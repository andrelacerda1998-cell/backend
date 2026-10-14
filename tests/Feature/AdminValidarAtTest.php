<?php

namespace Tests\Feature;

use App\Enums\Services\AddressType;
use App\Models\Address;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\FakeInvoiceVendorService;
use Tests\TestCase;

/**
 * PUT /v1/admin/vendors/{vendor}/at-validation -- os botões "Validar AT" e
 * "Retirar AT" do backoffice. Chamavam esta rota antes de ela existir.
 *
 * Validar é o que o Filament faz: testar as credenciais no InvoiceXpress
 * (aqui, num duplo em memória) e guardar o resultado. Não é um carimbo.
 */
class AdminValidarAtTest extends TestCase
{
    use RefreshDatabase;

    private FakeInvoiceVendorService $invoiceXpress;

    protected function setUp(): void
    {
        parent::setUp();

        $this->invoiceXpress = FakeInvoiceVendorService::ligar();
    }

    private function withAuth(): static
    {
        config(['services.admin_api.token' => 'a-valid-token']);

        return $this->withHeaders(['Authorization' => 'Bearer a-valid-token']);
    }

    /** Um técnico com tudo o que a validação precisa, menos o que o teste mudar. */
    private function tecnico(array $dados = [], bool $morada = true): Vendor
    {
        $user = User::factory()->create();

        if ($morada) {
            Address::create([
                'user_id' => $user->id,
                'street_name' => 'Rua do Teste',
                'street_number' => '1',
                'postal_code' => '1000-001',
                'city' => 'Lisboa',
                'state' => 'Lisboa',
                'country' => 'Portugal',
                'address_type' => AddressType::FISCAL_ADDRESS,
            ]);
        }

        $vendor = Vendor::create(['user_id' => $user->id, 'username' => 'tec_'.$user->id]);
        $vendor->forceFill(array_merge([
            'invoice_workspace' => 'ws-teste',
            'auth_token' => 'chave-de-teste',
            'at_user' => '123456789/1',
            'at_password' => 'segredo',
            'at_valid' => false,
        ], $dados))->save();

        return $vendor->fresh();
    }

    private function validar(Vendor $vendor, mixed $valid = true)
    {
        return $this->withAuth()->putJson("/api/v1/admin/vendors/{$vendor->id}/at-validation", ['valid' => $valid]);
    }

    public function test_validar_testa_as_credenciais_e_grava_o_resultado(): void
    {
        Carbon::setTestNow('2026-10-14 15:00:00');
        $vendor = $this->tecnico();

        $this->validar($vendor)
            ->assertOk()
            ->assertJsonPath('data.id', $vendor->id)
            ->assertJsonPath('data.at_valid', true)
            ->assertJsonPath('data.at_validated_at', now()->toIso8601String());

        $this->assertSame(['dados_fiscais', 'comunicacao_at', 'serie'], $this->invoiceXpress->passos());
        $this->assertTrue($vendor->fresh()->at_valid);
    }

    /** Quem já tinha a série criada: o InvoiceXpress diz que existe, e isso basta. */
    public function test_a_serie_que_ja_existe_nao_impede_a_validacao(): void
    {
        $this->invoiceXpress->serieJaExiste = true;
        $vendor = $this->tecnico();

        $this->validar($vendor)->assertOk()->assertJsonPath('data.at_valid', true);
        $this->assertTrue($vendor->fresh()->at_valid);
    }

    public function test_credenciais_recusadas_deixam_a_at_por_validar_e_dizem_porque(): void
    {
        $quandoFuncionou = Carbon::parse('2026-10-01 10:00:00');
        $vendor = $this->tecnico(['at_valid' => true, 'at_validated_at' => $quandoFuncionou]);
        $this->invoiceXpress->credenciaisDaAtRecusadas = true;

        $resposta = $this->validar($vendor)->assertStatus(422);

        $this->assertStringContainsString('recusou as credenciais', $resposta->json('message'));
        $this->assertFalse($vendor->fresh()->at_valid);
        // A data é a da última vez que funcionou, e continua a ser verdade.
        $this->assertTrue($vendor->fresh()->at_validated_at->equalTo($quandoFuncionou));
    }

    /** Uma falha que não é das credenciais diz o que o InvoiceXpress respondeu. */
    public function test_outra_falha_do_invoicexpress_chega_ao_backoffice(): void
    {
        $this->invoiceXpress->falhaNaComunicacaoComAt = new \Exception('AT communication failed: timeout');
        $vendor = $this->tecnico();

        $resposta = $this->validar($vendor)->assertStatus(502);

        $this->assertStringContainsString('timeout', $resposta->json('message'));
        $this->assertFalse($vendor->fresh()->at_valid);
    }

    public function test_sem_conta_de_faturacao_nem_chega_ao_invoicexpress(): void
    {
        // A coluna não aceita nulo: "sem workspace" é texto vazio.
        $vendor = $this->tecnico(['invoice_workspace' => '', 'auth_token' => null]);

        $resposta = $this->validar($vendor)->assertStatus(409);

        $this->assertStringContainsString('workspace', $resposta->json('message'));
        $this->assertSame([], $this->invoiceXpress->passos());
    }

    public function test_sem_subutilizador_nao_ha_nada_a_validar(): void
    {
        $vendor = $this->tecnico(['at_user' => null]);

        $this->validar($vendor)->assertStatus(409);
        $this->assertSame([], $this->invoiceXpress->passos());
    }

    /**
     * Sem a barra, o `at_ready` nunca fica verdadeiro: validar marcava a AT como
     * válida e o técnico continuava travado.
     */
    public function test_subutilizador_sem_barra_nao_se_valida(): void
    {
        $vendor = $this->tecnico(['at_user' => '123456789']);

        $resposta = $this->validar($vendor)->assertStatus(409);

        $this->assertStringContainsString('NIF/número', $resposta->json('message'));
        $this->assertSame([], $this->invoiceXpress->passos());
        $this->assertFalse($vendor->fresh()->at_valid);
    }

    /**
     * Sem morada nenhuma, os dados fiscais não têm o que mandar. Respondia 502 com
     * o erro do serviço em inglês ("Address is not set"), como se o InvoiceXpress
     * tivesse falhado. Visto no ensaio de ponta a ponta de 14/10.
     */
    public function test_sem_morada_diz_o_que_falta_sem_chegar_ao_invoicexpress(): void
    {
        $vendor = $this->tecnico([], morada: false);

        $resposta = $this->validar($vendor)->assertStatus(409);

        $this->assertStringContainsString('não tem morada', $resposta->json('message'));
        $this->assertSame([], $this->invoiceXpress->passos());
        $this->assertFalse($vendor->fresh()->at_valid);
    }

    public function test_retirar_a_validacao_mantem_a_data_da_ultima_vez_que_funcionou(): void
    {
        $quandoFuncionou = Carbon::parse('2026-10-01 10:00:00');
        $vendor = $this->tecnico(['at_valid' => true, 'at_validated_at' => $quandoFuncionou]);

        $this->validar($vendor, false)
            ->assertOk()
            ->assertJsonPath('data.at_valid', false)
            ->assertJsonPath('data.at_validated_at', $quandoFuncionou->toIso8601String());

        $this->assertSame([], $this->invoiceXpress->passos());
        $this->assertFalse($vendor->fresh()->at_valid);
    }

    public function test_sem_dizer_se_valida_ou_retira_e_erro(): void
    {
        $vendor = $this->tecnico();

        $this->withAuth()
            ->putJson("/api/v1/admin/vendors/{$vendor->id}/at-validation", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['valid']);

        $this->assertSame([], $this->invoiceXpress->passos());
    }

    public function test_tecnico_que_nao_existe(): void
    {
        $this->withAuth()
            ->putJson('/api/v1/admin/vendors/999999/at-validation', ['valid' => true])
            ->assertNotFound();
    }
}
