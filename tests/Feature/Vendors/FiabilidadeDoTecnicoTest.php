<?php

namespace Tests\Feature\Vendors;

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
 * Fiabilidade do técnico: o que acontece quando ele larga um serviço que aceitou.
 *
 * Três regras, todas visíveis na app:
 *  - quem cancela é o técnico, logo o cliente nunca paga (nem a meio caminho);
 *  - ao terceiro cancelamento no mesmo mês, 48 horas sem convites;
 *  - cada falta recente faz descer uma faixa no ranking.
 * E o cliente não recomeça do zero: o pedido volta a procurar outro técnico.
 */
class FiabilidadeDoTecnicoTest extends TestCase
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

    /** Ordem já capturada: o caminho cobrado do cancelamento não precisa de rede. */
    private function jaCapturado(Service $service): void
    {
        $order = PaymentOrder::query()->create([
            'user_id' => $service->customer_id,
            'uuid' => (string) Str::uuid(),
            'amount' => 5000,
            'paid' => true,
            'status' => Status::SUCCESS,
            'type' => OperationType::DEFERRED,
            'refunded' => 0,
            'service' => 'piquet-testes',
            'service_uuid' => (string) Str::uuid(),
            'token' => (string) Str::uuid(),
            'ip' => '127.0.0.1',
        ]);
        $service->forceFill(['payment_order_id' => $order->id])->save();
    }

    private function cancelaComoTecnico(Vendor $vendor, Service $service)
    {
        return $this->actingAs($vendor->user, 'api')
            ->postJson("/api/v1/vendor/services/{$service->id}/cancel");
    }

    // ------------------------------------------------- o cliente não paga

    /**
     * O controlador do técnico usava a regra do CLIENTE: com o técnico a
     * caminho, cobrava 100% ao cliente e dava metade ao técnico.
     */
    public function test_o_tecnico_a_caminho_cancela_e_o_cliente_nao_paga_nada(): void
    {
        $vendor = $this->tecnico();
        $service = $this->servicoDe($vendor, ServiceStatus::ACCEPTED, ['on_the_way_at' => now()]);
        $this->jaCapturado($service);
        $saldoAntes = (int) $vendor->user->balance;

        $this->cancelaComoTecnico($vendor, $service)->assertOk();

        $service->refresh();
        $this->assertSame(ServiceStatus::CANCELED, $service->status);
        $this->assertNotSame('internal/services.cancel.charged', $service->status_justification);
        $this->assertSame($saldoAntes, (int) $vendor->user->fresh()->balance, 'o técnico não recebe por cancelar');
        $this->assertNotNull($service->vendor_canceled_at);
    }

    public function test_o_tecnico_no_local_cancela_e_o_cliente_nao_paga_nada(): void
    {
        $vendor = $this->tecnico();
        $service = $this->servicoDe($vendor, ServiceStatus::ARRIVED, ['on_the_way_at' => now()]);
        $this->jaCapturado($service);
        $saldoAntes = (int) $vendor->user->balance;

        $this->cancelaComoTecnico($vendor, $service)->assertOk();

        $service->refresh();
        $this->assertSame(ServiceStatus::CANCELED, $service->status);
        $this->assertNotSame('internal/services.cancel.charged', $service->status_justification);
        $this->assertSame($saldoAntes, (int) $vendor->user->fresh()->balance);
    }

    // ------------------------------------------------------------- pausa

    public function test_dois_cancelamentos_no_mes_nao_dao_pausa(): void
    {
        $vendor = $this->tecnico();
        foreach (range(1, 2) as $_) {
            $this->servicoDe($vendor, ServiceStatus::CANCELED, ['vendor_canceled_at' => now()]);
        }

        $this->assertNull($vendor->invitesPausedUntil());
    }

    public function test_ao_terceiro_cancelamento_no_mes_fica_48_horas_sem_convites(): void
    {
        $vendor = $this->tecnico();
        foreach (range(1, 3) as $_) {
            $this->servicoDe($vendor, ServiceStatus::CANCELED, ['vendor_canceled_at' => now()]);
        }

        $ate = $vendor->invitesPausedUntil();
        $this->assertNotNull($ate);
        $this->assertEqualsWithDelta(now()->addHours(Vendor::HORAS_DE_PAUSA)->timestamp, $ate->timestamp, 5);

        // E não é convidado: o ranking passa-lhe à frente.
        $outro = $this->tecnico();
        $ids = $this->rankear()->map(fn (RankedVendor $r) => $r->vendor->id)->all();
        $this->assertNotContains($vendor->id, $ids);
        $this->assertContains($outro->id, $ids);
    }

    public function test_a_pausa_acaba_ao_fim_de_48_horas(): void
    {
        $vendor = $this->tecnico();
        foreach (range(1, 3) as $_) {
            $this->servicoDe($vendor, ServiceStatus::CANCELED, ['vendor_canceled_at' => now()->subHours(49)]);
        }

        // Se as 49 horas atravessarem a mudança de mês, os cancelamentos já
        // nem contam para este mês — e a pausa também não existe. As duas
        // leituras dão o mesmo: sem pausa.
        $this->assertNull($vendor->invitesPausedUntil());
    }

    public function test_cancelamentos_do_mes_passado_nao_contam(): void
    {
        $vendor = $this->tecnico();
        $mesPassado = now('Europe/Lisbon')->startOfMonth()->subDays(2)->utc();
        foreach (range(1, 3) as $_) {
            $this->servicoDe($vendor, ServiceStatus::CANCELED, ['vendor_canceled_at' => $mesPassado]);
        }

        $this->assertSame(0, $vendor->reliabilitySummary()['cancellations_this_month']);
    }

    // ------------------------------------------------------------- faltas

    public function test_cada_falta_recente_faz_descer_uma_faixa(): void
    {
        $comFalta = $this->tecnico(rate: 15);
        $semFalta = $this->tecnico(rate: 25);
        $this->servicoDe($comFalta, ServiceStatus::CANCELED, ['vendor_no_show_at' => now()->subDays(10)]);

        // Ambos novos (faixa A pelo amortecedor). Sem a falta, o mais barato
        // ganhava; com ela, desce uma faixa e fica atrás do mais caro.
        $ordem = $this->rankear()->map(fn (RankedVendor $r) => $r->vendor->id)->values()->all();

        $this->assertSame([$semFalta->id, $comFalta->id], $ordem);
    }

    public function test_uma_falta_antiga_ja_nao_pesa(): void
    {
        $comFaltaAntiga = $this->tecnico(rate: 15);
        $this->tecnico(rate: 25);
        $this->servicoDe($comFaltaAntiga, ServiceStatus::CANCELED, [
            'vendor_no_show_at' => now()->subDays(Vendor::DIAS_DAS_FALTAS_NO_RANKING + 1),
        ]);

        $primeiro = $this->rankear()->first();
        $this->assertSame($comFaltaAntiga->id, $primeiro->vendor->id);
    }

    // ------------------------------------------------------- reabertura

    public function test_quando_o_tecnico_cancela_o_pedido_volta_a_procurar_outro(): void
    {
        $quemCancela = $this->tecnico();
        $outro = $this->tecnico();
        $service = $this->servicoDe($quemCancela, ServiceStatus::ACCEPTED, ['customer_notes' => 'Fuga na cozinha']);

        $this->cancelaComoTecnico($quemCancela, $service)->assertOk();

        $novo = Service::where('customer_id', $this->customer->id)
            ->where('status', ServiceStatus::MATCHING)
            ->first();

        $this->assertNotNull($novo, 'o cliente não pode ficar a recomeçar do zero');
        $this->assertSame($this->type->id, $novo->services_type_id);
        $this->assertSame('Fuga na cozinha', $novo->customer_notes);
        $this->assertNull($novo->vendor_id);

        // Quem cancelou fica de fora; os outros são convidados.
        $this->assertSame(CandidateStatus::EXCLUDED, ServiceCandidate::where('service_id', $novo->id)
            ->where('vendor_id', $quemCancela->id)->value('status'));
        $this->assertTrue(ServiceCandidate::where('service_id', $novo->id)
            ->where('vendor_id', $outro->id)->where('status', CandidateStatus::NOTIFIED)->exists());
    }

    public function test_sem_mais_ninguem_o_pedido_reaberto_falha_e_o_cliente_e_avisado(): void
    {
        $quemCancela = $this->tecnico();
        $service = $this->servicoDe($quemCancela, ServiceStatus::ACCEPTED);

        $this->cancelaComoTecnico($quemCancela, $service)->assertOk();

        $novo = Service::where('customer_id', $this->customer->id)->latest('id')->first();
        $this->assertNotSame($service->id, $novo->id);
        $this->assertSame(ServiceStatus::MATCHING_FAILED, $novo->status);
    }

    public function test_o_cliente_e_avisado_de_que_ja_se_procura_outro(): void
    {
        $quemCancela = $this->tecnico();
        $this->tecnico();
        $this->customer->devices()->create(['expo_token' => 'ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]', 'device_name' => 'teste']);
        $service = $this->servicoDe($quemCancela, ServiceStatus::ACCEPTED);

        $this->cancelaComoTecnico($quemCancela, $service)->assertOk();

        Notification::assertSentTo($this->customer, ServiceCanceledByVendorNotification::class,
            function (ServiceCanceledByVendorNotification $n) {
                return ($n->toArray($this->customer)['open_type'] ?? null) === 'matching';
            });
    }

    // ------------------------------------------------------------ na app

    public function test_o_perfil_diz_ao_tecnico_onde_esta_nas_regras(): void
    {
        $vendor = $this->tecnico();
        $this->servicoDe($vendor, ServiceStatus::CANCELED, ['vendor_canceled_at' => now()]);

        $this->actingAs($vendor->user, 'api')
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.reliability.cancellations_this_month', 1)
            ->assertJsonPath('data.reliability.cancellations_limit', Vendor::CANCELAMENTOS_ANTES_DA_PAUSA)
            ->assertJsonPath('data.reliability.invites_paused_until', null);
    }

    private function rankear()
    {
        return app(VendorRankingService::class)->rank(
            scope: new MatchingScope($this->type, [(int) $this->area->id], 60),
            address: new AddressCoordinatesDTO(41.1478, -8.6110),
            customer: $this->customer,
            immediate: true,
        );
    }
}
