<?php

namespace Tests\Feature\Customer;

use App\Enums\Services\AddressType;
use App\Models\Address;
use App\Models\GeneralSettings\OperationArea;
use App\Models\GeneralSettings\ServicesType;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Multi-morada no PEDIDO: para um proprietário de vários alojamentos, escolher
 * a casa é o ponto todo da funcionalidade — se o pedido cair sempre na morada
 * principal, o técnico é enviado para a casa errada.
 *
 * A morada é gravada em `services.address` como snapshot (JSON), por isso o que
 * se verifica aqui é o snapshot, não a relação.
 */
class MultiAddressRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private ServicesType $type;

    private Address $main;

    private Address $cascais;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();
        Notification::fake();
        config(['broadcasting.default' => 'null']);

        $area = OperationArea::factory()->create();
        $this->type = ServicesType::factory()->create(['operation_area_id' => $area->id, 'time' => 60]);

        $this->customer = User::factory()->create(['is_test' => true]);
        $this->main = $this->makeAddress($this->customer, 'Apartamento Baixa', 'Porto', 41.1478, -8.6110, main: true);
        $this->cascais = $this->makeAddress($this->customer, 'Casa Cascais', 'Cascais', 38.6979, -9.4215);

        // Um profissional que cubra o tipo, para o pedido poder abrir.
        $vendor = Vendor::factory()->create();
        $vendor->servicesTypes()->attach($this->type->id);
    }

    private function makeAddress(User $user, string $label, string $city, float $lat, float $lng, bool $main = false): Address
    {
        return Address::create([
            'user_id' => $user->id,
            'address_name' => $label,
            'name' => $label,
            'street_name' => 'Rua '.$label,
            'street_number' => '1',
            'postal_code' => '4000-000',
            'city' => $city,
            'municipality' => $city,
            'state' => $city,
            'country' => 'Portugal',
            'latitude' => $lat,
            'longitude' => $lng,
            'main_address' => $main,
            'address_type' => AddressType::HOUSE_ADDRESS,
        ]);
    }

    private function start(?int $addressId): \Illuminate\Testing\TestResponse
    {
        $payload = ['service_type' => $this->type->id, 'scheduled' => false];

        if ($addressId !== null) {
            $payload['address_id'] = $addressId;
        }

        return $this->actingAs($this->customer, 'api')
            ->postJson('/api/v1/customer/services/matching', $payload);
    }

    /** A cidade do snapshot é a prova de qual das casas ficou no pedido. */
    private function snapshotCity(): ?string
    {
        return \App\Models\Service::query()->latest('id')->first()?->address['city'] ?? null;
    }

    public function test_request_uses_the_chosen_address(): void
    {
        $this->start($this->cascais->id)->assertSuccessful();

        $this->assertSame('Cascais', $this->snapshotCity());
    }

    public function test_request_without_address_id_falls_back_to_main(): void
    {
        $this->start(null)->assertSuccessful();

        $this->assertSame('Porto', $this->snapshotCity());
    }

    /**
     * Segurança: um id de morada de OUTRO cliente nunca pode entrar no pedido —
     * seria expor a casa de terceiros a um profissional. Cai na principal.
     */
    public function test_another_customers_address_is_never_used(): void
    {
        $stranger = User::factory()->create();
        $strangerAddress = $this->makeAddress($stranger, 'Casa do estranho', 'Faro', 37.0194, -7.9304, main: true);

        $this->start($strangerAddress->id)->assertSuccessful();

        $this->assertSame('Porto', $this->snapshotCity());
        $this->assertNotSame('Faro', $this->snapshotCity());
    }
}
