<?php

namespace Tests\Feature\Matching;

use App\Enums\Services\AddressType;
use App\Enums\Services\CandidateStatus;
use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Enums\Vendors\StatusVendor;
use App\Events\Matching\MatchingCandidateAcceptedEvent;
use App\Events\Matching\MatchingCandidateLostEvent;
use App\Events\Matching\MatchingInvitationEvent;
use App\Events\Matching\MatchingRequestClosedEvent;
use App\Models\Address;
use App\Models\GeneralSettings\City;
use App\Models\GeneralSettings\OperationArea;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Service;
use App\Models\ServiceCandidate;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Vendor\Location;
use App\Notifications\Customer\ServiceCanceledByVendorNotification;
use App\Services\Matching\RankedVendor;
use App\Services\Matching\VendorRankingService;
use App\Services\Matching\MatchingScope;
use App\DTO\Services\AddressCoordinatesDTO;
use App\Settings\MatchingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RwInteractive\PayshopSdk\Enums\Payment\OperationType;
use RwInteractive\PayshopSdk\Enums\Payment\Status;
use RwInteractive\PayshopSdk\Models\PaymentOrder;
use Tests\TestCase;

/**
 * As cidades do técnico são a área de trabalho dele no matching.
 *
 * Primeiro quem trabalha na cidade da morada; se não houver ninguém, quem
 * está perto (até 30 km), com o convite a dizer "fora das tuas cidades";
 * nunca para lá do raio máximo.
 */
class AreaDeTrabalhoTest extends TestCase
{
    use RefreshDatabase;

    private OperationArea $area;

    private ServicesType $type;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        MatchingSettings::fake([
            'shortlist_size' => 3,
            'wave_size' => 6,
            'wave_interval_seconds' => 45,
            'max_waves' => 3,
            'vendor_response_seconds_immediate' => 120,
            'vendor_response_seconds_scheduled' => 120,
            'customer_choice_seconds' => 300,
            'customer_choice_seconds_scheduled' => 300,
            'checkout_seconds' => 300,
            'rating_bands' => [4.5, 4.0, 3.0],
            'new_vendor_min_ratings' => 5,
            'require_recent_activity_minutes' => 15,
            'customer_choice_seconds_custom' => 3600,
            'request_deadline_seconds' => 600,
        ]);

        Event::fake([
            MatchingInvitationEvent::class,
            MatchingCandidateAcceptedEvent::class,
            MatchingRequestClosedEvent::class,
            MatchingCandidateLostEvent::class,
        ]);
        Notification::fake();
        // A fatura de cancelamento vai para a fila; aqui não pode bater no InvoiceXpress.
        \Illuminate\Support\Facades\Queue::fake();
        config(['broadcasting.default' => 'null']);

        // O gateway nunca é chamado a sério: porta onde não há ninguém.
        config([
            'payshop-sdk.environment' => 'sandbox',
            'payshop-sdk.api_endpoint.sandbox' => 'http://127.0.0.1:9/',
            'payshop-sdk.connect_timeout' => 0.25,
            'payshop-sdk.timeout' => 0.25,
        ]);

