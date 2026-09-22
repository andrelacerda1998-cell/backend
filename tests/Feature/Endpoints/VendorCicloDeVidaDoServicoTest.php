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
 * As transicoes que o tecnico provoca: aceitar, sair, chegar e avaliar.
 *
 * Sao endpoints que movem um servico — e o dinheiro com ele — e nenhum tinha
 * teste. Interessam tres coisas em cada um: que so o dono do servico lhe pode
 * tocar, que a transicao so acontece a partir do estado certo, e que o efeito
 * fica mesmo gravado.
 */
class VendorCicloDeVidaDoServicoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GenderSeeder::class);
        Notification::fake();
    }

    /** @return array{0: User, 1: Service} */
    private function servico(ServiceStatus $status = ServiceStatus::PENDING, array $extra = []): array
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

    // ------------------------------------------------------------- aceitar

    public function test_aceitar_passa_o_servico_a_aceite(): void
    {
        [$user, $service] = $this->servico(ServiceStatus::PENDING);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/vendor/services/{$service->id}/accept")
            ->assertOk();

        $this->assertSame(ServiceStatus::ACCEPTED, $service->refresh()->status);
    }

    public function test_aceitar_duas_vezes_nao_volta_a_transitar(): void
    {
        [$user, $service] = $this->servico(ServiceStatus::PENDING);

        $this->actingAs($user, 'api')->postJson("/api/v1/vendor/services/{$service->id}/accept")->assertOk();

        // A segunda tem de ser recusada: o `accept` faz lock e re-verifica
        // dentro da transacao precisamente para o duplo-clique nao disparar a
        // notificacao ao cliente duas vezes.
        $this->actingAs($user, 'api')
            ->postJson("/api/v1/vendor/services/{$service->id}/accept")
            ->assertStatus(403);

        $this->assertSame(ServiceStatus::ACCEPTED, $service->refresh()->status);
    }

    public function test_um_tecnico_nao_aceita_o_servico_de_outro(): void
    {
        [, $service] = $this->servico(ServiceStatus::PENDING);

        $this->actingAs($this->outroTecnico(), 'api')
            ->postJson("/api/v1/vendor/services/{$service->id}/accept")
            ->assertStatus(404);

        $this->assertSame(ServiceStatus::PENDING, $service->refresh()->status);
    }

    public function test_aceitar_exige_autenticacao(): void
    {
        [, $service] = $this->servico();

        $this->postJson("/api/v1/vendor/services/{$service->id}/accept")->assertStatus(401);
    }

    // ---------------------------------------------------------- a caminho

    public function test_a_caminho_so_depois_de_aceite(): void
    {
        [$user, $service] = $this->servico(ServiceStatus::PENDING);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/vendor/services/{$service->id}/on-the-way")
            ->assertStatus(422);

        $this->assertNull($service->refresh()->on_the_way_at);
    }

    public function test_a_caminho_carimba_a_hora_de_saida(): void
    {
        [$user, $service] = $this->servico(ServiceStatus::ACCEPTED);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/vendor/services/{$service->id}/on-the-way")
            ->assertOk();

        // E este carimbo que faz a app do cliente poder dizer "esta a caminho"
        // sem o inventar a partir do estado.
        $this->assertNotNull($service->refresh()->on_the_way_at);
    }

    public function test_a_caminho_de_um_servico_alheio_da_404(): void
    {
        [, $service] = $this->servico(ServiceStatus::ACCEPTED);

        $this->actingAs($this->outroTecnico(), 'api')
            ->postJson("/api/v1/vendor/services/{$service->id}/on-the-way")
            ->assertStatus(404);
    }

    // ------------------------------------------------------------- chegou

    public function test_chegou_marca_o_inicio_da_execucao(): void
    {
        [$user, $service] = $this->servico(ServiceStatus::ACCEPTED);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/vendor/services/{$service->id}/arrived")
            ->assertOk();

        $service->refresh();
        $this->assertSame(ServiceStatus::ARRIVED, $service->status);
        $this->assertNotNull($service->arrived_at, 'e daqui que a app conta o tempo de trabalho');
    }

    public function test_nao_se_chega_a_um_servico_que_ainda_esta_pendente(): void
    {
        [$user, $service] = $this->servico(ServiceStatus::PENDING);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/vendor/services/{$service->id}/arrived")
            ->assertStatus(403);

        $this->assertSame(ServiceStatus::PENDING, $service->refresh()->status);
    }

    public function test_chegar_a_um_servico_alheio_da_404(): void
    {
        [, $service] = $this->servico(ServiceStatus::ACCEPTED);

        $this->actingAs($this->outroTecnico(), 'api')
            ->postJson("/api/v1/vendor/services/{$service->id}/arrived")
            ->assertStatus(404);
    }

    // ------------------------------------------------------------ avaliar

    public function test_avaliar_grava_a_nota_dada_ao_cliente(): void
    {
        [$user, $service] = $this->servico(ServiceStatus::CLOSED);

        $this->actingAs($user, 'api')
            ->putJson("/api/v1/vendor/services/{$service->id}/rate", ['rate' => 4])
            ->assertOk();

        $this->assertSame(4, (int) $service->refresh()->rating_by_vendor);
    }

    public function test_nao_se_avalia_duas_vezes(): void
    {
        [$user, $service] = $this->servico(ServiceStatus::CLOSED, ['rating_by_vendor' => 3]);

        $this->actingAs($user, 'api')
            ->putJson("/api/v1/vendor/services/{$service->id}/rate", ['rate' => 5])
            ->assertStatus(409);

        $this->assertSame(3, (int) $service->refresh()->rating_by_vendor, 'a primeira nota manda');
    }

    public function test_a_nota_tem_de_vir_no_pedido(): void
    {
        [$user, $service] = $this->servico(ServiceStatus::CLOSED);

        $this->actingAs($user, 'api')
            ->putJson("/api/v1/vendor/services/{$service->id}/rate", [])
            ->assertStatus(422);
    }

    public function test_um_tecnico_nao_avalia_o_servico_de_outro(): void
    {
        [, $service] = $this->servico(ServiceStatus::CLOSED);

        $this->actingAs($this->outroTecnico(), 'api')
            ->putJson("/api/v1/vendor/services/{$service->id}/rate", ['rate' => 1])
            ->assertStatus(404);

        $this->assertNull($service->refresh()->rating_by_vendor);
    }
}
