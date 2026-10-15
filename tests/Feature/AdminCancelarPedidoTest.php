<?php

namespace Tests\Feature;

use App\Enums\Services\CandidateStatus;
use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Service;
use App\Models\ServiceCandidate;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\Customer\PedidoCanceladoPelaPiquetNotification;
use App\Notifications\Vendor\MatchingOutcomeNotification;
use App\Notifications\Vendor\ServicoCanceladoPelaPiquetNotification;
use App\Services\Matching\MatchingService;
use App\Services\Operacoes\CancelarPeloBackoffice;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RwInteractive\PayshopSdk\Enums\Payment\OperationType;
use RwInteractive\PayshopSdk\Enums\Payment\Status;
use RwInteractive\PayshopSdk\Models\PaymentOrder;
use Tests\TestCase;

/**
 * A equipa cancela um pedido pelo backoffice (POST /v1/admin/services/{id}/cancelar).
 *
 * A regra que importa: o cliente nunca paga e o técnico nunca é penalizado —
 * ao contrário de quando é o cliente a desistir (paga com o técnico a
 * caminho) ou o técnico a largar (perde fiabilidade). E só antes de o
 * trabalho começar.
 */
class AdminCancelarPedidoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GenderSeeder::class);
        config(['scout.driver' => 'null']);
        config(['services.admin_api.token' => 'a-valid-token']);
        Notification::fake();
        Queue::fake();

        // Nunca bater no Paylands a sério: uma porta sem ninguém, que também
        // exercita o "o gateway falhou" (o cancelamento segue na mesma).
        config([
            'payshop-sdk.environment' => 'sandbox',
            'payshop-sdk.api_endpoint.sandbox' => 'http://127.0.0.1:9/',
            'payshop-sdk.connect_timeout' => 0.25,
            'payshop-sdk.timeout' => 0.25,
        ]);
    }

    private function api(): static
    {
        return $this->withHeaders(['Authorization' => 'Bearer a-valid-token']);
    }

    private function cancelar(Service $service)
    {
        return $this->api()->postJson("/api/v1/admin/services/{$service->id}/cancelar");
    }

    private function servico(ServiceStatus $estado, array $extra = []): Service
    {
        $cliente = User::factory()->create();
        $cliente->devices()->create(['expo_token' => 'ExponentPushToken[cliente-'.Str::random(8).']', 'device_name' => 'teste']);
        $tecnico = Vendor::factory()->create();
        $tecnico->user->devices()->create(['expo_token' => 'ExponentPushToken[tecnico-'.Str::random(8).']', 'device_name' => 'teste']);

        return Service::factory()->create(array_merge([
            'customer_id' => $cliente->id,
            'vendor_id' => $tecnico->id,
            'services_type_id' => ServicesType::factory()->create(['time' => 60])->id,
            'status' => $estado,
            'payment_status' => PaymentStatus::PAID,
            'amount' => 5000,
            'amount_for_vendor' => 3750,
            'credit_used' => 0,
        ], $extra));
    }

    private function ordem(Service $service, Status $estado): PaymentOrder
    {
        $ordem = PaymentOrder::query()->create([
            'user_id' => $service->customer_id,
            'uuid' => (string) Str::uuid(),
            'amount' => 5000,
            'paid' => true,
            'status' => $estado,
            'type' => OperationType::DEFERRED,
            'refunded' => 0,
            'service' => 'piquet-testes',
            'service_uuid' => (string) Str::uuid(),
            'token' => (string) Str::uuid(),
            'ip' => '127.0.0.1',
        ]);
        $service->forceFill(['payment_order_id' => $ordem->id])->save();

        return $ordem;
    }

    private function marcacao(Service $service): void
    {
        DB::table('schedule')->insert([
            'vendor_id' => $service->vendor_id,
            'customer_id' => $service->customer_id,
            'service_id' => $service->id,
            'scheduled_day' => now('Europe/Lisbon')->addDay()->toDateString(),
            'scheduled_time_start' => '10:00:00',
            'scheduled_time_end' => '11:00:00',
            'is_pending' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assertCanceladoPelaPiquet(Service $service): void
    {
        $service->refresh();
        $this->assertSame(ServiceStatus::CANCELED, $service->status);
        $this->assertSame(CancelarPeloBackoffice::JUSTIFICACAO, $service->status_justification);
        // Nunca conta contra o técnico.
        $this->assertNull($service->vendor_canceled_at);
    }

    public function test_sem_token_nao_se_cancela(): void
    {
        $s = $this->servico(ServiceStatus::SCHEDULED);
        $this->postJson("/api/v1/admin/services/{$s->id}/cancelar")->assertStatus(401);
        $this->assertSame(ServiceStatus::SCHEDULED, $s->refresh()->status);
    }

    public function test_um_agendado_pago_e_cancelado_devolvido_e_os_dois_sao_avisados(): void
    {
        $s = $this->servico(ServiceStatus::SCHEDULED);
        $this->ordem($s, Status::SUCCESS);
        $this->marcacao($s);

        $this->cancelar($s)->assertOk()
            ->assertJsonPath('data.status', ServiceStatus::CANCELED->value)
            ->assertJsonPath('data.payment_status', PaymentStatus::REFUNDED->value);

        $this->assertCanceladoPelaPiquet($s);
        // A marcação sai da agenda do técnico.
        $this->assertSoftDeleted('schedule', ['service_id' => $s->id]);
        Notification::assertSentTo($s->customerUser, PedidoCanceladoPelaPiquetNotification::class);
        Notification::assertSentTo($s->vendor->user, ServicoCanceladoPelaPiquetNotification::class);
    }

    public function test_com_o_tecnico_a_caminho_o_cliente_nao_paga_e_o_tecnico_nao_recebe(): void
    {
        $s = $this->servico(ServiceStatus::ACCEPTED, ['on_the_way_at' => now()->subMinutes(10)]);
        $this->ordem($s, Status::SUCCESS);

        $this->cancelar($s)->assertOk();

        $this->assertCanceladoPelaPiquet($s);
        // Pela regra do cliente, aqui cobrava-se 100% e o técnico ficava com metade.
        $this->assertSame(PaymentStatus::REFUNDED, $s->payment_status);
        $this->assertSame(0, (int) $s->vendor->user->balanceInt);
    }

    public function test_um_pendente_pago_e_cancelado(): void
    {
        $s = $this->servico(ServiceStatus::PENDING);

        $this->cancelar($s)->assertOk();

        $this->assertCanceladoPelaPiquet($s);
        Notification::assertSentTo($s->vendor->user, ServicoCanceladoPelaPiquetNotification::class);
    }

    public function test_um_personalizado_em_analise_e_cancelado_sem_tecnico_a_avisar(): void
    {
        $s = $this->servico(ServiceStatus::PENDING_REVIEW, [
            'vendor_id' => null, 'services_type_id' => null, 'is_custom' => true,
            'payment_status' => PaymentStatus::PENDING, 'amount' => null, 'amount_for_vendor' => null,
        ]);

        $this->cancelar($s)->assertOk();

        $this->assertCanceladoPelaPiquet($s);
        Notification::assertSentTo($s->customerUser, PedidoCanceladoPelaPiquetNotification::class);
        Notification::assertNotSentTo(Vendor::all()->map->user, ServicoCanceladoPelaPiquetNotification::class);
    }

    public function test_em_selecao_fecha_os_convites(): void
    {
        $s = $this->servico(ServiceStatus::MATCHING, ['vendor_id' => null, 'payment_status' => PaymentStatus::PENDING]);
        $convidado = Vendor::factory()->create();
        ServiceCandidate::create([
            'service_id' => $s->id, 'vendor_id' => $convidado->id, 'rank' => 1, 'wave' => 1,
            'status' => CandidateStatus::NOTIFIED, 'quoted_amount' => 5000, 'quoted_amount_for_vendor' => 3750,
            'quoted_distance' => 3, 'notified_at' => now()->subMinute(), 'expires_at' => now()->addMinute(),
        ]);

        $this->cancelar($s)->assertOk();

        $this->assertCanceladoPelaPiquet($s);
        $this->assertSame(CandidateStatus::LOST, $s->candidates()->first()->status);
    }

    public function test_no_3ds_por_pagar_e_cancelado_sem_avisar_o_tecnico(): void
    {
        $s = $this->servico(ServiceStatus::PENDING_3DS, ['payment_status' => PaymentStatus::PENDING]);
        $this->ordem($s, Status::PENDING_3DS_RESPONSE);

        $this->cancelar($s)->assertOk()->assertJsonPath('data.payment_status', PaymentStatus::CANCELED->value);

        $this->assertCanceladoPelaPiquet($s);
        Notification::assertNotSentTo($s->vendor->user, ServicoCanceladoPelaPiquetNotification::class);
    }

    public function test_se_o_cliente_acabou_de_pagar_nao_se_cancela(): void
    {
        $s = $this->servico(ServiceStatus::PENDING_3DS, ['payment_status' => PaymentStatus::PENDING]);
        $this->ordem($s, Status::PENDING_CONFIRMATION);

        $this->cancelar($s)->assertStatus(409)
            ->assertJsonPath('message', 'O cliente acabou de pagar: o pedido seguiu. Atualiza e volta a ver.');

        $this->assertSame(ServiceStatus::PENDING_3DS, $s->refresh()->status);
        Notification::assertNothingSent();
    }

    /**
     * À espera de pagamento com um MB Way já enviado: o pedido 299 (15/10/2026).
     * O técnico foi escolhido, o cliente autorizou no telemóvel, e a
     * confirmação falhou do nosso lado.
     */
    private function aEsperaDoMbWay(Status $estadoDaOrdem): array
    {
        $s = $this->servico(ServiceStatus::AWAITING_PAYMENT, ['payment_status' => PaymentStatus::PENDING]);
        $escolhido = ServiceCandidate::query()->create([
            'service_id' => $s->id, 'vendor_id' => $s->vendor_id, 'rank' => 1, 'wave' => 1,
            'status' => CandidateStatus::SELECTED, 'quoted_amount' => 5000, 'quoted_amount_for_vendor' => 3750,
            'quoted_distance' => 2.4, 'notified_at' => now()->subHours(5), 'responded_at' => now()->subHours(5),
        ]);
        $this->ordem($s, $estadoDaOrdem);

        return [$s->refresh(), $escolhido];
    }

    public function test_com_o_mb_way_autorizado_e_preso_cancela_e_o_cliente_nao_paga(): void
    {
        [$s, $escolhido] = $this->aEsperaDoMbWay(Status::PENDING_CONFIRMATION);
        $tecnico = $s->vendor->user;
        $cliente = $s->customerUser;

        // Era aqui que respondia "o cliente está a meio do pagamento", sempre.
        $this->cancelar($s)->assertOk()
            ->assertJsonPath('data.status', ServiceStatus::CANCELED->value)
            ->assertJsonPath('data.payment_status', PaymentStatus::CANCELED->value);

        $this->assertCanceladoPelaPiquet($s);
        $this->assertNull($s->vendor_id);
        $this->assertSame(CandidateStatus::LOST, $escolhido->refresh()->status);

        Notification::assertSentTo($cliente, PedidoCanceladoPelaPiquetNotification::class);
        // O técnico nunca soube que ganhou: não leva o push de "cancelado pela
        // Piquet", só o desfecho no histórico, como quando um cliente desiste.
        Notification::assertNotSentTo($tecnico, ServicoCanceladoPelaPiquetNotification::class);
        Notification::assertSentTo(
            $tecnico,
            MatchingOutcomeNotification::class,
            fn (MatchingOutcomeNotification $n) => $n->via($tecnico) === ['database'],
        );
    }

    public function test_com_o_mb_way_por_responder_no_telemovel_nao_se_cancela(): void
    {
        [$s, $escolhido] = $this->aEsperaDoMbWay(Status::PENDING_PAYMENT);

        $this->cancelar($s)->assertStatus(409)
            ->assertJsonPath('message', 'O MB Way ainda está por responder no telemóvel do cliente. Espera uns minutos e volta a tentar.');

        $this->assertSame(ServiceStatus::AWAITING_PAYMENT, $s->refresh()->status);
        $this->assertSame(CandidateStatus::SELECTED, $escolhido->refresh()->status);
    }

    public function test_com_o_mb_way_recusado_ou_expirado_cancela(): void
    {
        foreach ([Status::REFUSED, Status::EXPIRED, Status::REFUNDED] as $estado) {
            [$s] = $this->aEsperaDoMbWay($estado);

            $this->cancelar($s)->assertOk()
                ->assertJsonPath('data.payment_status', PaymentStatus::CANCELED->value);
            $this->assertCanceladoPelaPiquet($s);
        }
    }

    /** Cobrado de facto (não devia acontecer sem confirmação, mas se acontecer): devolve-se. */
    public function test_com_o_mb_way_ja_cobrado_cancela_e_devolve(): void
    {
        [$s] = $this->aEsperaDoMbWay(Status::SUCCESS);

        $this->cancelar($s)->assertOk()
            ->assertJsonPath('data.payment_status', PaymentStatus::REFUNDED->value);
        $this->assertCanceladoPelaPiquet($s);
    }

    /** A regra do lado do cliente não muda: com um MB Way enviado, ele não cancela pela app. */
    public function test_o_cliente_continua_sem_poder_cancelar_com_o_mb_way_enviado(): void
    {
        [$s] = $this->aEsperaDoMbWay(Status::PENDING_CONFIRMATION);

        $this->assertFalse(app(MatchingService::class)->cancelByCustomer($s));
        $this->assertSame(ServiceStatus::AWAITING_PAYMENT, $s->refresh()->status);
    }

    public function test_depois_de_o_trabalho_comecar_nao_se_cancela_e_diz_porque(): void
    {
        $casos = [
            [ServiceStatus::ARRIVED, 'O técnico já está no local'],
            [ServiceStatus::FINISHED, 'O técnico já terminou'],
            [ServiceStatus::CLOSED_PENDING_PAYMENT, 'Desistir e devolver'],
            [ServiceStatus::CLOSED, 'Reembolsar'],
            [ServiceStatus::CANCELED, 'já acabou'],
        ];

        foreach ($casos as [$estado, $frase]) {
            $s = $this->servico($estado);

            $r = $this->cancelar($s)->assertStatus(409);

            $this->assertStringContainsString($frase, (string) $r->json('message'), $estado->value);
            $this->assertSame($estado, $s->refresh()->status, $estado->value);
        }

        Notification::assertNothingSent();
    }

    public function test_um_pedido_de_um_tipo_que_saiu_do_catalogo_continua_com_nome(): void
    {
        $s = $this->servico(ServiceStatus::SCHEDULED);
        $nome = $s->serviceType->name;
        $this->assertNotEmpty($nome);
        $s->serviceType->delete();

        $this->api()->getJson("/api/v1/admin/services/{$s->id}")->assertOk()
            ->assertJsonPath('data.service_name', $nome)
            ->assertJsonPath('data.service_type_removed', true);

        $item = collect($this->api()->getJson('/api/v1/admin/services?per_page=100')->assertOk()->json('data.items'))
            ->first(fn (array $i) => (int) $i['id'] === $s->id);
        $this->assertSame($nome, $item['service_name']);
        $this->assertTrue($item['service_type_removed']);
    }
}
