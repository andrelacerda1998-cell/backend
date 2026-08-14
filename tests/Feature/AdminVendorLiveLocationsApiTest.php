<?php

namespace Tests\Feature;

use App\Enums\Vendors\StatusVendor;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminVendorLiveLocationsApiTest extends TestCase
{
    use DatabaseTruncation;

    protected array $tablesToTruncate = ['users', 'vendors', 'wallets', 'vendors_location'];

    protected function setUp(): void
    {
        parent::setUp();

        // Ver AdminVendorsApiTest -- criar um Vendor dispara observers que
        // tentam indexar no Meilisearch.
        config(['scout.driver' => 'null']);
    }

    private function withAuth(): static
    {
        config(['services.admin_api.token' => 'a-valid-token']);

        return $this->withHeaders(['Authorization' => 'Bearer a-valid-token']);
    }

    private function makeVendor(array $userAttrs = [], array $vendorAttrs = []): Vendor
    {
        $user = User::factory()->create(array_merge(['first_name' => 'Carlos', 'last_name' => 'Mendes'], $userAttrs));

        return Vendor::create(array_merge([
            'user_id' => $user->id,
            'username' => 'carlos_'.$user->id,
            'iban' => 'PT50000201231234567890154',
        ], $vendorAttrs));
    }

    public function test_devolve_tecnico_online_com_localizacao_recente(): void
    {
        $vendor = $this->makeVendor(vendorAttrs: ['status' => StatusVendor::ONLINE]);
        $vendor->currentLocation()->create([
            'latitude' => 38.7223,
            'longitude' => -9.1393,
            'device_id' => 'device-1',
        ]);

        $res = $this->withAuth()->getJson('/api/v1/admin/vendors/live-locations')->assertOk();

        $res->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $vendor->id)
            ->assertJsonPath('data.0.name', 'Carlos Mendes')
            ->assertJsonPath('data.0.latitude', 38.7223)
            ->assertJsonPath('data.0.longitude', -9.1393)
            ->assertJsonPath('data.0.is_test', false);
    }

    public function test_exclui_conta_de_teste_por_omissao(): void
    {
        $vendor = $this->makeVendor(userAttrs: ['is_test' => true], vendorAttrs: ['status' => StatusVendor::ONLINE]);
        $vendor->currentLocation()->create([
            'latitude' => 38.7223,
            'longitude' => -9.1393,
            'device_id' => 'device-1',
        ]);

        $this->withAuth()->getJson('/api/v1/admin/vendors/live-locations')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_include_test_mostra_conta_de_teste(): void
    {
        $vendor = $this->makeVendor(userAttrs: ['is_test' => true], vendorAttrs: ['status' => StatusVendor::ONLINE]);
        $vendor->currentLocation()->create([
            'latitude' => 38.7223,
            'longitude' => -9.1393,
            'device_id' => 'device-1',
        ]);

        $this->withAuth()->getJson('/api/v1/admin/vendors/live-locations?include_test=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $vendor->id)
            ->assertJsonPath('data.0.is_test', true);
    }

    public function test_nao_devolve_tecnico_offline(): void
    {
        $vendor = $this->makeVendor(vendorAttrs: ['status' => StatusVendor::OFFLINE]);
        $vendor->currentLocation()->create([
            'latitude' => 38.7223,
            'longitude' => -9.1393,
            'device_id' => 'device-1',
        ]);

        $this->withAuth()->getJson('/api/v1/admin/vendors/live-locations')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_nao_devolve_tecnico_online_com_localizacao_obsoleta(): void
    {
        $vendor = $this->makeVendor(vendorAttrs: ['status' => StatusVendor::ONLINE]);
        $location = $vendor->currentLocation()->create([
            'latitude' => 38.7223,
            'longitude' => -9.1393,
            'device_id' => 'device-1',
        ]);
        // App só envia GPS enquanto online/com serviço aceite -- uma
        // localização de há 30 min é sinal de app em segundo plano, não
        // presença real (ver nota em VendorController::liveLocations()).
        $location->timestamps = false;
        $location->updated_at = Carbon::now()->subMinutes(30);
        $location->save();

        $this->withAuth()->getJson('/api/v1/admin/vendors/live-locations')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_nao_devolve_tecnico_online_sem_localizacao_nenhuma(): void
    {
        $this->makeVendor(vendorAttrs: ['status' => StatusVendor::ONLINE]);

        $this->withAuth()->getJson('/api/v1/admin/vendors/live-locations')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_401_sem_token(): void
    {
        // Precisa de um token esperado configurado -- sem isto, AdminApiToken
        // devolve 503 "not configured" (fail-closed) em vez de 401.
        config(['services.admin_api.token' => 'a-valid-token']);

        $this->getJson('/api/v1/admin/vendors/live-locations')->assertUnauthorized();
    }
}
