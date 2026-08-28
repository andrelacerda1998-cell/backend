<?php

namespace Tests\Feature\Vendor;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\Service;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Regressão: enviar a localização com um serviço ACCEPTED sem agenda cujo
 * endereço não traz todas as chaves (ex.: sem `additional_info`) rebentava com
 * "Undefined array key" em formatDataForCustomer() — um 500 que, na app, punha
 * um toast permanente por cima do botão de ação do serviço.
 */
class UpdateLocationAddressKeysTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_location_survives_partial_address(): void
    {
        Event::fake(); // não tentar broadcast real

        $vendor = Vendor::factory()->create();

        $service = Service::factory()->create([
            'vendor_id' => $vendor->id,
            'status' => ServiceStatus::ACCEPTED,
            'payment_status' => PaymentStatus::PAID,
        ]);
        // Endereço sem `additional_info` (campo opcional).
        $service->forceFill(['address' => [
            'name' => 'Casa', 'street_name' => 'Rua A', 'city' => 'Lisboa',
            'latitude' => 38.72, 'longitude' => -9.14,
        ]])->save();

        $this->actingAs($vendor->user, 'api')
            ->putJson('/api/v1/vendor/location/update', [
                'latitude' => 38.71,
                'longitude' => -9.13,
                'device_id' => 'test-device',
            ])
            ->assertOk();
    }
}
