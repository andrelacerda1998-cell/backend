<?php

namespace Tests\Feature\Vendor;

use App\Enums\Services\AddressType;
use App\Models\Address;
use App\Models\Vendor;
use App\Services\Common\AddressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O tecnico tem DUAS moradas, e elas nao se podem destruir uma a outra.
 *
 *   - fiscal: sustenta a facturacao (InvoiceXpress) e a activacao do tecnico;
 *   - de agendamento: e de onde sai a distancia — e portanto o preco — de
 *     TODOS os servicos agendados.
 *
 * Os dois ecras da app gravavam com `updateOrCreate([], ...)`. Com o array de
 * correspondencia vazio, o Eloquent agarra a PRIMEIRA morada do tecnico, seja
 * de que tipo for, e reescreve-a — tipo incluido. Gravar a morada da empresa
 * apagava a de agendamento; gravar a de agendamento apagava a fiscal. As duas
 * nunca podiam coexistir por via da app.
 *
 * Verificado antes da correccao numa transacao revertida: uma morada
 * `schedule_address` (id 31) ficava `fiscal_address` com o mesmo id.
 */
class MoradasDoTecnicoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Geocodificacao substituida: sem isto o endpoint devolve 400 em ambiente
     * de teste e os cenarios passavam sem gravar nada — um teste verde a nao
     * exercitar coisa nenhuma, que e pior do que um teste vermelho.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(AddressService::class, function ($mock) {
            $mock->shouldReceive('getCoordinates')->andReturn([
                'lat' => 38.7,
                'lng' => -9.14,
                'formatted_address' => 'Rua das Flores 12, Lisboa',
                // Nao pode vir vazio: o controlador rejeita a morada quando
                // o geocoder nao devolve componentes.
                'address_components' => [
                    (object) ['types' => ['country'], 'long_name' => 'Portugal', 'short_name' => 'PT'],
                    (object) ['types' => ['route'], 'long_name' => 'Rua das Flores', 'short_name' => 'Rua das Flores'],
                ],
            ]);

            $mock->shouldReceive('transformAddress')->andReturn([
                'address_name' => 'Morada',
                'state' => 'Lisboa',
                'city' => 'Lisboa',
                'country' => 'Portugal',
                'street_number' => '12',
                'street_name' => 'Rua das Flores',
                'postal_code' => '1000-001',
                'latitude' => 38.7,
                'longitude' => -9.14,
                'main_address' => true,
                'name' => 'Rua das Flores 12, Lisboa',
            ]);
        });
    }

    private function morada(Vendor $vendor, AddressType $tipo): Address
    {
        return Address::create([
            'user_id' => $vendor->user_id,
            'address_type' => $tipo,
            'address_name' => 'Morada '.$tipo->value,
            // `name` e o campo que o perfil devolve (vem do formatted_address
            // do geocoder nas moradas a serio).
            'name' => 'Rua Antiga 1, Almada',
            'street_name' => 'Rua Antiga',
            'street_number' => '1',
            'postal_code' => '2800-000',
            'city' => 'Almada',
            'municipality' => 'Almada',
            'state' => 'Setubal',
            'country' => 'Portugal',
            'latitude' => 38.66,
            'longitude' => -9.07,
        ]);
    }

    private function payload(): array
    {
        return [
            'street_name' => 'Rua das Flores',
            'street_number' => '12',
            'postal_code' => '1000-001',
            'city' => 'Lisboa',
        ];
    }

    public function test_gravar_a_morada_da_empresa_nao_apaga_a_de_agendamento(): void
    {
        $vendor = Vendor::factory()->create();
        $agendamento = $this->morada($vendor, AddressType::SCHEDULE_ADDRESS);

        $this->actingAs($vendor->user, 'api')
            ->postJson('/api/v1/vendor/address', $this->payload())
            ->assertOk();

        $this->assertDatabaseHas('addresses', [
            'id' => $agendamento->id,
            'address_type' => AddressType::SCHEDULE_ADDRESS->value,
        ]);
    }

    public function test_as_duas_moradas_coexistem(): void
    {
        $vendor = Vendor::factory()->create();
        $this->morada($vendor, AddressType::SCHEDULE_ADDRESS);

        $this->actingAs($vendor->user, 'api')
            ->postJson('/api/v1/vendor/address', $this->payload())
            ->assertOk();

        $moradas = $vendor->refresh()->addresses;

        $this->assertNotNull($moradas->firstWhere('address_type', AddressType::SCHEDULE_ADDRESS));
        $this->assertNotNull($moradas->firstWhere('address_type', AddressType::FISCAL_ADDRESS));
    }

    /**
     * O ecra da empresa mostrava "a primeira morada que houver". Com as duas a
     * coexistir, isso tanto podia ser uma como outra.
     */
    public function test_o_ecra_da_empresa_devolve_a_morada_fiscal_e_nao_a_primeira(): void
    {
        $vendor = Vendor::factory()->create();
        // A de agendamento e criada PRIMEIRO de proposito.
        $this->morada($vendor, AddressType::SCHEDULE_ADDRESS);
        $fiscal = $this->morada($vendor, AddressType::FISCAL_ADDRESS);

        $this->actingAs($vendor->user, 'api')
            ->getJson('/api/v1/vendor/address')
            ->assertOk()
            ->assertJsonPath('data.id', $fiscal->id);
    }

    /**
     * O endpoint que o "completar perfil" usa: grava SO a morada de
     * agendamento, sem exigir os dias da semana, e sem tocar na fiscal.
     */
    public function test_o_completar_perfil_grava_a_morada_de_agendamento_sem_tocar_na_fiscal(): void
    {
        $vendor = Vendor::factory()->create();
        $fiscal = $this->morada($vendor, AddressType::FISCAL_ADDRESS);

        $this->actingAs($vendor->user, 'api')
            ->postJson('/api/v1/vendor/schedule/address', $this->payload())
            ->assertOk();

        $moradas = $vendor->refresh()->addresses;

        $this->assertNotNull($moradas->firstWhere('address_type', AddressType::SCHEDULE_ADDRESS));
        $this->assertDatabaseHas('addresses', [
            'id' => $fiscal->id,
            'address_type' => AddressType::FISCAL_ADDRESS->value,
        ]);
    }

    /** O perfil tem de dizer se ela ja existe — e o sinal que o passo usa. */
    public function test_o_perfil_diz_se_a_morada_de_agendamento_ja_existe(): void
    {
        $vendor = Vendor::factory()->create();

        $this->actingAs($vendor->user, 'api')
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.schedule_address', null);

        $this->morada($vendor, AddressType::SCHEDULE_ADDRESS);

        // Instancia fresca: o `actingAs` guarda o utilizador entre pedidos e a
        // relacao `addresses` ficava em cache do pedido anterior. Em producao
        // cada pedido carrega de novo; aqui e preciso dize-lo.
        $resposta = $this->actingAs($vendor->user->fresh(), 'api')->getJson('/api/v1/auth/me')->assertOk();

        $this->assertNotNull($resposta->json('data.schedule_address'));
    }

    /**
     * Quem so tem uma linha, de antes desta correccao, continua a ver o que la
     * esta — melhor do que um ecra vazio a quem ja preencheu.
     */
    public function test_sem_morada_fiscal_devolve_a_que_existir(): void
    {
        $vendor = Vendor::factory()->create();
        $unica = $this->morada($vendor, AddressType::SCHEDULE_ADDRESS);

        $this->actingAs($vendor->user, 'api')
            ->getJson('/api/v1/vendor/address')
            ->assertOk()
            ->assertJsonPath('data.id', $unica->id);
    }
}
