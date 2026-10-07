<?php

namespace Tests\Feature\Customer;

use App\Enums\Services\AddressType;
use App\Models\Address;
use App\Models\User;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Geocoder\Facades\Geocoder;
use Tests\TestCase;

/**
 * Quem já tem conta e volta a confirmar o telemóvel (agora no toque em
 * "Pedir") não ganha uma morada nova de cada vez.
 */
class RegistoDeConvidadoSemMoradasRepetidasTest extends TestCase
{
    use RefreshDatabase;

    private const TELEFONE = '+351912345678';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GenderSeeder::class);
        Geocoder::shouldReceive('setLanguage')->andReturnSelf();
        Geocoder::shouldReceive('getAddressForCoordinates')->andReturn([]);
    }

    private function morada(array $extra = []): array
    {
        return array_merge([
            'latitude' => 38.7104, 'longitude' => -9.1366,
            'street_name' => 'Rua Augusta', 'street_number' => '100',
            'postal_code' => '1100-048', 'city' => 'Lisboa', 'state' => 'Lisboa', 'country' => 'Portugal',
        ], $extra);
    }

    private function registar(array $morada)
    {
        Cache::put('guest_phone_verified:'.self::TELEFONE, 'token-teste', 600);

        return $this->postJson('/api/v1/auth/guest/register', [
            'phone_number' => self::TELEFONE,
            'verification_token' => 'token-teste',
            'address' => $morada,
        ]);
    }

    private function clienteComMorada(): User
    {
        $user = User::factory()->create(['phone_number' => self::TELEFONE, 'phone_number_verified_at' => now()]);
        Address::create(array_merge($this->morada(['street_name' => 'RUA AUGUSTA']), [
            'user_id' => $user->id, 'name' => 'Casa', 'address_type' => AddressType::HOUSE_ADDRESS, 'main_address' => false,
        ]));

        return $user;
    }

    public function test_a_mesma_morada_nao_se_repete_e_passa_a_principal(): void
    {
        $user = $this->clienteComMorada();

        $this->registar($this->morada())->assertOk();

        $this->assertSame(1, $user->addresses()->count());
        $this->assertTrue((bool) $user->addresses()->first()->main_address);
    }

    public function test_uma_morada_diferente_e_guardada_como_principal(): void
    {
        $user = $this->clienteComMorada();

        $this->registar($this->morada(['street_number' => '200']))->assertOk();

        $this->assertSame(2, $user->addresses()->count());
        $this->assertSame('200', $user->addresses()->where('main_address', true)->value('street_number'));
    }
}
