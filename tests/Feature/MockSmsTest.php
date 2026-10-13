<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\MockSms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MOCK_SMS nunca em produção (ver App\Support\MockSms).
 *
 * Com a variável ligada por engano num deploy, o login por SMS aceitava 123456
 * para qualquer número: bastava saber o telefone de alguém para entrar na conta
 * dele. A validação do telefone aceitava-o também.
 */
class MockSmsTest extends TestCase
{
    use RefreshDatabase;

    private function cliente(array $atributos = []): User
    {
        return User::factory()->create(array_merge(['phone_number' => '+351912345678'], $atributos));
    }

    private function emProducao(): void
    {
        $this->app['env'] = 'production';
    }

    public function test_desligado_quando_a_variavel_esta_desligada(): void
    {
        config(['app.MOCK_SMS' => false]);

        $this->assertFalse(MockSms::ativo());
    }

    public function test_ligado_fora_de_producao(): void
    {
        config(['app.MOCK_SMS' => true]);

        $this->assertTrue(MockSms::ativo());
    }

    public function test_nunca_em_producao_mesmo_com_a_variavel_ligada(): void
    {
        config(['app.MOCK_SMS' => true]);
        $this->emProducao();

        $this->assertFalse(MockSms::ativo());
    }

    /** Controlo: fora de produção o modo a fingir continua a deixar entrar com 123456. */
    public function test_fora_de_producao_o_login_por_sms_aceita_o_codigo_de_teste(): void
    {
        config(['app.MOCK_SMS' => true]);
        $cliente = $this->cliente();

        $resposta = $this->postJson('/api/v1/auth/login/phone/verify', [
            'phone_number' => $cliente->phone_number,
            'code' => MockSms::CODIGO,
        ]);

        $resposta->assertOk();
        $this->assertNotEmpty($resposta->json('data.access_token'));
    }

    public function test_em_producao_o_login_por_sms_nao_aceita_o_codigo_de_teste(): void
    {
        config(['app.MOCK_SMS' => true]);
        $this->emProducao();
        $cliente = $this->cliente();

        $resposta = $this->postJson('/api/v1/auth/login/phone/verify', [
            'phone_number' => $cliente->phone_number,
            'code' => MockSms::CODIGO,
        ]);

        $this->assertNotSame(200, $resposta->status());
        $this->assertNull($resposta->json('data.access_token'));
    }

    public function test_em_producao_a_validacao_do_telefone_nao_aceita_o_codigo_de_teste(): void
    {
        config(['app.MOCK_SMS' => true]);
        $this->emProducao();
        $cliente = $this->cliente(['phone_number_verified_at' => null]);

        $this->actingAs($cliente, 'api')
            ->postJson('/api/v1/auth/sms-validation', ['code' => MockSms::CODIGO])
            ->assertForbidden();

        $this->assertNull($cliente->fresh()->phone_number_verified_at);
    }
}
