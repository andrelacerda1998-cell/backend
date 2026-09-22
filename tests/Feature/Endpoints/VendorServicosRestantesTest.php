<?php

namespace Tests\Feature\Endpoints;

use App\Enums\Services\ServiceStatus;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * O resto do lado do tecnico: terminar, cancelar, recusar, fotos, rota,
 * historico e os padroes de procura.
 *
 * Terminar, cancelar e recusar sao as tres transicoes que faltavam do dinheiro
 * — o `CancelService` e o `RefuseService` ja tinham testes de unidade, mas
 * nenhum destes endpoints tinha um teste que passasse pela rota, pela
 * autenticacao e pela verificacao de dono.
 */
class VendorServicosRestantesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GenderSeeder::class);
        Notification::fake();
    }

    /** @return array{0: User, 1: Service} */
    private function servico(ServiceStatus $status, array $extra = []): array
    {
        $vendor = Vendor::factory()->create();
        $service = Service::factory()->create(array_merge([
            'vendor_id' => $vendor->id,
            'customer_id' => User::factory()->create()->id,
            'status' => $status,
        ], $extra));

        return [$vendor->user, $service->refresh()];
    }

    private function outroTecnico(): User
    {
        return Vendor::factory()->create()->user;
    }

    // ----------------------------------------------------------- terminar

    public function test_terminar_um_servico_aceite(): void
    {
        [$user, $service] = $this->servico(ServiceStatus::ACCEPTED);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/vendor/services/{$service->id}/finish")
            ->assertOk();

        $this->assertSame(ServiceStatus::FINISHED, $service->refresh()->status);
    }

    public function test_terminar_um_servico_em_que_ja_se_chegou(): void
    {
        [$user, $service] = $this->servico(ServiceStatus::ARRIVED);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/vendor/services/{$service->id}/finish")
            ->assertOk();

        $this->assertSame(ServiceStatus::FINISHED, $service->refresh()->status);
    }

    public function test_nao_se_termina_um_servico_que_ainda_nao_foi_aceite(): void
    {
        [$user, $service] = $this->servico(ServiceStatus::PENDING);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/vendor/services/{$service->id}/finish")
            ->assertStatus(422);

        $this->assertSame(ServiceStatus::PENDING, $service->refresh()->status);
    }

    public function test_terminar_duas_vezes_e_conflito(): void
    {
        [$user, $service] = $this->servico(ServiceStatus::FINISHED);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/vendor/services/{$service->id}/finish")
            ->assertStatus(409);
    }

    public function test_um_tecnico_nao_termina_o_servico_de_outro(): void
    {
        [, $service] = $this->servico(ServiceStatus::ACCEPTED);

        $this->actingAs($this->outroTecnico(), 'api')
            ->postJson("/api/v1/vendor/services/{$service->id}/finish")
            ->assertStatus(404);

        $this->assertSame(ServiceStatus::ACCEPTED, $service->refresh()->status);
    }

    public function test_terminar_exige_autenticacao(): void
    {
        [, $service] = $this->servico(ServiceStatus::ACCEPTED);

        $this->postJson("/api/v1/vendor/services/{$service->id}/finish")->assertStatus(401);
    }

    // ------------------------------------------------------------ recusar

    public function test_recusar_um_pedido_pendente(): void
    {
        [$user, $service] = $this->servico(ServiceStatus::PENDING);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/vendor/services/{$service->id}/refuse")
            ->assertOk();

        $this->assertSame(ServiceStatus::REFUSED, $service->refresh()->status);
    }

    public function test_recusar_deixa_registada_a_razao(): void
    {
        [$user, $service] = $this->servico(ServiceStatus::PENDING);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/vendor/services/{$service->id}/refuse")
            ->assertOk();

        // Sem isto, um pedido recusado e um pedido cancelado por prazo ficam
        // indistinguiveis no historico — e sao coisas diferentes.
        $this->assertSame('internal/services.refused.vendor', $service->refresh()->status_justification);
    }

    public function test_um_tecnico_nao_recusa_o_servico_de_outro(): void
    {
        [, $service] = $this->servico(ServiceStatus::PENDING);

        $this->actingAs($this->outroTecnico(), 'api')
            ->postJson("/api/v1/vendor/services/{$service->id}/refuse")
            ->assertStatus(404);

        $this->assertSame(ServiceStatus::PENDING, $service->refresh()->status);
    }

    public function test_recusar_exige_autenticacao(): void
    {
        [, $service] = $this->servico(ServiceStatus::PENDING);

        $this->postJson("/api/v1/vendor/services/{$service->id}/refuse")->assertStatus(401);
    }

    // ----------------------------------------------------------- cancelar

    public function test_o_tecnico_cancela_um_servico_aceite(): void
    {
        [$user, $service] = $this->servico(ServiceStatus::ACCEPTED);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/vendor/services/{$service->id}/cancel")
            ->assertOk();

        $this->assertSame(ServiceStatus::CANCELED, $service->refresh()->status);
    }

    public function test_um_tecnico_nao_cancela_o_servico_de_outro(): void
    {
        [, $service] = $this->servico(ServiceStatus::ACCEPTED);

        $this->actingAs($this->outroTecnico(), 'api')
            ->postJson("/api/v1/vendor/services/{$service->id}/cancel")
            ->assertStatus(404);

        $this->assertSame(ServiceStatus::ACCEPTED, $service->refresh()->status);
    }

    public function test_cancelar_exige_autenticacao(): void
    {
        [, $service] = $this->servico(ServiceStatus::ACCEPTED);

        $this->postJson("/api/v1/vendor/services/{$service->id}/cancel")->assertStatus(401);
    }

    // --------------------------------------------------------------- rota

    /**
     * O caminho feliz depende do Google Directions, que nao existe em teste —
     * por isso o que se fixa aqui e o que NAO depende dele: o dono passa a
     * verificacao de acesso, e uma rota que nao se consegue calcular responde
     * 400 e nao 500. Sem isto, os dois casos eram indistinguiveis.
     */
    public function test_ao_dono_a_rota_falha_a_calcular_mas_nao_e_negada(): void
    {
        [$user, $service] = $this->servico(ServiceStatus::ACCEPTED);

        $r = $this->actingAs($user, 'api')
            ->getJson("/api/v1/vendor/services/{$service->id}/route");

        $this->assertNotContains($r->status(), [403, 404], 'o dono do servico nunca pode levar com um "nao encontrado"');
        $this->assertContains($r->status(), [200, 400]);
    }

    public function test_a_rota_de_um_servico_alheio_nao_responde(): void
    {
        [, $service] = $this->servico(ServiceStatus::ACCEPTED);

        $r = $this->actingAs($this->outroTecnico(), 'api')
            ->getJson("/api/v1/vendor/services/{$service->id}/route");

        $this->assertContains($r->status(), [403, 404], 'a morada do cliente nao pode sair para outro tecnico');
    }

    // -------------------------------------------------------------- fotos

    public function test_as_fotos_de_um_servico_respondem_ao_dono(): void
    {
        [$user, $service] = $this->servico(ServiceStatus::ARRIVED);

        $this->actingAs($user, 'api')
            ->getJson("/api/v1/vendor/services/{$service->id}/photos")
            ->assertOk();
    }

    public function test_um_tecnico_nao_ve_as_fotos_de_um_servico_alheio(): void
    {
        [, $service] = $this->servico(ServiceStatus::ARRIVED);

        $r = $this->actingAs($this->outroTecnico(), 'api')
            ->getJson("/api/v1/vendor/services/{$service->id}/photos");

        $this->assertContains($r->status(), [403, 404], 'as fotos da casa do cliente sao do servico, nao publicas');
    }

    public function test_fotos_exige_autenticacao(): void
    {
        [, $service] = $this->servico(ServiceStatus::ARRIVED);

        $this->getJson("/api/v1/vendor/services/{$service->id}/photos")->assertStatus(401);
    }

    // ---------------------------------------------------------- historico

    public function test_o_historico_responde(): void
    {
        $vendor = Vendor::factory()->create();

        $this->actingAs($vendor->user, 'api')
            ->postJson('/api/v1/vendor/services/history', [])
            ->assertOk();
    }

    public function test_o_historico_exige_autenticacao(): void
    {
        $this->postJson('/api/v1/vendor/services/history', [])->assertStatus(401);
    }

    // ----------------------------------------------------------- insights

    public function test_os_padroes_de_procura_respondem(): void
    {
        $this->actingAs(Vendor::factory()->create()->user, 'api')
            ->getJson('/api/v1/vendor/services/matching/insights')
            ->assertOk();
    }

    public function test_insights_exige_autenticacao(): void
    {
        $this->getJson('/api/v1/vendor/services/matching/insights')->assertStatus(401);
    }

    // ------------------------------------------------- tipos de servico

    public function test_o_tecnico_le_os_seus_tipos_de_servico(): void
    {
        $this->actingAs(Vendor::factory()->create()->user, 'api')
            ->getJson('/api/v1/vendor/services/operation-areas/services-types')
            ->assertOk();
    }

    public function test_tipos_de_servico_exige_autenticacao(): void
    {
        $this->getJson('/api/v1/vendor/services/operation-areas/services-types')->assertStatus(401);
    }
}
