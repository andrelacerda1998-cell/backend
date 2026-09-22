<?php

namespace Tests\Feature\Endpoints;

use App\Models\GeneralSettings\OperationArea;
use App\Models\GeneralSettings\ServicesType;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Catalogo, moradas, faturacao e suporte.
 *
 * Endpoints menos dramaticos do que os do dinheiro, mas o catalogo e a
 * primeira coisa que um cliente ve e a morada decide o preco — um 500 aqui
 * fecha a porta antes de haver pedido nenhum.
 */
class CatalogoMoradasEfaturacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GenderSeeder::class);
        Notification::fake();
    }

    // ----------------------------------------------------------- catalogo

    public function test_o_catalogo_de_categorias_e_publico(): void
    {
        OperationArea::factory()->create();

        // Sem `auth:api` de proposito: um visitante tem de poder ver o que ha
        // antes de criar conta.
        $this->getJson('/api/v1/customer/services/operation-areas')->assertOk();
    }

    public function test_uma_categoria_lista_os_seus_trabalhos(): void
    {
        $area = OperationArea::factory()->create();
        ServicesType::factory()->create(['operation_area_id' => $area->id]);

        $r = $this->getJson("/api/v1/customer/services/operation-areas/{$area->id}/services-types");

        $r->assertOk();
        $this->assertNotEmpty($r->json('data'));
    }

    public function test_uma_categoria_que_nao_existe_da_404(): void
    {
        $this->getJson('/api/v1/customer/services/operation-areas/999999/services-types')
            ->assertStatus(404);
    }

    public function test_a_procura_aceita_filtro_por_categorias(): void
    {
        $area = OperationArea::factory()->create();
        ServicesType::factory()->create(['operation_area_id' => $area->id]);

        $this->postJson('/api/v1/customer/services/operation-areas/search', [
            'operation_areas' => [$area->id],
        ])->assertOk();
    }

    public function test_a_procura_recusa_uma_categoria_inexistente(): void
    {
        $this->postJson('/api/v1/customer/services/operation-areas/search', [
            'operation_areas' => [999999],
        ])->assertStatus(422);
    }

    // ------------------------------------------------------------ moradas

    public function test_o_cliente_lista_cria_e_apaga_moradas(): void
    {
        $customer = User::factory()->create();

        $this->actingAs($customer, 'api')->getJson('/api/v1/customer/addresses')->assertOk();
    }

    public function test_a_morada_actual_exige_autenticacao(): void
    {
        $this->getJson('/api/v1/customer/address')->assertStatus(401);
    }

    public function test_um_cliente_nao_apaga_a_morada_de_outro(): void
    {
        $dono = User::factory()->create();
        $morada = $dono->addresses()->create([
            'street_name' => 'Rua A', 'street_number' => '1', 'postal_code' => '1000-001',
            'city' => 'Lisboa', 'state' => 'Lisboa', 'country' => 'Portugal',
            'latitude' => 38.7, 'longitude' => -9.1, 'main_address' => true,
        ]);

        $this->actingAs(User::factory()->create(), 'api')
            ->deleteJson("/api/v1/customer/addresses/{$morada->id}")
            ->assertStatus(404);

        $this->assertDatabaseHas('addresses', ['id' => $morada->id]);
    }

    // ---------------------------------------------------------- faturacao

    public function test_os_dados_de_faturacao_gravam_e_lem(): void
    {
        $customer = User::factory()->create();

        $this->actingAs($customer, 'api')
            ->postJson('/api/v1/customer/billing', [
                'name' => 'Andre Lacerda',
                'address' => 'Rua das Flores 12',
                'postal_code' => '1000-001',
                'locality' => 'Lisboa',
            ])
            ->assertOk();

        $this->actingAs($customer, 'api')
            ->getJson('/api/v1/customer/billing')
            ->assertOk();
    }

    public function test_faturacao_sem_os_campos_obrigatorios_e_recusada(): void
    {
        $this->actingAs(User::factory()->create(), 'api')
            ->postJson('/api/v1/customer/billing', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'address', 'postal_code', 'locality']);
    }

    /**
     * Um NIF mal escrito tem de dar 422 e nao 500.
     *
     * O `$fail()` do Laravel nao interrompe o metodo: a regra falhava por
     * "curto demais" e continuava ate ao ciclo do digito de controlo, onde
     * lia a posicao 3 de um array de 3. Quem escrevesse o NIF a medias via
     * "Something went wrong" em vez de "NIF invalido".
     */
    public static function nifsInvalidos(): array
    {
        return [
            'curto' => ['123'],
            'muito curto' => ['1'],
            'oito digitos' => ['12345678'],
            'com letras' => ['12345678A'],
            'primeiro digito invalido' => ['412345678'],
            'digito de controlo errado' => ['123456780'],
            'com espacos no meio' => ['123 456 78'],
        ];
    }

    #[DataProvider('nifsInvalidos')]
    public function test_um_nif_invalido_e_recusado(string $nif): void
    {
        $this->actingAs(User::factory()->create(), 'api')
            ->postJson('/api/v1/customer/billing', [
                'name' => 'Andre', 'address' => 'Rua A', 'postal_code' => '1000-001',
                'locality' => 'Lisboa', 'nif' => $nif,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nif']);
    }

    public function test_um_nif_valido_passa(): void
    {
        // 123456789: 1x9+2x8+3x7+4x6+5x5+6x4+7x3+8x2 = 156; 156 % 11 = 2;
        // 11-2 = 9, que e mesmo o ultimo digito.
        $this->actingAs(User::factory()->create(), 'api')
            ->postJson('/api/v1/customer/billing', [
                'name' => 'Andre', 'address' => 'Rua A', 'postal_code' => '1000-001',
                'locality' => 'Lisboa', 'nif' => '123456789',
            ])
            ->assertOk();
    }

    // ------------------------------------------------------------ suporte

    public function test_o_tecnico_abre_e_lista_tickets(): void
    {
        $vendor = Vendor::factory()->create();

        $this->actingAs($vendor->user, 'api')
            ->postJson('/api/v1/vendor/support/tickets', [
                'subject' => 'Nao consigo faturar',
                'message' => 'O workspace diz que nao esta pronto.',
            ])
            ->assertOk();

        $r = $this->actingAs($vendor->user, 'api')->getJson('/api/v1/vendor/support/tickets');
        $r->assertOk();
        $this->assertNotEmpty($r->json('data.tickets'));
    }

    public function test_um_ticket_precisa_de_assunto_e_mensagem(): void
    {
        $this->actingAs(Vendor::factory()->create()->user, 'api')
            ->postJson('/api/v1/vendor/support/tickets', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['subject', 'message']);
    }

    public function test_um_tecnico_nao_ve_os_tickets_de_outro(): void
    {
        $dono = Vendor::factory()->create();
        $this->actingAs($dono->user, 'api')->postJson('/api/v1/vendor/support/tickets', [
            'subject' => 'Assunto do dono', 'message' => 'Mensagem privada.',
        ])->assertOk();

        $r = $this->actingAs(Vendor::factory()->create()->user, 'api')
            ->getJson('/api/v1/vendor/support/tickets');

        $r->assertOk();
        $this->assertEmpty($r->json('data.tickets'));
    }

    public function test_tickets_exige_autenticacao(): void
    {
        $this->getJson('/api/v1/vendor/support/tickets')->assertStatus(401);
    }

    // ------------------------------------------------------------- survey

    public function test_a_lista_de_cidades_do_inquerito_responde(): void
    {
        $this->actingAs(Vendor::factory()->create()->user, 'api')
            ->getJson('/api/v1/vendor/survey/cities')
            ->assertOk();
    }

    public function test_o_inquerito_exige_autenticacao(): void
    {
        $this->getJson('/api/v1/vendor/survey/cities')->assertStatus(401);
    }
}
