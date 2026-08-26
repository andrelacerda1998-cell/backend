<?php

namespace Tests\Feature\Vendor;

use App\Models\GeneralSettings\City;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Disponibilidade geografica do tecnico: onde aceita trabalhar (available) e o
 * top 3 de maior interesse (preferred). Estes dados alimentam o ranking de
 * potencial de abertura no backoffice, por isso as regras de integridade —
 * minimo 3, top com 3, top subconjunto do available — sao guardadas aqui.
 */
class VendorCitiesTest extends TestCase
{
    use RefreshDatabase;

    private function vendor(): Vendor
    {
        return Vendor::factory()->create();
    }

    /** @return int[] ids das cidades criadas */
    private function cities(int $n): array
    {
        $ids = [];
        foreach (range(1, $n) as $i) {
            $ids[] = City::create([
                'name' => "Cidade $i",
                'district' => 'Distrito',
                'suggested' => $i <= 2,
            ])->id;
        }

        return $ids;
    }

    public function test_index_returns_catalog_and_current_selection(): void
    {
        $vendor = $this->vendor();
        $ids = $this->cities(4);
        $vendor->availableCities()->sync([$ids[0], $ids[1], $ids[2]]);
        $vendor->preferredCities()->sync([$ids[0] => ['position' => 1]]);

        $response = $this->actingAs($vendor->user, 'api')
            ->getJson('/api/v1/vendor/cities')
            ->assertOk();

        $response->assertJsonCount(4, 'data.cities');
        $response->assertJsonPath('data.selected.available_city_ids', [$ids[0], $ids[1], $ids[2]]);
        $response->assertJsonPath('data.selected.preferred_city_ids', [$ids[0]]);
    }

    public function test_store_persists_available_and_preferred_with_position(): void
    {
        $vendor = $this->vendor();
        $ids = $this->cities(5);

        $this->actingAs($vendor->user, 'api')
            ->postJson('/api/v1/vendor/cities', [
                'available_city_ids' => [$ids[0], $ids[1], $ids[2], $ids[3]],
                'preferred_city_ids' => [$ids[2], $ids[0], $ids[1]],
            ])
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            [$ids[0], $ids[1], $ids[2], $ids[3]],
            $vendor->availableCities()->pluck('cities.id')->all(),
        );

        $preferred = $vendor->preferredCities()->get();
        $this->assertSame($ids[2], $preferred->firstWhere('pivot.position', 1)->id);
        $this->assertSame($ids[0], $preferred->firstWhere('pivot.position', 2)->id);
        $this->assertSame($ids[1], $preferred->firstWhere('pivot.position', 3)->id);
    }

    public function test_available_requires_at_least_three(): void
    {
        $vendor = $this->vendor();
        $ids = $this->cities(3);

        $this->actingAs($vendor->user, 'api')
            ->postJson('/api/v1/vendor/cities', [
                'available_city_ids' => [$ids[0], $ids[1]],
                'preferred_city_ids' => [$ids[0], $ids[1], $ids[2]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('available_city_ids');
    }

    public function test_preferred_is_optional(): void
    {
        // O "top 3" saiu do fluxo; guardar só as disponíveis é válido.
        $vendor = $this->vendor();
        $ids = $this->cities(3);

        $this->actingAs($vendor->user, 'api')
            ->postJson('/api/v1/vendor/cities', [
                'available_city_ids' => [$ids[0], $ids[1], $ids[2]],
            ])
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            [$ids[0], $ids[1], $ids[2]],
            $vendor->availableCities()->pluck('cities.id')->all(),
        );
        $this->assertCount(0, $vendor->preferredCities()->get());
    }

    public function test_preferred_must_be_subset_of_available(): void
    {
        $vendor = $this->vendor();
        $ids = $this->cities(4);

        $this->actingAs($vendor->user, 'api')
            ->postJson('/api/v1/vendor/cities', [
                'available_city_ids' => [$ids[0], $ids[1], $ids[2]],
                'preferred_city_ids' => [$ids[0], $ids[1], $ids[3]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('preferred_city_ids');
    }
}
