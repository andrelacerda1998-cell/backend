<?php

namespace Tests\Feature\Matching;

use App\Enums\Services\ServiceStatus;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * O cliente consegue sair de um pedido personalizado que ainda está em análise.
 *
 * Antes não conseguia: o CancelServiceController mandava-o para o
 * `cancelOpenService`, que faz `return` silencioso em tudo o que não seja
 * ACCEPTED/ARRIVED. A API respondia que não era possível cancelar, e o cliente
 * ficava preso a um pedido que não controlava — sem custo, mas também sem
 * saída, e sem perceber porquê.
 */
class CancelarPedidoEmAnaliseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GenderSeeder::class);
        Notification::fake();
    }

    private function pedidoEmAnalise(User $customer): Service
    {
        return Service::factory()->create([
            'customer_id' => $customer->id,
            'services_type_id' => null,
            'vendor_id' => null,
            'is_custom' => true,
            'custom_description' => 'Trocar a fechadura da porta da rua.',
            'status' => ServiceStatus::PENDING_REVIEW,
        ]);
    }

    public function test_cancelar_um_pedido_em_analise_fecha_o_sem_custo(): void
    {
        $customer = User::factory()->create();
        $service = $this->pedidoEmAnalise($customer);

        $this->actingAs($customer, 'api')
            ->postJson("/api/v1/customer/services/{$service->id}/cancel")
            ->assertSuccessful();

        $service->refresh();
        $this->assertSame(ServiceStatus::CANCELED, $service->status);
        // Nenhum profissional soube que existia, e não há ordem de pagamento:
        // não há nada para cobrar nem para devolver.
        $this->assertNull($service->payment_order_id);
    }

    public function test_cancelar_liberta_o_cliente_para_pedir_outro(): void
    {
        // É este o ponto todo: um pedido esquecido em análise recusava um
        // segundo personalizado, e sem forma de o cancelar o cliente ficava
        // sem poder pedir nada do género.
        $customer = User::factory()->create();
        $service = $this->pedidoEmAnalise($customer);

        $this->actingAs($customer, 'api')
            ->postJson("/api/v1/customer/services/{$service->id}/cancel")
            ->assertSuccessful();

        $abertos = Service::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', [ServiceStatus::PENDING_REVIEW, ServiceStatus::MATCHING, ServiceStatus::AWAITING_PAYMENT])
            ->count();

        $this->assertSame(0, $abertos);
    }

    public function test_o_pedido_de_outra_pessoa_continua_fora_de_alcance(): void
    {
        $dono = User::factory()->create();
        $intruso = User::factory()->create();
        $service = $this->pedidoEmAnalise($dono);

        $this->actingAs($intruso, 'api')
            ->postJson("/api/v1/customer/services/{$service->id}/cancel")
            ->assertStatus(404);

        $this->assertSame(ServiceStatus::PENDING_REVIEW, $service->refresh()->status);
    }
}
