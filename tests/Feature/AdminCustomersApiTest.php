<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminCustomersApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Ver AdminVendorDocumentsApiTest -- criar um Vendor dispara uma cadeia de
        // observers (VendorObserver → ScheduleAvailable → ScheduleAvailableObserver)
        // que tenta indexar no Meilisearch. Precisamos de criar um Vendor no teste
        // que confirma o filtro whereDoesntHave('vendor').
        config(['scout.driver' => 'null']);
    }

    private function withAuth(): static
    {
        config(['services.admin_api.token' => 'a-valid-token']);

        return $this->withHeaders(['Authorization' => 'Bearer a-valid-token']);
    }

    public function test_it_lists_customers_excluding_admins_vendors_and_test_users(): void
    {
        $customer = User::factory()->create(['first_name' => 'Ana', 'last_name' => 'Silva']);

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['first_name' => 'Admin', 'last_name' => 'User']);
        $admin->assignRole('admin');

        $vendorUser = User::factory()->create(['first_name' => 'Carlos', 'last_name' => 'Mendes']);
        Vendor::create([
            'user_id' => $vendorUser->id,
            'username' => 'carlos_'.$vendorUser->id,
            'iban' => 'PT50000201231234567890154',
        ]);

        User::factory()->create(['first_name' => 'Teste', 'last_name' => 'Interno', 'is_test' => true]);

        $this->withAuth()
            ->getJson('/api/v1/admin/customers')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $customer->id)
            ->assertJsonPath('data.items.0.name', 'Ana Silva');
    }

    public function test_it_searches_customers_by_name_email_and_nif(): void
    {
        $target = User::factory()->create(['first_name' => 'Ana', 'last_name' => 'Silva', 'email' => 'ana@example.com']);
        User::factory()->create(['first_name' => 'Bruno', 'last_name' => 'Costa']);

        $this->withAuth()
            ->getJson('/api/v1/admin/customers?search=ana')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $target->id);
    }

    public function test_it_blocks_a_customer_via_soft_delete(): void
    {
        $customer = User::factory()->create();

        $this->withAuth()
            ->putJson("/api/v1/admin/customers/{$customer->id}/block")
            ->assertOk()
            ->assertJsonPath('data.id', $customer->id)
            ->assertJsonPath('data.blocked_at', fn ($v) => $v !== null);

        $this->assertSoftDeleted('users', ['id' => $customer->id]);

        $this->withAuth()
            ->getJson('/api/v1/admin/customers')
            ->assertOk()
            ->assertJsonCount(0, 'data.items');
    }

    public function test_it_rejects_blocking_an_already_blocked_customer(): void
    {
        $customer = User::factory()->create();
        $customer->delete();

        $this->withAuth()
            ->putJson("/api/v1/admin/customers/{$customer->id}/block")
            ->assertStatus(409);
    }

    public function test_it_restores_a_blocked_customer(): void
    {
        $customer = User::factory()->create();
        $customer->delete();

        $this->withAuth()
            ->putJson("/api/v1/admin/customers/{$customer->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.blocked_at', null);

        $this->assertDatabaseHas('users', ['id' => $customer->id, 'deleted_at' => null]);
    }

    public function test_it_rejects_restoring_a_customer_that_is_not_blocked(): void
    {
        $customer = User::factory()->create();

        $this->withAuth()
            ->putJson("/api/v1/admin/customers/{$customer->id}/restore")
            ->assertStatus(409);
    }

    public function test_it_returns_404_for_an_unknown_customer(): void
    {
        $this->withAuth()
            ->putJson('/api/v1/admin/customers/999999/block')
            ->assertStatus(404);
    }

    public function test_blocked_list_only_shows_blocked_customers(): void
    {
        $active = User::factory()->create();
        $blocked = User::factory()->create();
        $blocked->delete();

        $this->withAuth()
            ->getJson('/api/v1/admin/customers?blocked=1')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $blocked->id);
    }
}
