<?php

namespace Tests\Feature\Matching;

use App\Enums\Services\ServiceStatus;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A aba Serviços deixa de esconder pedidos que ainda estão abertos.
 *
 * Pode haver mais do que um ao mesmo tempo: o `startCustom` recusa um segundo
 * personalizado enquanto houver um em análise, mas o `start()` de catálogo não
 * olha para o PendingReview. Bastava pedir um serviço normal para o
 * personalizado sumir do ecrã — invisível para o cliente, e a bloquear na
 * mesma.
 */
class PedidosAbertosNaAbaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GenderSeeder::class);
        Notification::fake();
    }

    public function test_um_personalizado_em_analise_nao_desaparece_atras_de_um_de_catalogo(): void
    {
        $customer = User::factory()->create();

        $personalizado = Service::factory()->create([
            'customer_id' => $customer->id,
            'services_type_id' => null,
            'is_custom' => true,
            'custom_description' => 'Trocar a fechadura da porta da rua.',
            'status' => ServiceStatus::PENDING_REVIEW,
        ]);

        // Pedido de catálogo feito DEPOIS: fica com o id maior e era ele que o
        // `latest('id')` devolvia, sozinho.
        $catalogo = Service::factory()->create([
            'customer_id' => $customer->id,
            'services_type_id' => ServicesType::factory(),
            'is_custom' => false,
            'status' => ServiceStatus::MATCHING,
        ]);

        $dados = $this->actingAs($customer, 'api')
            ->getJson('/api/v1/customer/services/matching/current')
            ->assertSuccessful()
            ->json('data');

        $ids = collect($dados['requests'])->pluck('id')->all();

        $this->assertContains($personalizado->id, $ids, 'o que estava em análise tem de continuar visível');
        $this->assertContains($catalogo->id, $ids);
        // `request` continua a ser o mais recente, para as versões da app já
        // instaladas não notarem diferença nenhuma.
        $this->assertSame($catalogo->id, $dados['request']['id']);
    }

    public function test_sem_pedidos_abertos_a_lista_vem_vazia(): void
    {
        $customer = User::factory()->create();

        $dados = $this->actingAs($customer, 'api')
            ->getJson('/api/v1/customer/services/matching/current')
            ->assertSuccessful()
            ->json('data');

        $this->assertNull($dados['request']);
        $this->assertSame([], $dados['requests']);
    }
}
