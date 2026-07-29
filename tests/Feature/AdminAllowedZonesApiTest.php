<?php

namespace Tests\Feature;

use App\Models\GeneralSettings\AllowedZone;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

class AdminAllowedZonesApiTest extends TestCase
{
    // DatabaseTruncation por defeito (ver nota extensa em AdminVendorsApiTest):
    // garante 'allowed_zone'/'vendor_allowed_zones' limpas antes de cada
    // teste. Nota: a tabela chama-se 'allowed_zone' (singular), não
    // 'allowed_zones' -- confirmado na migration.
    use DatabaseTruncation;

    protected array $tablesToTruncate = ['allowed_zone', 'vendor_allowed_zones', 'users', 'vendors', 'wallets'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null']);
    }

    private function withAuth(): static
    {
        config(['services.admin_api.token' => 'a-valid-token']);

        return $this->withHeaders(['Authorization' => 'Bearer a-valid-token']);
    }

    private function makeZone(string $city = 'Lisboa', ?string $district = 'Lisboa'): AllowedZone
    {
        return AllowedZone::create(['city' => $city, 'district' => $district]);
    }

    public function test_it_lists_zones_ordered_by_city_with_vendor_count(): void
    {
        $this->makeZone('Sintra', 'Lisboa');
        $porto = $this->makeZone('Porto', 'Porto');

        $user = User::factory()->create();
        $vendor = Vendor::create(['user_id' => $user->id, 'username' => 'tecnico_'.$user->id]);
        $vendor->allowedZones()->attach($porto->id);

        $this->withAuth()
            ->getJson('/api/v1/admin/allowed-zones')
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            // ordenado por cidade: Porto antes de Sintra
            ->assertJsonPath('data.items.0.city', 'Porto')
            ->assertJsonPath('data.items.0.district', 'Porto')
            ->assertJsonPath('data.items.0.vendors_count', 1)
            ->assertJsonPath('data.items.1.city', 'Sintra')
            ->assertJsonPath('data.items.1.vendors_count', 0);
    }

    public function test_it_searches_zones_by_city_or_district(): void
    {
        $target = $this->makeZone('Cascais', 'Lisboa');
        $this->makeZone('Braga', 'Braga');

        $this->withAuth()
            ->getJson('/api/v1/admin/allowed-zones?search=cascais')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $target->id);
    }

    public function test_it_creates_a_zone(): void
    {
        $response = $this->withAuth()->postJson('/api/v1/admin/allowed-zones', [
            'city' => 'Oeiras',
            'district' => 'Lisboa',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.city', 'Oeiras')
            ->assertJsonPath('data.district', 'Lisboa')
            ->assertJsonPath('data.vendors_count', 0);

        $this->assertDatabaseCount('allowed_zone', 1);
    }

    public function test_it_creates_a_zone_without_district(): void
    {
        $this->withAuth()
            ->postJson('/api/v1/admin/allowed-zones', ['city' => 'Faro'])
            ->assertCreated()
            ->assertJsonPath('data.city', 'Faro')
            ->assertJsonPath('data.district', null);
    }

    public function test_it_validates_city_required_on_create(): void
    {
        $this->withAuth()
            ->postJson('/api/v1/admin/allowed-zones', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['city']);
    }

    public function test_it_updates_a_zone(): void
    {
        $zone = $this->makeZone('Amadora', 'Lisboa');

        $this->withAuth()
            ->putJson("/api/v1/admin/allowed-zones/{$zone->id}", ['district' => 'Grande Lisboa'])
            ->assertOk()
            ->assertJsonPath('data.id', $zone->id)
            ->assertJsonPath('data.city', 'Amadora')
            ->assertJsonPath('data.district', 'Grande Lisboa');
    }

    public function test_it_returns_404_for_an_unknown_zone(): void
    {
        $this->withAuth()
            ->putJson('/api/v1/admin/allowed-zones/999999', ['city' => 'X'])
            ->assertNotFound();
    }
}
