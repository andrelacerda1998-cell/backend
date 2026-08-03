<?php

namespace Tests\Feature\Services;

use App\Enums\Services\ServiceStatus;
use App\Events\Common\Services\ServiceExtraRequestedEvent;
use App\Events\Common\Services\ServiceExtraWithdrawnEvent;
use App\Models\GeneralSettings\Gender;
use App\Models\Service;
use App\Models\ServiceExtra;
use App\Models\User;
use App\Services\Common\Services\ChargeServiceExtra;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FakeChargeServiceExtra;
use Tests\TestCase;

/**
 * Fluxo HTTP completo: o técnico pede tempo extra/peça, o cliente aprova ou
 * recusa, e o técnico pode retirar um pedido pendente. Cobre também as duas
 * proteções mais sensíveis: só é possível pedir com o serviço a decorrer
 * (ARRIVED), e nunca se resolve o mesmo pedido duas vezes.
 */
class ServiceExtrasFlowTest extends TestCase
{
    // NÃO RefreshDatabase aqui: mesma razão documentada em
    // AdminVendorsApiTest e ChargeServiceExtraTest -- a CI corre contra uma
    // BD MySQL partilhada por toda a suite, e RefreshDatabase não limpa o
    // que outras classes com DatabaseTruncation já commitaram antes desta.
    use DatabaseTruncation;

    protected array $tablesToTruncate = [
        'users', 'wallets', 'vendors', 'schedule_available',
        'services', 'services_types', 'operation_areas', 'service_extras',
        'payshop_payments_methods', 'payshop_payments_orders',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Ver AdminVendorsApiTest -- criar um Vendor dispara uma cadeia de
        // observers que tenta indexar no Meilisearch.
        config(['scout.driver' => 'null']);

        Gender::firstOrCreate(['name' => 'Masculino']);
        Notification::fake();
        $this->app->bind(ChargeServiceExtra::class, fn () => tap(new FakeChargeServiceExtra, fn ($c) => $c->outcome = 'success'));
    }

    private function arrivedService(): Service
    {
        return Service::factory()->create(['status' => ServiceStatus::ARRIVED]);
    }

