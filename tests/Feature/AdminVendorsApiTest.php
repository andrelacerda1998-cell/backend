<?php

namespace Tests\Feature;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Enums\Vendors\StatusVendor;
use App\Models\GeneralSettings\AllowedZone;
use App\Models\GeneralSettings\OperationArea;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminVendorsApiTest extends TestCase
{
    // NÃO RefreshDatabase aqui: AdminVendorPaymentsApiTest corre antes desta
    // classe (ordem alfabética: "VendorPayments" < "Vendors") e usa
    // DatabaseTruncation em modo autocommit -- os Vendors/Users que cria
    // ficam mesmo gravados na BD, fora de qualquer transação. RefreshDatabase
    // só embrulha CADA teste numa transação que é revertida no fim; não limpa
    // linhas que outra classe já tinha committado antes de esta classe
    // começar. Resultado: um "Carlos Mendes" residual do teste de pagamentos
    // aparecia nas listagens daqui (contagens a mais). DatabaseTruncation
    // resolve porque limpa mesmo as tabelas antes de cada teste, independente
    // de quem as sujou.
    use DatabaseTruncation;

    // 'wallets' tem de entrar aqui também: UserObserver cria uma wallet
    // "default-wallet" para cada User novo (unique em holder_type+holder_id+
    // slug). Sem truncar 'wallets', o TRUNCATE de 'users' reinicia o
    // auto_increment (users volta a começar em 1), mas a wallet antiga do
    // User#1 de um teste anterior continua na tabela -- o próximo User#1
    // criado colide com ela. Mesma lista de tabelas que
    // AdminVendorPaymentsApiTest usa, e pela mesma razão.
    // 'services', 'operation_areas'/'operation_area_vendors' e
    // 'allowed_zone'/'vendor_allowed_zones' entram por causa dos testes de
    // métricas (Visão geral) -- nenhuma delas tem factory/seeder corrido em
    // testes, por isso são criadas diretamente em cada teste que precisa.
    protected array $tablesToTruncate = [
        'users', 'vendors', 'wallets', 'schedule_available',
        'services', 'operation_areas', 'operation_area_vendors',
        'allowed_zone', 'vendor_allowed_zones',
    ];

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

    public function test_it_searches_vendors_by_email(): void
    {
        $target = $this->makeVendor(['first_name' => 'Ana', 'last_name' => 'Silva', 'email' => 'ana.silva@piquet.pt']);
        $this->makeVendor(['first_name' => 'Bruno', 'last_name' => 'Costa', 'email' => 'bruno.costa@piquet.pt']);

        $this->withAuth()
            ->getJson('/api/v1/admin/vendors?search=ana.silva')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $target->id);
    }

    public function test_it_presents_price_rate_in_euros_and_status(): void
    {
        // Vendor::priceRate() tem um `set` que espera uma STRING EM EUROS e
        // multiplica por 100 para gravar em cêntimos (mesmo padrão do
        // Service::priceRate(), ver nota em AdminCustomersApiTest) -- passar
        // 1250 diretamente seria interpretado como 1250€, não 1250 cêntimos.
        $vendor = $this->makeVendor(vendorAttrs: ['price_rate' => '12.50', 'status' => StatusVendor::ONLINE]);

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

    /**
     * Um vendor elegível para aceitar serviço (Vendor::canAcceptService) --
     * mesmas 8 condições que o Filament exige. Sem Document nenhum na BD,
     * Vendor::allDocumentsVerified é `true` por omissão (o accessor só
     * itera required_documents, que fica vazio) -- por isso um vendor
     * "vazio" já conta como docComplete/elegível desde que os outros campos
     * estejam certos.
     */
    private function makeEligibleVendor(array $userAttrs = []): Vendor
    {
        return $this->makeVendor($userAttrs, [
            'invoice_workspace' => 'ws-'.uniqid(),
            'at_valid' => true,
            'at_user' => 'sub/user',
        ]);
    }

    /**
     * INSERT direto pela query builder -- 'status' e 'payment_status' são os
     * únicos NOT NULL sem default em `services` (mesma lição de
     * AdminCustomersApiTest::makeService()); 'address' é json, tem de ir
     * já codificado porque bypassa o cast do Eloquent.
     */
    private function makeService(
        Vendor $vendor,
        ServiceStatus $status = ServiceStatus::CLOSED,
        ?int $amount = null,
        ?int $amountForVendor = null,
        ?int $ratingByVendor = null,
        ?string $city = null,
        bool $isTest = false,
        ?Carbon $createdAt = null,
    ): int {
        $timestamp = $createdAt ?? now();

        return DB::table('services')->insertGetId([
            'vendor_id' => $vendor->id,
            'status' => $status->value,
            'payment_status' => PaymentStatus::PAID->value,
            'amount' => $amount,
            'amount_for_vendor' => $amountForVendor,
            'rating_by_vendor' => $ratingByVendor,
            'address' => $city ? json_encode(['city' => $city]) : null,
            'is_test' => $isTest,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    public function test_metrics_computes_real_indicators(): void
    {
        $eligible = $this->makeEligibleVendor();
        $this->makeVendor(); // sem invoice_workspace/at_user -- não elegível

        $this->withAuth()
            ->getJson('/api/v1/admin/vendors/metrics')
            ->assertOk()
            ->assertJsonPath('data.registered', 2)
            ->assertJsonPath('data.eligible', 1)
            ->assertJsonPath('data.docComplete', 2) // sem Document nenhum -> true por omissão
            ->assertJsonPath('data.inValidation', 0)
            ->assertJsonPath('data.noServices', 1) // o elegível ainda não tem serviços
            ->assertJsonPath('data.approvalRate', 50)
            ->assertJsonPath('data.profileCompletionRate', 100)
            ->assertJsonPath('data.avgTimeToFirstService', 0);

        $this->makeService($eligible, createdAt: $eligible->created_at->copy()->addDays(3));

        $this->withAuth()
            ->getJson('/api/v1/admin/vendors/metrics')
            ->assertOk()
            ->assertJsonPath('data.noServices', 0)
            ->assertJsonPath('data.avgTimeToFirstService', 3);
    }

    public function test_by_category_counts_vendors_per_operation_area(): void
    {
        $area = OperationArea::create(['name' => 'Canalização']);
        $vendor = $this->makeVendor();
        $vendor->operationAreas()->attach($area->id);
        $this->makeVendor(); // sem categoria -- não deve entrar na contagem

        $res = $this->withAuth()->getJson('/api/v1/admin/vendors/by-category')->assertOk();
        $data = collect($res->json('data'))->keyBy('name');

        $this->assertSame(1, $data['Canalização']['value']);
    }

    public function test_by_location_counts_vendors_per_allowed_zone(): void
    {
        $zone = AllowedZone::create(['city' => 'Lisboa', 'district' => 'Lisboa']);
        $vendor = $this->makeVendor();
        $vendor->allowedZones()->attach($zone->id);
        $this->makeVendor(); // sem zona -- não deve entrar na contagem

        $res = $this->withAuth()->getJson('/api/v1/admin/vendors/by-location')->assertOk();
        $data = collect($res->json('data'))->keyBy('name');

        $this->assertSame(1, $data['Lisboa']['value']);
    }

    public function test_top_ranks_vendors_by_revenue_generated(): void
    {
        $vendor = $this->makeVendor(['first_name' => 'Ana', 'last_name' => 'Silva']);
        // amount=100,00€, amount_for_vendor=70,00€ -> comissão (receita gerada) 30,00€.
        $this->makeService($vendor, amount: 10000, amountForVendor: 7000, ratingByVendor: 5);
        // Não deve contar: serviço não-CLOSED e serviço de teste.
        $other = $this->makeVendor(['first_name' => 'Bruno', 'last_name' => 'Costa']);
        $this->makeService($other, status: ServiceStatus::PENDING, amount: 99999999, amountForVendor: 0);
        $this->makeService($other, amount: 99999999, amountForVendor: 0, isTest: true);

        $this->withAuth()
            ->getJson('/api/v1/admin/vendors/top')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Ana Silva')
            ->assertJsonPath('data.0.servicesCompleted', 1)
            ->assertJsonPath('data.0.averageRating', 5)
            ->assertJsonPath('data.0.piquetRevenue', 30)
            ->assertJsonPath('data.0.amountReceived', 70);
    }

    public function test_coverage_compares_supply_and_demand_per_city(): void
    {
        $zone = AllowedZone::create(['city' => 'Lisboa', 'district' => 'Lisboa']);
        $vendor = $this->makeEligibleVendor();
        $vendor->allowedZones()->attach($zone->id);
        $this->makeService($vendor, city: 'Lisboa');
        $this->makeService($vendor, city: 'Lisboa');

        $res = $this->withAuth()->getJson('/api/v1/admin/vendors/coverage')->assertOk();
        $data = collect($res->json('data'))->keyBy('name');

        $this->assertSame(2, $data['Lisboa']['procura']);
        $this->assertSame(1, $data['Lisboa']['oferta']);
        // json_encode(2.0) sai "2" (sem parte decimal) -- comparar com o int,
        // não o float, mesma nota já documentada em AdminVendorPaymentsApiTest.
        $this->assertSame(2, $data['Lisboa']['ratio']);
    }
}
