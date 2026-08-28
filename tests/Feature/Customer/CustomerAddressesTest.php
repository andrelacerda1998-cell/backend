<?php

namespace Tests\Feature\Customer;

use App\Enums\Services\AddressType;
use App\Models\Address;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Geocoder\Facades\Geocoder;
use Tests\TestCase;

/**
 * Multi-morada: um proprietário de vários alojamentos guarda uma morada por casa
 * e escolhe qual usar. Regras guardadas aqui: uma e só uma principal, apagar a
 * principal promove outra, e uma morada nunca é acessível por outro cliente.
 */
class CustomerAddressesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Sem rede: o reverse-geocode do concelho cai no fallback (city).
        Geocoder::shouldReceive('setLanguage')->andReturnSelf();
        Geocoder::shouldReceive('getAddressForCoordinates')->andReturn(['address_components' => []]);
    }

    private function payload(string $name, string $city = 'Lisboa'): array
    {
        return [
            'address_name' => $name,
            'street_name' => 'Rua Teste',
            'street_number' => '10',
            'postal_code' => '1000-000',
            'city' => $city,
            'latitude' => 38.72,
            'longitude' => -9.14,
        ];
    }

    private function existing(User $user, bool $main = true): Address
    {
        return Address::create([
            'user_id' => $user->id, 'name' => 'Casa', 'street_name' => 'Rua A',
            'street_number' => '1', 'postal_code' => '1000-000',
            'city' => 'Lisboa', 'municipality' => 'Lisboa', 'state' => 'Lisboa',
            'country' => 'Portugal', 'latitude' => 38.7, 'longitude' => -9.1,
            'main_address' => $main, 'address_type' => AddressType::HOUSE_ADDRESS,
        ]);
    }

    public function test_first_address_is_main_second_is_not(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')->postJson('/api/v1/customer/addresses', $this->payload('Casa Cascais'))
            ->assertStatus(201)->assertJsonPath('data.address.main_address', true);

        $this->actingAs($user, 'api')->postJson('/api/v1/customer/addresses', $this->payload('Apartamento Baixa'))
            ->assertStatus(201)->assertJsonPath('data.address.main_address', false);

        $this->actingAs($user, 'api')->getJson('/api/v1/customer/addresses')
            ->assertOk()->assertJsonCount(2, 'data.addresses');
        $this->assertSame(1, $user->addresses()->where('main_address', true)->count());
    }

    public function test_store_with_main_flag_unsets_previous(): void
    {
        $user = User::factory()->create();
        $first = $this->existing($user);

        $this->actingAs($user, 'api')->postJson('/api/v1/customer/addresses', [...$this->payload('Nova'), 'main_address' => true])
            ->assertStatus(201);

        $this->assertFalse((bool) $first->fresh()->main_address);
        $this->assertSame(1, $user->addresses()->where('main_address', true)->count());
    }

    public function test_set_main_flips_the_default(): void
    {
        $user = User::factory()->create();
        $a = $this->existing($user, main: true);
        $b = $this->existing($user, main: false);

        $this->actingAs($user, 'api')->putJson("/api/v1/customer/addresses/{$b->id}/main")->assertOk();

        $this->assertFalse((bool) $a->fresh()->main_address);
        $this->assertTrue((bool) $b->fresh()->main_address);
    }

    public function test_update_keeps_main_flag(): void
    {
        $user = User::factory()->create();
        $a = $this->existing($user, main: true);

        $this->actingAs($user, 'api')->putJson("/api/v1/customer/addresses/{$a->id}", $this->payload('Renomeada'))
            ->assertOk()->assertJsonPath('data.address.address_name', 'Renomeada');

        $this->assertTrue((bool) $a->fresh()->main_address);
    }

    public function test_deleting_main_promotes_another(): void
    {
        $user = User::factory()->create();
        $main = $this->existing($user, main: true);
        $other = $this->existing($user, main: false);

        $this->actingAs($user, 'api')->deleteJson("/api/v1/customer/addresses/{$main->id}")->assertOk();

        $this->assertSoftDeleted('addresses', ['id' => $main->id]);
        $this->assertTrue((bool) $other->fresh()->main_address);
    }

    public function test_cannot_touch_another_customers_address(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $addr = $this->existing($owner);

        $this->actingAs($intruder, 'api')->putJson("/api/v1/customer/addresses/{$addr->id}/main")->assertStatus(404);
        $this->actingAs($intruder, 'api')->deleteJson("/api/v1/customer/addresses/{$addr->id}")->assertStatus(404);
    }
}
