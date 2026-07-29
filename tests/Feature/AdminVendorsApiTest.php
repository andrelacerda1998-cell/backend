<?php

namespace Tests\Feature;

use App\Enums\Vendors\StatusVendor;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminVendorsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Ver AdminVendorDocumentsApiTest -- criar um Vendor dispara uma cadeia de
        // observers (VendorObserver → ScheduleAvailable → ScheduleAvailableObserver)
        // que tenta indexar no Meilisearch.
        config(['scout.driver' => 'null']);
    }

    private function withAuth(): static
    {
        config(['services.admin_api.token' => 'a-valid-token']);

        return $this->withHeaders(['Authorization' => 'Bearer a-valid-token']);
    }

    /**
     * Não há factory para Vendor -- criado diretamente. 'username' é o único
     * NOT NULL sem default na tabela `vendors` além de user_id (confirmado
     * na migration), e já está no $fillable do model.
     */
    private function makeVendor(array $userAttrs = [], array $vendorAttrs = []): Vendor
    {
        $user = User::factory()->create(array_merge(['first_name' => 'Carlos', 'last_name' => 'Mendes'], $userAttrs));

        return Vendor::create(array_merge([
            'user_id' => $user->id,
            'username' => 'carlos_'.$user->id,
            'iban' => 'PT50000201231234567890154',
        ], $vendorAttrs));
    }

    public function test_it_lists_vendors_excluding_test_users(): void
    {
        $vendor = $this->makeVendor(['first_name' => 'Carlos', 'last_name' => 'Mendes']);
        $this->makeVendor(['first_name' => 'Teste', 'last_name' => 'Interno', 'is_test' => true]);

        $this->withAuth()
            ->getJson('/api/v1/admin/vendors')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $vendor->id)
            ->assertJsonPath('data.items.0.name', 'Carlos Mendes');
    }

    public function test_it_searches_vendors_by_name_nif_and_phone(): void
    {
        $target = $this->makeVendor(['first_name' => 'Ana', 'last_name' => 'Silva', 'nif' => '123456789']);
        $this->makeVendor(['first_name' => 'Bruno', 'last_name' => 'Costa']);

        $this->withAuth()
            ->getJson('/api/v1/admin/vendors?search=ana')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $target->id);
    }

    public function test_it_presents_price_rate_in_euros_and_status(): void
    {
        $vendor = $this->makeVendor(vendorAttrs: ['price_rate' => 1250, 'status' => StatusVendor::ONLINE]);

        $this->withAuth()
            ->getJson('/api/v1/admin/vendors')
            ->assertOk()
            ->assertJsonPath('data.items.0.price_rate', 12.5)
            ->assertJsonPath('data.items.0.status', 'Online');
    }

    public function test_it_suspends_a_vendor_via_soft_delete(): void
    {
        $vendor = $this->makeVendor();

        $this->withAuth()
            ->putJson("/api/v1/admin/vendors/{$vendor->id}/suspend")
            ->assertOk()
            ->assertJsonPath('data.id', $vendor->id)
            ->assertJsonPath('data.suspended_at', fn ($v) => $v !== null);

        $this->assertSoftDeleted('vendors', ['id' => $vendor->id]);

        $this->withAuth()
            ->getJson('/api/v1/admin/vendors')
            ->assertOk()
            ->assertJsonCount(0, 'data.items');
    }

    public function test_it_rejects_suspending_an_already_suspended_vendor(): void
    {
        $vendor = $this->makeVendor();
        $vendor->delete();

        $this->withAuth()
            ->putJson("/api/v1/admin/vendors/{$vendor->id}/suspend")
            ->assertStatus(409);
    }

    public function test_it_restores_a_suspended_vendor(): void
    {
        $vendor = $this->makeVendor();
        $vendor->delete();

        $this->withAuth()
            ->putJson("/api/v1/admin/vendors/{$vendor->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.suspended_at', null);

        $this->assertDatabaseHas('vendors', ['id' => $vendor->id, 'deleted_at' => null]);
    }

    public function test_it_rejects_restoring_a_vendor_that_is_not_suspended(): void
    {
        $vendor = $this->makeVendor();

        $this->withAuth()
            ->putJson("/api/v1/admin/vendors/{$vendor->id}/restore")
            ->assertStatus(409);
    }

    public function test_it_returns_404_for_an_unknown_vendor(): void
    {
        $this->withAuth()
            ->putJson('/api/v1/admin/vendors/999999/suspend')
            ->assertStatus(404);
    }

    public function test_suspended_list_only_shows_suspended_vendors(): void
    {
        $this->makeVendor();
        $suspended = $this->makeVendor();
        $suspended->delete();

        $this->withAuth()
            ->getJson('/api/v1/admin/vendors?suspended=1')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $suspended->id);
    }
}