        $this->area = OperationArea::factory()->create();
        $this->type = ServicesType::factory()->create(['operation_area_id' => $this->area->id, 'time' => 60]);
        $this->customer = User::factory()->create(['is_test' => true]);
        $this->morada($this->customer, AddressType::HOUSE_ADDRESS, main: true);
    }

    private function morada(User $user, $type, bool $main = false): Address
    {
        return Address::create([
            'user_id' => $user->id,
            'name' => 'Casa',
            'street_name' => 'Rua de Exemplo',
            'street_number' => '1',
            'postal_code' => '4000-000',
            'city' => 'Porto',
            'municipality' => 'Porto',
            'state' => 'Porto',
            'country' => 'Portugal',
            'latitude' => 41.1478,
            'longitude' => -8.6110,
            'main_address' => $main,
            'address_type' => $type,
        ]);
    }

    /** Um técnico pronto a receber convites (o `can_accept_service` passa). */
    private function tecnico(int $rate = 20): Vendor
    {
        $user = User::factory()->create([
            'is_test' => true,
            'email_verified_at' => now(),
            'phone_number_verified_at' => now(),
        ]);

        $vendor = Vendor::factory()->create([
            'user_id' => $user->id,
            'price_rate' => $rate,
            'status' => StatusVendor::ONLINE,
            'iban' => 'PT50000000000000000000000',
            'invoice_workspace' => 'ws-'.$user->id,
            'at_user' => '999999999/1',
            'at_valid' => true,
            'at_validated_at' => now(),
        ]);

        $vendor->operationAreas()->attach($this->area->id);
        $vendor->servicesTypes()->attach($this->type->id);
        $vendor->currentLocation()->save(new Location(['latitude' => 41.15, 'longitude' => -8.61]));
        $this->morada($user, AddressType::SCHEDULE_ADDRESS);

        return $vendor->fresh();
    }

    private function servicoDe(Vendor $vendor, ServiceStatus $estado, array $extra = []): Service
    {
        return Service::factory()->create(array_merge([
            'customer_id' => $this->customer->id,
            'vendor_id' => $vendor->id,
            'services_type_id' => $this->type->id,
            'status' => $estado,
            'payment_status' => PaymentStatus::PAID,
            'amount' => 5000,
            'amount_for_vendor' => 3750,
            'credit_used' => 0,
            'is_test' => true,
            'address' => [
                'name' => 'Casa', 'street_name' => 'Rua de Exemplo', 'street_number' => '1',
                'postal_code' => '4000-000', 'city' => 'Porto',
                'latitude' => 41.1478, 'longitude' => -8.6110,
            ],
        ], $extra));
    }



    private function cidade(string $nome, ?float $lat, ?float $lng, int $raio = 15): City
    {
        return City::create(['name' => $nome, 'district' => 'Teste', 'latitude' => $lat, 'longitude' => $lng, 'radius_km' => $raio]);
    }

    private function tecnicoEm(array $cidades, float $lat = 41.15, float $lng = -8.61, int $rate = 20): Vendor
    {
        $v = $this->tecnico($rate);
        $v->currentLocation()->update(['latitude' => $lat, 'longitude' => $lng]);
        $v->availableCities()->sync(collect($cidades)->pluck('id')->all());

        return $v->fresh();
    }

    private function convidados(): array
    {
        $id = $this->actingAs($this->customer, 'api')
            ->postJson('/api/v1/customer/services/matching', ['service_type' => $this->type->id, 'scheduled' => false])
            ->assertOk()->json('data.service.id');

        return ServiceCandidate::where('service_id', $id)->where('status', CandidateStatus::NOTIFIED)
            ->get()->keyBy('vendor_id')->all();
    }

    public function test_quem_trabalha_na_cidade_da_morada_e_convidado_e_quem_nao_trabalha_nao(): void
    {
        $porto = $this->cidade('Porto', 41.1496, -8.6110);
        $faro = $this->cidade('Faro', 37.0194, -7.9304);
        $doPorto = $this->tecnicoEm([$porto]);
        $deFaroMasAqui = $this->tecnicoEm([$faro]); // o GPS diz Porto, mas ele escolheu Faro

        $convidados = $this->convidados();

        $this->assertArrayHasKey($doPorto->id, $convidados);
        $this->assertArrayNotHasKey($deFaroMasAqui->id, $convidados, 'as cidades que escolheu decidem, não só o GPS');
    }

    public function test_sem_ninguem_da_cidade_convida_quem_esta_perto_e_avisa(): void
    {
        $faro = $this->cidade('Faro', 37.0194, -7.9304);
        $pertoMasDeFaro = $this->tecnicoEm([$faro]);

        $convidados = $this->convidados();

        $this->assertArrayHasKey($pertoMasDeFaro->id, $convidados);
        $this->assertTrue($convidados[$pertoMasDeFaro->id]->is_outside_area);
    }

    public function test_nunca_convida_para_la_do_raio_maximo(): void
    {
        $lisboa = $this->cidade('Lisboa', 38.7223, -9.1393);
        $emLisboa = $this->tecnicoEm([$lisboa], lat: 38.72, lng: -9.14);

        $convidados = $this->convidados();

        $this->assertArrayNotHasKey($emLisboa->id, $convidados, 'um pedido no Porto não chama ninguém a 275 km');
    }

    public function test_quem_nao_escolheu_cidades_nao_fica_sem_pedidos(): void
    {
        $semCidades = $this->tecnicoEm([]);

        $this->assertArrayHasKey($semCidades->id, $this->convidados());
    }

    public function test_cidade_ainda_sem_coordenadas_conta_pelo_nome(): void
    {
        $portoSemCoordenadas = $this->cidade('Porto', null, null);
        $faro = $this->cidade('Faro', 37.0194, -7.9304);
        $doPorto = $this->tecnicoEm([$portoSemCoordenadas]);
        $deFaro = $this->tecnicoEm([$faro]);

        $convidados = $this->convidados();

        $this->assertArrayHasKey($doPorto->id, $convidados);
        $this->assertArrayNotHasKey($deFaro->id, $convidados);
    }

    public function test_o_comando_preenche_as_coordenadas(): void
    {
        $cidade = $this->cidade('Braga', null, null);
        \Spatie\Geocoder\Facades\Geocoder::shouldReceive('setLanguage')->andReturnSelf();
        \Spatie\Geocoder\Facades\Geocoder::shouldReceive('getCoordinatesForAddress')
            ->andReturn(['lat' => 41.5454, 'lng' => -8.4265, 'accuracy' => 'APPROXIMATE', 'formatted_address' => 'Braga']);

        $this->artisan('cities:geocode')->assertSuccessful();

        $this->assertEqualsWithDelta(41.5454, $cidade->fresh()->latitude, 0.0001);
    }

    public function test_o_aviso_de_procura_na_zona_le_as_cidades_de_agora(): void
    {
        $porto = $this->cidade('Porto', 41.1496, -8.6110);
        $v = $this->tecnicoEm([$porto]);
        Service::factory()->create([
            'customer_id' => $this->customer->id,
            'services_type_id' => $this->type->id,
            'status' => ServiceStatus::CLOSED,
            'is_test' => false,
            'address' => ['city' => 'Porto', 'latitude' => 41.15, 'longitude' => -8.61],
        ]);

        $this->assertSame(1, app(\App\Services\Vendor\ZoneDemand::class)->recentRequestCount($v));
    }

    public function test_uma_cidade_chega(): void
    {
        $porto = $this->cidade('Porto', 41.1496, -8.6110);
        $v = $this->tecnico();

        $this->actingAs($v->user, 'api')
            ->postJson('/api/v1/vendor/cities', ['available_city_ids' => [$porto->id]])
            ->assertOk();
    }
}
