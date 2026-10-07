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
 * "Já te atendeu": quem fez um bom serviço a este cliente entra na primeira
 * onda mesmo fora do top, e se aceitar o cliente vê-o — à frente.
 */
class TecnicoConhecidoTest extends TestCase
{
    use RefreshDatabase;

    private OperationArea $area;

    private ServicesType $type;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        MatchingSettings::fake([
            'shortlist_size' => 2,
            'wave_size' => 1,
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


    private function servicoFechadoCom(Vendor $vendor, ?int $nota): Service
    {
        return $this->servicoDe($vendor, ServiceStatus::CLOSED, ['rating_by_customer' => $nota]);
    }

    private function pedir(): Service
    {
        $id = $this->actingAs($this->customer, 'api')
            ->postJson('/api/v1/customer/services/matching', ['service_type' => $this->type->id, 'scheduled' => false])
            ->assertOk()
            ->json('data.service.id');

        return Service::find($id);
    }

    public function test_quem_ja_atendeu_bem_entra_na_primeira_onda_fora_do_top(): void
    {
        $melhor = $this->tecnico(rate: 15);
        $this->tecnico(rate: 20);
        $conhecido = $this->tecnico(rate: 30);
        $this->servicoFechadoCom($conhecido, 5);

        $pedido = $this->pedir();

        $convidados = ServiceCandidate::where('service_id', $pedido->id)->where('status', CandidateStatus::NOTIFIED)->pluck('vendor_id')->all();
        $this->assertContains($melhor->id, $convidados, 'a onda normal continua a existir');
        $this->assertContains($conhecido->id, $convidados, 'o conhecido entra mesmo sendo o mais caro');
        $this->assertTrue((bool) ServiceCandidate::where('service_id', $pedido->id)->where('vendor_id', $conhecido->id)->value('is_returning_vendor'));
    }

    public function test_se_aceitar_o_cliente_ve_o_a_frente_com_o_selo(): void
    {
        $melhor = $this->tecnico(rate: 15);
        $conhecido = $this->tecnico(rate: 30);
        $this->servicoFechadoCom($conhecido, null);

        $pedido = $this->pedir();
        foreach (ServiceCandidate::where('service_id', $pedido->id)->get() as $c) {
            app(\App\Services\Matching\MatchingService::class)->accept($c);
        }

        $candidatos = $this->actingAs($this->customer, 'api')
            ->getJson("/api/v1/customer/services/matching/{$pedido->id}")
            ->assertOk()
            ->json('data.candidates');

        $this->assertSame($conhecido->id, $candidatos[0]['vendor']['id']);
        $this->assertTrue($candidatos[0]['knows_you']);
        $this->assertFalse($candidatos[1]['knows_you']);
    }

    public function test_quem_levou_nota_ma_nao_e_posto_a_frente(): void
    {
        $this->tecnico(rate: 15);
        $malAvaliado = $this->tecnico(rate: 30);
        $this->servicoFechadoCom($malAvaliado, 2);

        $pedido = $this->pedir();

        $this->assertFalse(ServiceCandidate::where('service_id', $pedido->id)->where('vendor_id', $malAvaliado->id)->exists());
    }

    public function test_um_servico_com_problema_reportado_nao_conta(): void
    {
        $this->tecnico(rate: 15);
        $comProblema = $this->tecnico(rate: 30);
        $s = $this->servicoFechadoCom($comProblema, 5);
        $s->forceFill(['problem_reported_at' => now()])->save();

        $pedido = $this->pedir();

        $this->assertFalse(ServiceCandidate::where('service_id', $pedido->id)->where('vendor_id', $comProblema->id)->exists());
    }
}
