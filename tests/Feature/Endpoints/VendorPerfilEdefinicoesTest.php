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
 * O que o tecnico ve sobre si proprio e o que pode mudar.
 *
 * Avaliacoes, ganhos, estado online, tarifa e notificacoes. A tarifa em
 * particular decide o preco de todos os pedidos futuros dele — mudava sem um
 * unico teste a ver se so o proprio lhe mexe.
 */
class VendorPerfilEdefinicoesTest extends TestCase
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

    // ---------------------------------------------------------- avaliacoes

    public function test_as_avaliacoes_trazem_a_nota_e_o_comentario(): void
    {
        $vendor = $this->tecnico();

        Service::factory()->create([
            'vendor_id' => $vendor->id,
            'customer_id' => User::factory()->create()->id,
            'status' => ServiceStatus::CLOSED,
            'rating_by_customer' => 5,
            'rating_comment_by_customer' => 'Rapido e limpo.',
        ]);

        $r = $this->actingAs($vendor->user, 'api')->getJson('/api/v1/vendor/reviews');
        $r->assertOk();

        $this->assertSame(5, $r->json('data.reviews.0.rating'));
        // O comentario chegava sempre vazio porque a coluna nao estava no
        // `fillable` do Service: gravava-se a estrela e deitava-se fora o texto.
        $this->assertSame('Rapido e limpo.', $r->json('data.reviews.0.comment'));
    }

    public function test_um_servico_sem_nota_nao_aparece_nas_avaliacoes(): void
    {
        $vendor = $this->tecnico();

        Service::factory()->create([
            'vendor_id' => $vendor->id,
            'customer_id' => User::factory()->create()->id,
            'status' => ServiceStatus::CLOSED,
            'rating_by_customer' => null,
        ]);

        $r = $this->actingAs($vendor->user, 'api')->getJson('/api/v1/vendor/reviews');
        $r->assertOk();

        $this->assertSame([], $r->json('data.reviews'));
    }

    public function test_um_tecnico_nao_ve_as_avaliacoes_de_outro(): void
    {
        $dono = $this->tecnico();
        Service::factory()->create([
            'vendor_id' => $dono->id,
            'customer_id' => User::factory()->create()->id,
            'status' => ServiceStatus::CLOSED,
            'rating_by_customer' => 5,
        ]);

        $r = $this->actingAs($this->tecnico()->user, 'api')->getJson('/api/v1/vendor/reviews');

        $r->assertOk();
        $this->assertSame([], $r->json('data.reviews'));
    }

    public function test_avaliacoes_exige_autenticacao(): void
    {
        $this->getJson('/api/v1/vendor/reviews')->assertStatus(401);
    }

    // --------------------------------------------------------------- stats

    public function test_os_ganhos_so_contam_servicos_fechados(): void
    {
        $vendor = $this->tecnico();
        $cliente = User::factory()->create();

        Service::factory()->create([
            'vendor_id' => $vendor->id, 'customer_id' => $cliente->id,
            'status' => ServiceStatus::CLOSED, 'amount_for_vendor' => 5000,
        ]);
        // Um aceite mas ainda nao terminado nao e dinheiro ganho.
        Service::factory()->create([
            'vendor_id' => $vendor->id, 'customer_id' => $cliente->id,
            'status' => ServiceStatus::ACCEPTED, 'amount_for_vendor' => 9900,
        ]);

        $r = $this->actingAs($vendor->user, 'api')->getJson('/api/v1/vendor/stats');
        $r->assertOk();

        $this->assertSame(5000, $r->json('data.total_earned'));
    }

    public function test_stats_de_um_tecnico_sem_historico_nao_rebenta(): void
    {
        $this->actingAs($this->tecnico()->user, 'api')
            ->getJson('/api/v1/vendor/stats')
            ->assertOk();
    }

    public function test_stats_exige_autenticacao(): void
    {
        $this->getJson('/api/v1/vendor/stats')->assertStatus(401);
    }

    // -------------------------------------------------------------- estado

    public function test_o_tecnico_le_o_proprio_estado(): void
    {
        $this->actingAs($this->tecnico()->user, 'api')
            ->getJson('/api/v1/vendor/status')
            ->assertOk();
    }

    public function test_quem_nao_esta_verificado_nao_pode_ficar_online(): void
    {
        // Um tecnico de fabrica nao tem documentos, IBAN, workspace nem AT —
        // ou seja, exatamente o estado em que 82% dos registados param. Ficar
        // Online significaria entrar nas ondas de convite sem poder faturar.
        $vendor = $this->tecnico();

        $this->actingAs($vendor->user, 'api')
            ->putJson('/api/v1/vendor/status', ['status' => 'Online'])
            ->assertStatus(422);
    }

    public function test_o_tecnico_pode_sempre_ficar_offline(): void
    {
        $vendor = $this->tecnico();

        $this->actingAs($vendor->user, 'api')
            ->putJson('/api/v1/vendor/status', ['status' => 'Offline'])
            ->assertOk();

        $this->assertSame('Offline', $vendor->refresh()->status->value);
    }

    public function test_um_estado_que_nao_existe_e_recusado(): void
    {
        $vendor = $this->tecnico();

        $this->actingAs($vendor->user, 'api')
            ->putJson('/api/v1/vendor/status', ['status' => 'DeFerias'])
            ->assertStatus(422);
    }

    public function test_estado_exige_autenticacao(): void
    {
        $this->getJson('/api/v1/vendor/status')->assertStatus(401);
    }

    // -------------------------------------------------------------- tarifa

    public function test_o_tecnico_muda_a_propria_tarifa(): void
    {
        $vendor = $this->tecnico();

        $this->actingAs($vendor->user, 'api')
            ->putJson('/api/v1/vendor/settings/price-rate', ['rate' => 25])
            ->assertOk();

        // A coluna guarda centimos: o mutador multiplica por 100. Quem enviar
        // 25 esta a dizer 25,00 EUR/hora.
        $this->assertSame(2500, (int) $vendor->refresh()->getRawOriginal('price_rate'));
    }

    public function test_a_tarifa_tem_de_vir_e_tem_de_ser_numero(): void
    {
        $vendor = $this->tecnico();

        $this->actingAs($vendor->user, 'api')
            ->putJson('/api/v1/vendor/settings/price-rate', [])
            ->assertStatus(422);

        $this->actingAs($vendor->user, 'api')
            ->putJson('/api/v1/vendor/settings/price-rate', ['rate' => 'caro'])
            ->assertStatus(422);
    }

    public function test_uma_tarifa_negativa_e_recusada(): void
    {
        $vendor = $this->tecnico();
        $antes = (int) $vendor->getRawOriginal('price_rate');

        $this->actingAs($vendor->user, 'api')
            ->putJson('/api/v1/vendor/settings/price-rate', ['rate' => -100])
            ->assertStatus(422);

        $this->assertSame($antes, (int) $vendor->refresh()->getRawOriginal('price_rate'));
    }

    public function test_tarifa_exige_autenticacao(): void
    {
        $this->putJson('/api/v1/vendor/settings/price-rate', ['rate' => 1])->assertStatus(401);
    }

    // ------------------------------------------------------- notificacoes

    public function test_o_tecnico_le_e_muda_as_notificacoes(): void
    {
        $vendor = $this->tecnico();

        $this->actingAs($vendor->user, 'api')
            ->getJson('/api/v1/vendor/settings/notifications')
            ->assertOk();

        $this->actingAs($vendor->user, 'api')
            ->putJson('/api/v1/vendor/settings/notifications', ['new_requests' => false])
            ->assertOk();

        $this->assertFalse($vendor->refresh()->shouldReceive('new_requests'));
    }

    public function test_notificacoes_exige_autenticacao(): void
    {
        $this->getJson('/api/v1/vendor/settings/notifications')->assertStatus(401);
    }

    // -------------------------------------------------------------- wallet

    public function test_a_carteira_responde_e_o_historico_tambem(): void
    {
        $vendor = $this->tecnico();

        $this->actingAs($vendor->user, 'api')->getJson('/api/v1/vendor/wallet')->assertOk();
        $this->actingAs($vendor->user, 'api')->postJson('/api/v1/vendor/wallet/history', [])->assertOk();
    }

    public function test_carteira_exige_autenticacao(): void
    {
        $this->getJson('/api/v1/vendor/wallet')->assertStatus(401);
        $this->postJson('/api/v1/vendor/wallet/history', [])->assertStatus(401);
    }
}
