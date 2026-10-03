<?php

namespace Tests\Feature\Customer;

use App\Enums\Services\AddressType;
use App\Models\Address;
use App\Models\GeneralSettings\Gender;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A MESMA MORADA NÃO APARECE DUAS VEZES NOS GUARDADOS.
 *
 * O `AddressesController::store` criava sempre uma linha nova, e a lista de
 * moradas guardadas do cliente enchia-se de repetidas.
 */
class MoradasRepetidasTest extends TestCase
{
    use DatabaseTruncation;

    protected array $tablesToTruncate = ['users', 'wallets', 'addresses'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null']);
        Gender::firstOrCreate(['name' => 'Masculino']);
        Notification::fake();
    }

    /** Com coordenadas, para não ir ao Google geocodificar. */
    private function morada(array $extra = []): array
    {
        return array_merge([
            'street_name' => 'Rua da Prata',
            'street_number' => '80',
            'postal_code' => '1100-414',
            'city' => 'Lisboa',
            'additional_info' => '3º Esq',
            'latitude' => 38.7102,
            'longitude' => -9.1366,
        ], $extra);
    }

    private function guardar(User $u, array $morada)
    {
        return $this->actingAs($u, 'api')->postJson('/api/v1/customer/addresses', $morada);
    }

    private function lista(User $u): array
    {
        return $this->actingAs($u, 'api')->getJson('/api/v1/customer/addresses')
            ->assertOk()->json('data.addresses');
    }

    // ---------------------------------------------------------------- na criação

    public function test_guardar_a_mesma_morada_duas_vezes_da_uma_linha_so(): void
    {
        $u = User::factory()->create();

        $primeira = $this->guardar($u, $this->morada())->assertStatus(201)->json('data.address.id');
        $segunda = $this->guardar($u, $this->morada())->assertStatus(200)->json('data.address.id');

        $this->assertSame($primeira, $segunda, 'Devolveu uma morada nova em vez da que já existia.');
        $this->assertSame(1, Address::where('user_id', $u->id)->count());
    }

    /** Escrita de outra maneira continua a ser a mesma porta. */
    public function test_a_mesma_morada_escrita_de_outra_maneira_e_reconhecida(): void
    {
        $u = User::factory()->create();

        $this->guardar($u, $this->morada())->assertStatus(201);
        $this->guardar($u, $this->morada([
            'street_name' => 'rua da prata ',
            'street_number' => ' 80',
            'postal_code' => '1100414',
            'additional_info' => '3 esq.',
        ]))->assertStatus(200);

        $this->assertSame(1, Address::where('user_id', $u->id)->count());
    }

    /**
     * ANDARES DIFERENTES SÃO CASAS DIFERENTES.
     *
     * O controlador existe para um proprietário de vários alojamentos. Fundir
     * o 3.º Esq com o 2.º Dto mandava o técnico à porta errada.
     */
    public function test_o_mesmo_predio_noutro_andar_e_outra_morada(): void
    {
        $u = User::factory()->create();

        $this->guardar($u, $this->morada(['additional_info' => '3º Esq']))->assertStatus(201);
        $this->guardar($u, $this->morada(['additional_info' => '2º Dto']))->assertStatus(201);

        $this->assertSame(2, Address::where('user_id', $u->id)->count());
    }

    /** O nome que se dá à morada é um rótulo: a porta é a mesma. */
    public function test_o_nome_da_morada_nao_a_torna_diferente(): void
    {
        $u = User::factory()->create();

        $this->guardar($u, $this->morada(['address_name' => 'Casa']))->assertStatus(201);
        $this->guardar($u, $this->morada(['address_name' => 'Casa da mãe']))->assertStatus(200);

        $this->assertSame(1, Address::where('user_id', $u->id)->count());
    }

    /** Pedir para a repetida passar a principal funciona -- sem criar outra. */
    public function test_pedir_para_principal_promove_a_existente(): void
    {
        $u = User::factory()->create();

        $this->guardar($u, $this->morada(['street_name' => 'Rua A', 'additional_info' => null]))->assertStatus(201); // principal
        $idPrata = $this->guardar($u, $this->morada())->assertStatus(201)->json('data.address.id');            // não principal

        $this->guardar($u, $this->morada(['main_address' => true]))->assertStatus(200);

        $this->assertTrue((bool) Address::find($idPrata)->main_address);
        $this->assertSame(1, Address::where('user_id', $u->id)->where('main_address', true)->count());
        $this->assertSame(2, Address::where('user_id', $u->id)->count());
    }

    /** Moradas de OUTRO cliente nunca são reutilizadas. */
    public function test_a_morada_de_outro_cliente_nao_e_reaproveitada(): void
    {
        $ana = User::factory()->create();
        $rui = User::factory()->create();

        $daAna = $this->guardar($ana, $this->morada())->json('data.address.id');
        $doRui = $this->guardar($rui, $this->morada())->assertStatus(201)->json('data.address.id');

        $this->assertNotSame($daAna, $doRui);
    }

    // ---------------------------------------------------------------- na lista

    private function repetidaNaBd(User $u, bool $principal, array $extra = []): Address
    {
        return Address::forceCreate(array_merge([
            'user_id' => $u->id,
            'street_name' => 'Rua da Prata',
            'street_number' => '80',
            'postal_code' => '1100-414',
            'city' => 'Lisboa',
            'state' => 'Lisboa',
            'country' => 'Portugal',
            'municipality' => 'Lisboa',
            'additional_info' => '3º Esq',
            'latitude' => 38.7102,
            'longitude' => -9.1366,
            'main_address' => $principal,
            'address_type' => AddressType::HOUSE_ADDRESS,
            'name' => 'Rua da Prata 80',
        ], $extra));
    }

    /** As repetidas que JÁ existem deixam de aparecer na lista. */
    public function test_a_lista_esconde_as_repetidas_que_ja_existem(): void
    {
        $u = User::factory()->create();
        $this->repetidaNaBd($u, false);
        $this->repetidaNaBd($u, false);
        $this->repetidaNaBd($u, false, ['street_name' => 'rua da prata']);

        $this->assertCount(1, $this->lista($u));
    }

    /** Das repetidas, fica a principal. */
    public function test_das_repetidas_fica_a_principal(): void
    {
        $u = User::factory()->create();
        $this->repetidaNaBd($u, false);
        $principal = $this->repetidaNaBd($u, true);

        $lista = $this->lista($u);

        $this->assertCount(1, $lista);
        $this->assertSame($principal->id, $lista[0]['id']);
    }

    /**
     * ESCONDER NÃO É APAGAR.
     *
     * Os serviços antigos apontam para os ids das repetidas. Apagá-las era
     * perder de onde esses serviços foram feitos.
     */
    public function test_esconder_nao_apaga_nada(): void
    {
        $u = User::factory()->create();
        $this->repetidaNaBd($u, true);
        $this->repetidaNaBd($u, false);

        $this->lista($u);

        $this->assertSame(2, Address::where('user_id', $u->id)->count());
    }

    /** Moradas genuinamente diferentes continuam todas na lista. */
    public function test_moradas_diferentes_aparecem_todas(): void
    {
        $u = User::factory()->create();
        $this->repetidaNaBd($u, true);
        $this->repetidaNaBd($u, false, ['additional_info' => '2º Dto']);
        $this->repetidaNaBd($u, false, ['street_name' => 'Avenida da Liberdade', 'street_number' => '1']);

        $this->assertCount(3, $this->lista($u));
    }
}
