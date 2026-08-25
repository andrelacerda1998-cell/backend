<?php

namespace Tests\Feature\Services;

use App\Enums\Services\AddressType;
use App\Enums\Services\CandidateStatus;
use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Enums\Vendors\StatusVendor;
use App\Models\Address;
use App\Models\GeneralSettings\OperationArea;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Service;
use App\Models\ServiceCandidate;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Vendor\Location;
use App\Services\Matching\MatchingService;
use App\Settings\MatchingSettings;
use App\Events\Matching\MatchingCandidateAcceptedEvent;
use App\Events\Matching\MatchingCandidateLostEvent;
use App\Events\Matching\MatchingInvitationEvent;
use App\Events\Matching\MatchingRequestClosedEvent;
use Illuminate\Support\Facades\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fluxo do cliente na seleção de profissional — ver docs/matching.md.
 *
 * Os serviços são de teste (`is_test`), por isso a cobrança remota não corre.
 * O que se fixa aqui é a ORDEM: candidatos, escolha, e só depois dinheiro.
 */
class MatchingFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private ServicesType $type;

    protected function setUp(): void
    {
        parent::setUp();

        MatchingSettings::fake([
            'shortlist_size' => 3,
            'wave_size' => 6,
            'wave_interval_seconds' => 45,
            'max_waves' => 3,
            'vendor_response_seconds_immediate' => 60,
            'vendor_response_seconds_scheduled' => 1800,
            'customer_choice_seconds' => 120,
            'checkout_seconds' => 300,
            'rating_bands' => [4.5, 4.0, 3.0],
            'new_vendor_min_ratings' => 5,
            'require_recent_activity_minutes' => 15,
        ]);


        // Todos implementam ShouldBroadcast: sem isto o teste tenta um broadcast
        // real via Pusher e rebenta com "Failed to connect to 0.0.0.0:8080".
        Event::fake([
            MatchingInvitationEvent::class,
            MatchingCandidateAcceptedEvent::class,
            MatchingRequestClosedEvent::class,
            MatchingCandidateLostEvent::class,
            // O checkout de um agendado materializa a agenda pelo caminho que
            // já existia, e esse difunde os seus próprios eventos.
            \App\Events\Customer\Schedule\AcceptScheduleEvent::class,
            \App\Events\Vendor\Schedule\CreateScheduleEvent::class,
            \App\Events\Vendor\Schedule\ServiceScheduledEvent::class,
            // Com a auto-aceitação DESLIGADA, o notifyVendor segue o ramo normal
            // e difunde estes em vez do ServiceScheduledEvent.
            \App\Events\Vendor\Services\CreateServiceEvent::class,
            \App\Events\Common\Services\ServiceAcceptedEvent::class,
        ]);

        // As notificações também saem por este caminho e não precisam de rede.
        \Illuminate\Support\Facades\Notification::fake();

        // O checkout aciona a materialização da agenda, que difunde vários
        // eventos do fluxo antigo. Em vez de os enumerar um a um — lista que se
        // desatualiza a cada alteração noutro sítio — desliga-se a difusão. Os
        // eventos de matching continuam com Event::fake, que é o que estes
        // testes verificam.
        config(['broadcasting.default' => 'null']);

        $area = OperationArea::factory()->create();
        $this->type = ServicesType::factory()->create(['operation_area_id' => $area->id, 'time' => 60]);

        $this->customer = User::factory()->create(['is_test' => true]);
        $this->makeAddress($this->customer, AddressType::HOUSE_ADDRESS, main: true);

        foreach ([20, 22, 24] as $i => $rate) {
            $this->makeVendor("Profissional {$i}", $rate, $area, $this->type);
        }
    }

    /**
     * O VendorObserver liga a auto-aceitação por omissão em todos os blocos.
     * Estes testes medem o percurso MANUAL — o profissional a responder — por
     * isso desligam-na explicitamente em vez de dependerem do que o observer
     * calhar a fazer.
     */
    private function withoutAutoAccept(Vendor $vendor): Vendor
    {
        $vendor->scheduleAvailable()->update(['auto_accept' => false]);

        return $vendor->fresh();
    }

    private function makeAddress(User $user, $type, bool $main = false): Address
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

    private function makeVendor(string $name, int $ratePerHour, OperationArea $area, ServicesType $type): Vendor
    {
        // Profissional COMPLETAMENTE onboarded de propósito: a shortlist de um
        // pedido imediato passa pelo `can_accept_service`, que exige telefone e
        // email verificados, documentos, IBAN, workspace de faturação e
        // credenciais AT válidas. É esse portão que faz "está livre" significar
        // alguma coisa — sem ele, mostrar-se-iam ao cliente pessoas que nunca
        // poderiam aceitar.
        $user = User::factory()->create([
            'name' => $name,
            'is_test' => true,
            'email_verified_at' => now(),
            'phone_number_verified_at' => now(),
        ]);

        $vendor = Vendor::factory()->create([
            'user_id' => $user->id,
            'price_rate' => $ratePerHour,
            'status' => StatusVendor::ONLINE,
            'iban' => 'PT50000000000000000000000',
            'invoice_workspace' => 'ws-'.$user->id,
            'at_user' => '999999999/1',
            'at_valid' => true,
            'at_validated_at' => now(),
        ]);

        $vendor->operationAreas()->attach($area->id);
        $vendor->servicesTypes()->attach($type->id);
        $vendor->currentLocation()->save(new Location(['latitude' => 41.15, 'longitude' => -8.61]));
        $this->makeAddress($user, AddressType::SCHEDULE_ADDRESS);

        return $this->withoutAutoAccept($vendor);
    }

    private function start(bool $scheduled = false)
    {
        return $this->actingAs($this->customer, 'api')
            ->postJson('/api/v1/customer/services/matching', [
                'service_type' => $this->type->id,
                'scheduled' => $scheduled,
                'schedule' => $scheduled ? [
                    'scheduled_day' => now()->addDay()->toDateString(),
                    'scheduled_time_start' => now()->addDay()->setTime(10, 0)->toDateTimeString(),
                ] : null,
            ]);
    }

    public function test_immediate_shows_candidates_without_notifying_anyone(): void
    {
        // O ganho do fluxo imediato: o cliente vê opções de imediato e ninguém
        // aceita em vão, porque ninguém foi chamado ainda.
        $response = $this->start()->assertOk();

        $this->assertSame(ServiceStatus::MATCHING->value, $response->json('data.service.status'));
        $this->assertCount(3, $response->json('data.candidates'));
        $this->assertSame(0, ServiceCandidate::where('status', CandidateStatus::NOTIFIED)->count());
    }

    public function test_opening_a_request_charges_nothing(): void
    {
        $response = $this->start()->assertOk();

        $service = Service::find($response->json('data.service.id'));

        $this->assertNull($service->amount, 'não há preço no serviço antes de haver escolha');
        $this->assertSame(PaymentStatus::PENDING, $service->payment_status);
        $this->assertNull($service->vendor_id);
    }

    public function test_candidates_are_ordered_and_priced_individually(): void
    {
        $candidates = $this->start()->assertOk()->json('data.candidates');

        $this->assertSame([1, 2, 3], array_column($candidates, 'rank'));

        $amounts = array_column($candidates, 'amount');
        $this->assertSame($amounts, array_values(array_unique($amounts)), 'cada profissional tem o seu preço');
        $this->assertSame($amounts, collect($amounts)->sort()->values()->all(), 'sem avaliações, ordena por preço');
    }

    public function test_choosing_in_the_immediate_flow_only_calls_the_chosen_one(): void
    {
        $data = $this->start()->assertOk()->json('data');
        $service = Service::find($data['service']['id']);
        $chosen = $data['candidates'][0]['id'];

        $this->actingAs($this->customer, 'api')
            ->postJson("/api/v1/customer/services/matching/{$service->id}/select/{$chosen}")
            ->assertOk()
            ->assertJsonPath('data.awaiting_vendor', true);

        $this->assertSame(CandidateStatus::NOTIFIED, ServiceCandidate::find($chosen)->status);
        $this->assertSame(
            0,
            ServiceCandidate::where('service_id', $service->id)
                ->where('status', CandidateStatus::NOTIFIED)->count() - 1,
            'só o escolhido foi chamado'
        );
    }

    public function test_checkout_is_refused_before_a_vendor_accepts(): void
    {
        $data = $this->start()->assertOk()->json('data');
        $service = Service::find($data['service']['id']);

        // A inversão que este fluxo trouxe: não se cobra sem aceitação.
        $this->actingAs($this->customer, 'api')
            ->postJson("/api/v1/customer/services/matching/{$service->id}/checkout")
            ->assertStatus(409);

        $this->assertNull($service->refresh()->amount);
    }

    public function test_full_flow_charges_the_price_the_customer_saw(): void
    {
        $data = $this->start(scheduled: true)->assertOk()->json('data');
        $service = Service::find($data['service']['id']);

        $matching = app(MatchingService::class);
        $candidate = $service->candidates()->orderBy('rank')->first();
        $quoted = (int) $candidate->quoted_amount;

        $matching->accept($candidate);

        $this->actingAs($this->customer, 'api')
            ->postJson("/api/v1/customer/services/matching/{$service->id}/select/{$candidate->id}")
            ->assertOk();

        $this->assertSame(ServiceStatus::AWAITING_PAYMENT, $service->refresh()->status);

        $this->actingAs($this->customer, 'api')
            ->postJson("/api/v1/customer/services/matching/{$service->id}/checkout")
            ->assertOk();

        $service->refresh();

        // O preço mostrado é o preço cobrado — a comissão horária muda com a
        // hora do dia, por isso recalcular no checkout daria outro número.
        $this->assertSame($quoted, $service->amount);
        $this->assertSame(PaymentStatus::PAID, $service->payment_status);
        $this->assertSame($candidate->vendor_id, $service->vendor_id);
    }

    public function test_another_customer_cannot_see_or_choose(): void
    {
        $data = $this->start()->assertOk()->json('data');
        $service = Service::find($data['service']['id']);
        $intruder = User::factory()->create(['is_test' => true]);

        $this->actingAs($intruder, 'api')
            ->getJson("/api/v1/customer/services/matching/{$service->id}")
            ->assertStatus(404);

        $this->actingAs($intruder, 'api')
            ->postJson("/api/v1/customer/services/matching/{$service->id}/select/{$data['candidates'][0]['id']}")
            ->assertStatus(404);
    }

    public function test_a_second_checkout_is_refused(): void
    {
        $data = $this->start(scheduled: true)->assertOk()->json('data');
        $service = Service::find($data['service']['id']);
        $candidate = $service->candidates()->orderBy('rank')->first();

        app(MatchingService::class)->accept($candidate);
        $this->actingAs($this->customer, 'api')
            ->postJson("/api/v1/customer/services/matching/{$service->id}/select/{$candidate->id}")->assertOk();
        $this->actingAs($this->customer, 'api')
            ->postJson("/api/v1/customer/services/matching/{$service->id}/checkout")->assertOk();

        // Sem este travão, o cliente pagava duas vezes o mesmo serviço.
        $this->actingAs($this->customer, 'api')
            ->postJson("/api/v1/customer/services/matching/{$service->id}/checkout")
            ->assertStatus(409);
    }

    public function test_no_eligible_vendors_fails_fast(): void
    {
        Vendor::query()->update(['status' => StatusVendor::OFFLINE]);

        $response = $this->start()->assertOk();

        // Melhor falhar já do que deixar o cliente num ecrã de espera que
        // nunca resolve. A regra do negócio é "tenta outra vez".
        $this->assertSame(ServiceStatus::MATCHING_FAILED->value, $response->json('data.service.status'));
        $this->assertCount(0, $response->json('data.candidates'));
    }
}
