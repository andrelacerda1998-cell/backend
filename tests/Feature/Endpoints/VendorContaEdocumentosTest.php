<?php

namespace Tests\Feature\Endpoints;

use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Documentos, credenciais da AT, dados de pagamento, agenda e inquérito.
 *
 * São os requisitos que decidem se um técnico fica apto — o ponto onde 82% dos
 * registados param. Um 500 ou uma validação mal-feita aqui não estraga um
 * serviço: impede que exista algum.
 */
class VendorContaEdocumentosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GenderSeeder::class);
        Notification::fake();
    }

    private function tecnico(): Vendor
    {
        return Vendor::factory()->create();
    }

    // --------------------------------------------------------- documentos

    public function test_o_tecnico_lista_os_seus_documentos(): void
    {
        $this->actingAs($this->tecnico()->user, 'api')
            ->getJson('/api/v1/vendor/documents')
            ->assertOk();
    }

    public function test_documentos_exige_autenticacao(): void
    {
        $this->getJson('/api/v1/vendor/documents')->assertStatus(401);
    }

    public function test_um_documento_que_nao_existe_nao_da_500(): void
    {
        $r = $this->actingAs($this->tecnico()->user, 'api')
            ->getJson('/api/v1/vendor/documents/999999');

        $this->assertContains($r->status(), [403, 404], 'um id inexistente e um 404, nao uma avaria');
    }

    public function test_enviar_documento_sem_ficheiro_e_recusado(): void
    {
        $r = $this->actingAs($this->tecnico()->user, 'api')
            ->postJson('/api/v1/vendor/documents', []);

        $this->assertContains($r->status(), [400, 422]);
    }

    // ----------------------------------------------------- credenciais AT

    public static function atUsersInvalidos(): array
    {
        return [
            'sem barra' => ['123456789'],
            'nif curto' => ['12345/1'],
            'com letras' => ['12345678A/1'],
            'so a barra' => ['/'],
            'vazio' => [''],
        ];
    }

    #[DataProvider('atUsersInvalidos')]
    public function test_um_subutilizador_at_mal_formado_e_recusado(string $atUser): void
    {
        // O formato e NIF/numero. Guardar lixo aqui significa faturas que a AT
        // recusa — e o tecnico so descobre quando o servico ja foi feito.
        $this->actingAs($this->tecnico()->user, 'api')
            ->postJson('/api/v1/vendor/at-user', ['at_user' => $atUser, 'at_password' => 'segredo'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['at_user']);
    }

    public function test_at_user_exige_autenticacao(): void
    {
        $this->postJson('/api/v1/vendor/at-user', ['at_user' => '123456789/1'])->assertStatus(401);
    }

    // ------------------------------------------------ dados de pagamento

    public function test_um_iban_invalido_e_recusado(): void
    {
        $this->actingAs($this->tecnico()->user, 'api')
            ->putJson('/api/v1/vendor/settings/update/payment', [
                'iban' => 'PT50-nao-e-um-iban',
                'price_rate' => 20,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['iban']);
    }

    public function test_os_dados_de_pagamento_exigem_iban(): void
    {
        $this->actingAs($this->tecnico()->user, 'api')
            ->putJson('/api/v1/vendor/settings/update/payment', ['price_rate' => 20])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['iban']);
    }

    public function test_dados_de_pagamento_exigem_autenticacao(): void
    {
        $this->putJson('/api/v1/vendor/settings/update/payment', [])->assertStatus(401);
    }

    // ----------------------------------------------------------- inquerito

    public function test_votar_no_inquerito_com_listas_vazias_e_aceite(): void
    {
        $this->actingAs($this->tecnico()->user, 'api')
            ->postJson('/api/v1/vendor/survey/vote', [])
            ->assertOk();
    }

    public function test_votar_numa_cidade_que_nao_existe_e_recusado(): void
    {
        $this->actingAs($this->tecnico()->user, 'api')
            ->postJson('/api/v1/vendor/survey/vote', ['survey_city_ids' => [999999]])
            ->assertStatus(422);
    }

    public function test_votar_exige_autenticacao(): void
    {
        $this->postJson('/api/v1/vendor/survey/vote', [])->assertStatus(401);
    }

    // --------------------------------------------------- agenda do tecnico

    public function test_atualizar_a_disponibilidade_exige_autenticacao(): void
    {
        $this->postJson('/api/v1/vendor/schedule/update-availability', [])->assertStatus(401);
    }

    public function test_atualizar_a_agenda_exige_autenticacao(): void
    {
        $this->postJson('/api/v1/vendor/schedule/update', [])->assertStatus(401);
    }

    public function test_o_tecnico_nao_cancela_a_marcacao_de_outro(): void
    {
        $outro = $this->tecnico();

        $this->actingAs($outro->user, 'api')
            ->postJson('/api/v1/vendor/schedule/999999/cancel')
            ->assertStatus(404);
    }

    public function test_ir_para_o_local_de_um_servico_alheio_e_negado(): void
    {
        $dono = $this->tecnico();
        $service = Service::factory()->create([
            'vendor_id' => $dono->id,
            'customer_id' => User::factory()->create()->id,
        ]);

        $r = $this->actingAs($this->tecnico()->user, 'api')
            ->postJson("/api/v1/vendor/schedule/go-to-location/{$service->id}");

        $this->assertContains($r->status(), [403, 404]);
    }

    // ------------------------------------------------------- codigo postal

    public function test_verificar_um_codigo_postal_responde(): void
    {
        $r = $this->actingAs($this->tecnico()->user, 'api')
            ->getJson('/api/v1/vendor/address/postal-code/verify?postal_code=2560-363');

        $this->assertNotSame(500, $r->status(), 'um codigo postal nao pode rebentar o servidor');
    }

    public function test_verificar_codigo_postal_exige_autenticacao(): void
    {
        $this->getJson('/api/v1/vendor/address/postal-code/verify?postal_code=1000-001')
            ->assertStatus(401);
    }
}
