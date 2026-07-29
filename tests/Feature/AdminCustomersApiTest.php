<?php

namespace Tests\Feature;

use App\Enums\Services\AddressType;
use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\Address;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

    private function makeService(
        User $customer,
        ServiceStatus $status = ServiceStatus::CLOSED,
        int $priceRateCents = 0,
        ?int $ratingByCustomer = null,
        bool $isTest = false,
        ?Carbon $createdAt = null,
    ): Service {
        // INSERT direto pela query builder, não Service::create(): 'price_rate'
        // e 'payment_status' não estão no $fillable do model -- create() ignora-os
        // em silêncio, e 'payment_status' é NOT NULL sem default na coluna, o que
        // rebenta logo no INSERT (não dava para gravar a seguir com um UPDATE).
        // 'price_rate' também não pode ser atribuído via ->price_rate = ... depois:
        // o Attribute priceRate() tem um `set` que espera EUROS e multiplica por
        // 100, o que duplicaria a conversão para um valor já em cêntimos.
        $timestamp = $createdAt ?? now();

        $id = DB::table('services')->insertGetId([
            'customer_id' => $customer->id,
            'status' => $status->value,
            'payment_status' => PaymentStatus::PAID->value,
            'price_rate' => $priceRateCents,
            'rating_by_customer' => $ratingByCustomer,
            'is_test' => $isTest,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return Service::findOrFail($id);
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

    public function test_metrics_computes_real_indicators_from_completed_services(): void
    {
        // a: 1 serviço concluído (pontual), com avaliação.
        $a = User::factory()->create();
        $this->makeService($a, priceRateCents: 1200, ratingByCustomer: 5);

        // b: 2 serviços concluídos (recorrente), sem avaliação.
        $b = User::factory()->create();
        $this->makeService($b, priceRateCents: 600, createdAt: now()->subDays(10));
        $this->makeService($b, priceRateCents: 600, createdAt: now());

        // c: sem serviços -- inativo.
        User::factory()->create();

        // d: sem serviços, registado há 40 dias -- não conta em "novos".
        User::factory()->create(['created_at' => now()->subDays(40)]);

        // Não devem contar: serviço não-CLOSED do a, e serviço de teste do e.
        $this->makeService($a, status: ServiceStatus::PENDING, priceRateCents: 999999);
        $e = User::factory()->create();
        $this->makeService($e, priceRateCents: 999999, isTest: true);

        $this->withAuth()
            ->getJson('/api/v1/admin/customers/metrics')
            ->assertOk()
            ->assertJsonPath('data.registered', 5)
            ->assertJsonPath('data.newCustomers', 4)
            ->assertJsonPath('data.active', 2)
            ->assertJsonPath('data.recurring', 1)
            ->assertJsonPath('data.oneTime', 1)
            ->assertJsonPath('data.inactive', 3)
            ->assertJsonPath('data.avgServicesPerCustomer', 0.6)
            ->assertJsonPath('data.avgRevenuePerCustomer', 4.8)
            // json_encode(12.0)/(5.0) saem "12"/"5" (sem parte decimal, números
            // inteiros) -- comparar com o int, não o float, ver nota em
            // AdminVendorPaymentsApiTest sobre assertJsonPath usar ===.
            ->assertJsonPath('data.estimatedLTV', 12)
            ->assertJsonPath('data.averageRating', 5)
            ->assertJsonPath('data.withComplaints', 0);
    }

    public function test_by_location_groups_customers_by_main_address_city(): void
    {
        $a = User::factory()->create();
        Address::create([
            'user_id' => $a->id, 'name' => 'Casa', 'street_name' => 'Rua A', 'street_number' => '1',
            'postal_code' => '1000-001', 'city' => 'Lisboa', 'state' => 'Lisboa', 'country' => 'Portugal',
            'main_address' => true, 'address_type' => AddressType::HOUSE_ADDRESS,
        ]);

        $b = User::factory()->create();
        Address::create([
            'user_id' => $b->id, 'name' => 'Casa', 'street_name' => 'Rua B', 'street_number' => '2',
            'postal_code' => '4000-001', 'city' => 'Porto', 'state' => 'Porto', 'country' => 'Portugal',
            'main_address' => true, 'address_type' => AddressType::HOUSE_ADDRESS,
        ]);

        $c = User::factory()->create();
        Address::create([
            'user_id' => $c->id, 'name' => 'Casa', 'street_name' => 'Rua C', 'street_number' => '3',
            'postal_code' => '1000-002', 'city' => 'Lisboa', 'state' => 'Lisboa', 'country' => 'Portugal',
            'main_address' => true, 'address_type' => AddressType::HOUSE_ADDRESS,
        ]);
        // Morada secundária -- não deve contar (só main_address).
        Address::create([
            'user_id' => $c->id, 'name' => 'Trabalho', 'street_name' => 'Rua D', 'street_number' => '4',
            'postal_code' => '8000-001', 'city' => 'Faro', 'state' => 'Faro', 'country' => 'Portugal',
            'main_address' => false, 'address_type' => AddressType::HOUSE_ADDRESS,
        ]);

        $res = $this->withAuth()->getJson('/api/v1/admin/customers/by-location')->assertOk();
        $data = collect($res->json('data'))->keyBy('name');

        $this->assertSame(2, $data['Lisboa']['value']);
        $this->assertSame(1, $data['Porto']['value']);
        $this->assertArrayNotHasKey('Faro', $data->toArray());
    }

    public function test_by_source_and_retention_return_empty_on_purpose(): void
    {
        // Sem tracking de origem de aquisição nem análise de coortes no
        // Laravel -- devolvem vazio em vez de inventar números.
        $this->withAuth()->getJson('/api/v1/admin/customers/by-source')->assertOk()->assertJsonPath('data', []);
        $this->withAuth()->getJson('/api/v1/admin/customers/retention')->assertOk()->assertJsonPath('data', []);
    }

    public function test_trend_reports_novos_and_recorrentes_for_the_current_month(): void
    {
        // Cliente novo este mês, sem serviços ainda.
        User::factory()->create(['created_at' => now()]);

        // Cliente recorrente: primeiro serviço há 2 meses, segundo este mês.
        $recurring = User::factory()->create(['created_at' => now()->subMonths(3)]);
        $this->makeService($recurring, createdAt: now()->subMonths(2));
        $this->makeService($recurring, createdAt: now());

        $res = $this->withAuth()->getJson('/api/v1/admin/customers/trend')->assertOk();
        $months = collect($res->json('data'));
        $this->assertCount(6, $months);

        $current = $months->last();
        $this->assertSame(1, $current['novos']); // só o cliente novo, não o recorrente (criado há 3 meses)
        $this->assertSame(1, $current['recorrentes']);
    }
}
