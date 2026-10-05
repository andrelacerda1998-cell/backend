<?php

namespace Tests\Feature\Services;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\Admin\ServiceProblemOpsNotification;
use App\Notifications\Customer\ServiceAutoClosedNotification;
use App\Notifications\Vendor\ServiceProblemReportedNotification;
use App\Services\Vendor\Services\FinishService;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RwInteractive\PayshopSdk\Enums\Payment\OperationType;
use RwInteractive\PayshopSdk\Enums\Payment\Status;
use RwInteractive\PayshopSdk\Models\PaymentOrder;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Fecho automático e "Reportar um problema".
 *
 * O pagamento do técnico dependia de o cliente carregar em "Confirmar". Agora,
 * 24 horas depois de concluído e sem problema reportado, o serviço fecha
 * sozinho — pelo mesmo caminho do botão: captura, e só depois paga.
 */
class FechoAutomaticoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GenderSeeder::class);
        Notification::fake();
        Queue::fake();
        config(['broadcasting.default' => 'null']);
        // O gateway nunca é chamado a sério.
        config([
            'payshop-sdk.environment' => 'sandbox',
            'payshop-sdk.api_endpoint.sandbox' => 'http://127.0.0.1:9/',
            'payshop-sdk.connect_timeout' => 0.25,
            'payshop-sdk.timeout' => 0.25,
        ]);
    }

    private function servico(ServiceStatus $estado, array $extra = []): Service
    {
        return Service::factory()->create(array_merge([
            'customer_id' => User::factory()->create()->id,
            'vendor_id' => Vendor::factory()->create()->id,
            'services_type_id' => ServicesType::factory()->create(['time' => 60])->id,
            'status' => $estado,
            'payment_status' => PaymentStatus::PAID,
            'amount' => 5000,
            'amount_for_vendor' => 3750,
            'credit_used' => 0,
        ], $extra));
    }

    /** Ordem já capturada: o fecho segue sem rede. */
    private function jaCapturado(Service $service, Status $estado = Status::SUCCESS): void
    {
        $order = PaymentOrder::query()->create([
            'user_id' => $service->customer_id,
            'uuid' => (string) Str::uuid(),
            'amount' => 5000,
            'paid' => $estado === Status::SUCCESS,
            'status' => $estado,
            'type' => OperationType::DEFERRED,
            'refunded' => 0,
            'service' => 'piquet-testes',
            'service_uuid' => (string) Str::uuid(),
            'token' => (string) Str::uuid(),
            'ip' => '127.0.0.1',
        ]);
        $service->forceFill(['payment_order_id' => $order->id])->save();
    }

    public function test_concluir_regista_a_hora(): void
    {
        $service = $this->servico(ServiceStatus::ARRIVED);

        (new FinishService($service))->finish();

        $this->assertNotNull($service->fresh()->finished_at);
    }

    public function test_24_horas_depois_fecha_cobra_e_paga_o_tecnico(): void
    {
        $service = $this->servico(ServiceStatus::FINISHED, ['finished_at' => now()->subHours(25)]);
        $this->jaCapturado($service);
        $tecnico = $service->vendor->user;
        $saldoAntes = (int) $tecnico->balance;

        $this->artisan('services:auto-close')->assertSuccessful();

        $service->refresh();
        $this->assertSame(ServiceStatus::CLOSED, $service->status);
        $this->assertNotNull($service->auto_closed_at);
        $this->assertSame($saldoAntes + 3750, (int) $tecnico->fresh()->balance);
        Notification::assertSentTo($service->customer, ServiceAutoClosedNotification::class);
    }

    public function test_antes_das_24_horas_nao_fecha(): void
    {
        $service = $this->servico(ServiceStatus::FINISHED, ['finished_at' => now()->subHours(23)]);
        $this->jaCapturado($service);

        $this->artisan('services:auto-close')->assertSuccessful();

        $this->assertSame(ServiceStatus::FINISHED, $service->fresh()->status);
    }

    public function test_um_problema_reportado_para_o_fecho_automatico(): void
    {
        $service = $this->servico(ServiceStatus::FINISHED, [
            'finished_at' => now()->subHours(30),
        ]);
        $service->forceFill(['problem_reported_at' => now()->subHours(2), 'problem_reason' => 'poor_quality'])->save();
        $this->jaCapturado($service);

        $this->artisan('services:auto-close')->assertSuccessful();

        $this->assertSame(ServiceStatus::FINISHED, $service->fresh()->status);
        $this->assertNull($service->fresh()->autoCloseAt());
    }

    /** Sem captura não se paga ninguém: fica à espera da repetição manual. */
    public function test_se_a_captura_falhar_fica_por_cobrar_e_o_tecnico_nao_recebe(): void
    {
        $service = $this->servico(ServiceStatus::FINISHED, ['finished_at' => now()->subHours(25)]);
        // Cativada mas por capturar, e o gateway não responde.
        $this->jaCapturado($service, Status::CREATED);
        $tecnico = $service->vendor->user;
        $saldoAntes = (int) $tecnico->balance;

        $this->artisan('services:auto-close')->assertSuccessful();

        $this->assertSame(ServiceStatus::CLOSED_PENDING_PAYMENT, $service->fresh()->status);
        $this->assertSame($saldoAntes, (int) $tecnico->fresh()->balance);
        Notification::assertNotSentTo($service->customer, ServiceAutoClosedNotification::class);
    }

    public function test_o_cliente_ve_ate_quando_pode_reportar(): void
    {
        $service = $this->servico(ServiceStatus::FINISHED, ['finished_at' => now()->subHours(1)]);

        $this->actingAs($service->customer, 'api')
            ->getJson("/api/v1/customer/services/{$service->id}")
            ->assertOk()
            ->assertJsonPath('data.service.auto_close_at', $service->finished_at->copy()->addHours(24)->toIso8601String());
    }

    // ------------------------------------------------- reportar problema

    public function test_reportar_um_problema_avisa_o_backoffice_e_o_tecnico(): void
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $service = $this->servico(ServiceStatus::FINISHED, ['finished_at' => now()->subHour()]);

        $this->actingAs($service->customer, 'api')
            ->postJson("/api/v1/customer/services/{$service->id}/report", [
                'reason' => 'poor_quality',
                'message' => 'A torneira continua a pingar.',
            ])
            ->assertOk()
            ->assertJsonPath('data.service.auto_close_at', null);

        $service->refresh();
        $this->assertNotNull($service->problem_reported_at);
        $this->assertSame('customer', $service->problem_reported_by);
        $this->assertSame('poor_quality', $service->problem_reason);
        Notification::assertSentTo($admin, ServiceProblemOpsNotification::class);
        Notification::assertSentTo($service->vendor->user, ServiceProblemReportedNotification::class);
    }

    public function test_um_segundo_relato_acrescenta_e_nao_volta_a_avisar(): void
    {
        $service = $this->servico(ServiceStatus::FINISHED, ['finished_at' => now()->subHour()]);
        $cliente = $service->customer;

        $this->actingAs($cliente, 'api')->postJson("/api/v1/customer/services/{$service->id}/report", ['reason' => 'damage', 'message' => 'Riscou o chão.'])->assertOk();
        $this->actingAs($cliente, 'api')->postJson("/api/v1/customer/services/{$service->id}/report", ['reason' => 'other', 'message' => 'E deixou lixo.'])->assertOk();

        $service->refresh();
        $this->assertSame('damage', $service->problem_reason);
        $this->assertStringContainsString('Riscou o chão.', $service->problem_message);
        $this->assertStringContainsString('E deixou lixo.', $service->problem_message);
        Notification::assertSentToTimes($service->vendor->user, ServiceProblemReportedNotification::class, 1);
    }

    public function test_so_o_proprio_cliente_reporta(): void
    {
        $service = $this->servico(ServiceStatus::FINISHED);

        $this->actingAs(User::factory()->create(), 'api')
            ->postJson("/api/v1/customer/services/{$service->id}/report", ['reason' => 'other'])
            ->assertStatus(404);
    }

    public function test_um_pedido_ainda_sem_tecnico_nao_recebe_relato(): void
    {
        $service = $this->servico(ServiceStatus::MATCHING, ['vendor_id' => null]);

        $this->actingAs($service->customer, 'api')
            ->postJson("/api/v1/customer/services/{$service->id}/report", ['reason' => 'other'])
            ->assertStatus(409);
    }

    public function test_motivo_desconhecido_e_recusado(): void
    {
        $service = $this->servico(ServiceStatus::FINISHED);

        $this->actingAs($service->customer, 'api')
            ->postJson("/api/v1/customer/services/{$service->id}/report", ['reason' => 'inventado'])
            ->assertStatus(422);
    }
}