    public function test_vendor_can_request_extra_time_and_amount_is_computed_from_price_rate(): void
    {
        Event::fake([ServiceExtraRequestedEvent::class]);
        $service = $this->arrivedService();
        $service->vendor->update(['price_rate' => 20]); // 20€/h

        $response = $this->actingAs($service->vendor->user, 'api')
            ->postJson("/api/v1/vendor/services/{$service->id}/extras", [
                'type' => 'time',
                'minutes' => 30,
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.extra.status', 'pending');
        $response->assertJsonPath('data.extra.amount', 1000); // 30 min a 20€/h = 10€ = 1000 cêntimos

        $this->assertDatabaseHas('service_extras', [
            'service_id' => $service->id,
            'type' => 'time',
            'minutes' => 30,
            'amount' => 1000,
            'status' => 'pending',
        ]);

        Event::assertDispatched(ServiceExtraRequestedEvent::class, fn ($e) => $e->extra['amount'] === 1000);
    }

    public function test_vendor_can_request_a_part_with_explicit_amount(): void
    {
        $service = $this->arrivedService();

        $response = $this->actingAs($service->vendor->user, 'api')
            ->postJson("/api/v1/vendor/services/{$service->id}/extras", [
                'type' => 'part',
                'description' => 'Torneira monocomando',
                'amount' => 4500,
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('service_extras', [
            'service_id' => $service->id,
            'type' => 'part',
            'description' => 'Torneira monocomando',
            'amount' => 4500,
        ]);
    }

    public function test_vendor_cannot_request_extra_before_the_service_is_in_execution(): void
    {
        $service = Service::factory()->create(['status' => ServiceStatus::ACCEPTED]);

        $response = $this->actingAs($service->vendor->user, 'api')
            ->postJson("/api/v1/vendor/services/{$service->id}/extras", [
                'type' => 'time',
                'minutes' => 30,
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('service_extras', 0);
    }

    public function test_a_different_vendor_cannot_request_extras_for_someone_elses_service(): void
    {
        $service = $this->arrivedService();
        $otherVendorUser = \App\Models\Vendor::factory()->create()->user;

        $response = $this->actingAs($otherVendorUser, 'api')
            ->postJson("/api/v1/vendor/services/{$service->id}/extras", [
                'type' => 'time',
                'minutes' => 30,
            ]);

        $response->assertStatus(404);
    }

    public function test_vendor_can_withdraw_a_pending_extra_and_it_broadcasts(): void
    {
        Event::fake([ServiceExtraWithdrawnEvent::class]);
        $service = $this->arrivedService();
        $extra = ServiceExtra::factory()->create(['service_id' => $service->id]);

        $response = $this->actingAs($service->vendor->user, 'api')
            ->deleteJson("/api/v1/vendor/services/{$service->id}/extras/{$extra->id}");

        $response->assertOk();
        $response->assertJsonPath('data.extra.status', 'withdrawn');
        $this->assertDatabaseHas('service_extras', ['id' => $extra->id, 'status' => 'withdrawn']);
        Event::assertDispatched(ServiceExtraWithdrawnEvent::class, fn ($e) => $e->extra['id'] === $extra->id);
    }

    public function test_vendor_cannot_withdraw_an_already_resolved_extra(): void
    {
        $service = $this->arrivedService();
        $extra = ServiceExtra::factory()->approved()->create(['service_id' => $service->id]);

        $response = $this->actingAs($service->vendor->user, 'api')
            ->deleteJson("/api/v1/vendor/services/{$service->id}/extras/{$extra->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('service_extras', ['id' => $extra->id, 'status' => 'approved']);
    }

    public function test_customer_can_approve_a_pending_extra_and_it_gets_charged(): void
    {
        $service = $this->arrivedService();
        $service->customer->paymentMethods()->create(['type' => 'card', 'uuid' => 'card-uuid']);
        $extra = ServiceExtra::factory()->create(['service_id' => $service->id, 'amount' => 1500]);

        $response = $this->actingAs($service->customer, 'api')
            ->postJson("/api/v1/customer/services/{$service->id}/extras/{$extra->id}/approve");

        $response->assertOk();
        $response->assertJsonPath('data.extra.status', 'approved');
        $response->assertJsonPath('data.extra.payment_status', 'paid');
    }

    public function test_customer_can_reject_a_pending_extra_with_a_reason(): void
    {
        $service = $this->arrivedService();
        $extra = ServiceExtra::factory()->create(['service_id' => $service->id]);

        $response = $this->actingAs($service->customer, 'api')
            ->postJson("/api/v1/customer/services/{$service->id}/extras/{$extra->id}/reject", [
                'reason' => 'Prefiro não avançar sem estar presente',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.extra.status', 'rejected');
        $this->assertDatabaseHas('service_extras', [
            'id' => $extra->id,
            'status' => 'rejected',
            'rejection_reason' => 'Prefiro não avançar sem estar presente',
        ]);
    }

    public function test_a_pending_extra_can_never_be_resolved_twice(): void
    {
        $service = $this->arrivedService();
        $service->customer->paymentMethods()->create(['type' => 'card', 'uuid' => 'card-uuid']);
        $extra = ServiceExtra::factory()->create(['service_id' => $service->id]);

        $first = $this->actingAs($service->customer, 'api')
            ->postJson("/api/v1/customer/services/{$service->id}/extras/{$extra->id}/approve");
        $first->assertOk();

        // Double-tap / retry de rede sobre o MESMO pedido já aprovado.
        $second = $this->actingAs($service->customer, 'api')
            ->postJson("/api/v1/customer/services/{$service->id}/extras/{$extra->id}/reject");

        $second->assertStatus(422);
        $this->assertDatabaseHas('service_extras', ['id' => $extra->id, 'status' => 'approved']);
    }

    public function test_a_different_customer_cannot_respond_to_someone_elses_extra(): void
    {
        $service = $this->arrivedService();
        $extra = ServiceExtra::factory()->create(['service_id' => $service->id]);
        $otherCustomer = User::factory()->create();

        $response = $this->actingAs($otherCustomer, 'api')
            ->postJson("/api/v1/customer/services/{$service->id}/extras/{$extra->id}/approve");

        $response->assertStatus(404);
        $this->assertDatabaseHas('service_extras', ['id' => $extra->id, 'status' => 'pending']);
    }

    public function test_customer_can_retry_charge_after_adding_a_payment_method(): void
    {
        $service = $this->arrivedService();
        // Sem cartão gravado — a aprovação fica requires_action, sem ordem.
        $extra = ServiceExtra::factory()->create(['service_id' => $service->id]);

        $this->actingAs($service->customer, 'api')
            ->postJson("/api/v1/customer/services/{$service->id}/extras/{$extra->id}/approve")
            ->assertOk();

        $extra->refresh();
        $this->assertSame('requires_action', $extra->payment_status);
        $this->assertSame('no_stored_payment_method', $extra->payment_error);

        $service->customer->paymentMethods()->create(['type' => 'card', 'uuid' => 'card-uuid']);

        $retry = $this->actingAs($service->customer, 'api')
            ->postJson("/api/v1/customer/services/{$service->id}/extras/{$extra->id}/retry-charge");

        $retry->assertOk();
        $retry->assertJsonPath('data.extra.payment_status', 'paid');
    }

    public function test_retry_charge_is_refused_while_a_3ds_order_is_still_pending(): void
    {
        $service = $this->arrivedService();
        $service->customer->paymentMethods()->create(['type' => 'card', 'uuid' => 'card-uuid']);
        $extra = ServiceExtra::factory()->create(['service_id' => $service->id]);

        $this->app->bind(ChargeServiceExtra::class, fn () => tap(new FakeChargeServiceExtra, fn ($c) => $c->outcome = '3ds'));
        $this->actingAs($service->customer, 'api')
            ->postJson("/api/v1/customer/services/{$service->id}/extras/{$extra->id}/approve")
            ->assertOk();

        $this->assertSame('requires_action', $extra->refresh()->payment_status);
        $this->assertNotNull($extra->payment_order_id);

        $retry = $this->actingAs($service->customer, 'api')
            ->postJson("/api/v1/customer/services/{$service->id}/extras/{$extra->id}/retry-charge");

        // Já existe ordem viva à espera do 3DS — nunca se tenta criar uma segunda.
        $retry->assertStatus(422);
    }

    public function test_index_only_lists_pending_by_default_and_all_with_query_param(): void
    {
        $service = $this->arrivedService();
        ServiceExtra::factory()->create(['service_id' => $service->id, 'status' => 'pending']);
        ServiceExtra::factory()->approved()->create(['service_id' => $service->id]);

        $pendingOnly = $this->actingAs($service->customer, 'api')
            ->getJson("/api/v1/customer/services/{$service->id}/extras");
        $pendingOnly->assertOk();
        $this->assertCount(1, $pendingOnly->json('data.extras'));

        $all = $this->actingAs($service->customer, 'api')
            ->getJson("/api/v1/customer/services/{$service->id}/extras?all=1");
        $all->assertOk();
        $this->assertCount(2, $all->json('data.extras'));
        $all->assertJsonPath('data.approved_amount', fn ($v) => $v > 0);
    }
}
