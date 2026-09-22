<?php

namespace Tests\Feature\Services;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Jobs\Services\CreateCancellationInvoiceJob;
use App\Jobs\Services\CreateVendorCancellationInvoiceJob;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Common\Services\CancellationPolicy;
use App\Services\Common\Services\CancelService;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RwInteractive\PayshopSdk\Enums\Payment\OperationType;
use RwInteractive\PayshopSdk\Enums\Payment\Status;
use RwInteractive\PayshopSdk\Models\PaymentOrder;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Cancelar um serviço — quem cancela, quando, e quem fica a pagar.
 *
 * Esta classe não tinha um único teste, em camada nenhuma. Tem nove entradas
 * diferentes, cada uma com a sua regra de dinheiro: umas reembolsam, uma cobra
 * 100% e reparte 50/50 com o técnico, outra penaliza por escalão, outra reverte
 * depósitos já feitos. Trocar uma pela outra não dá erro nenhum — dá o cliente
 * cobrado por um serviço que ninguém fez, ou o técnico a receber por um que
 * não chegou a acontecer.
 *
 * A regra pura (SE cobra e COMO reparte) já tem testes em CancellationPolicy.
 * O que falta, e é o que está aqui, é a ORQUESTRAÇÃO: que estado fica, que
 * justificação se escreve, que dinheiro se move, e o que acontece quando o
 * gateway não coopera.
 */
class CancelServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(GenderSeeder::class);
        Notification::fake();
        Queue::fake();

        // O SDK aponta por omissão para o sandbox REAL do Paylands. Um teste
        // nunca pode lá bater: manda-se para uma porta onde não há ninguém, o
        // que também serve para exercitar o caminho de "o gateway falhou".
        config([
            'payshop-sdk.environment' => 'sandbox',
            'payshop-sdk.api_endpoint.sandbox' => 'http://127.0.0.1:9/',
            'payshop-sdk.connect_timeout' => 0.25,
            'payshop-sdk.timeout' => 0.25,
        ]);
    }

    private function servico(ServiceStatus $estado, array $extra = []): Service
    {
        $customer = User::factory()->create();
        $vendor = Vendor::factory()->create();
        $serviceType = ServicesType::factory()->create(['time' => 60]);

        return Service::factory()->create(array_merge([
            'customer_id' => $customer->id,
            'vendor_id' => $vendor->id,
            'services_type_id' => $serviceType->id,
            'status' => $estado,
            'payment_status' => PaymentStatus::PAID,
            'amount' => 5000,
            'amount_for_vendor' => 3750,
            'credit_used' => 0,
        ], $extra));
    }

    /**
     * Ordem de pagamento JÁ CAPTURADA. É a única forma de exercitar o caminho
     * cobrado sem tocar na rede: o capturePayment() sai logo a true quando a
     * order já está SUCCESS, em vez de chamar o gateway.
     */
    private function jaCapturado(Service $service): PaymentOrder
    {
        $order = PaymentOrder::query()->create([
            'user_id' => $service->customer_id,
            'uuid' => (string) Str::uuid(),
            'amount' => (int) $service->getRawOriginal('amount'),
            'paid' => true,
            'status' => Status::SUCCESS,
            'type' => OperationType::DEFERRED,
            'refunded' => 0,
            'service' => 'piquet-testes',
            'service_uuid' => (string) Str::uuid(),
            'token' => (string) Str::uuid(),
            'ip' => '127.0.0.1',
        ]);

        // payment_order_id não está no fillable do Service.
        $service->forceFill(['payment_order_id' => $order->id])->save();

        return $order;
    }

    // ── o cliente desiste antes de haver trabalho ──────────────────────────

    public function test_o_cliente_cancela_um_pedido_por_atribuir(): void
    {
        $service = $this->servico(ServiceStatus::PENDING);

        (new CancelService($service))->customerCancel();

        $service->refresh();
        $this->assertSame(ServiceStatus::CANCELED, $service->status);
        $this->assertSame('internal/services.cancel.description', $service->status_justification);
    }

    public function test_o_cliente_cancela_um_servico_agendado(): void
    {
        $service = $this->servico(ServiceStatus::SCHEDULED);

        (new CancelService($service))->customerCancel();

        $this->assertSame(ServiceStatus::CANCELED, $service->fresh()->status);
    }

    /**
     * O cancelamento simples só age em PENDING e SCHEDULED. Num serviço já
     * aceite sai em silêncio — e é por isso que existe o cancelOpenService(),
     * que é quem sabe cobrar. Trocar um pelo outro é o erro caro desta classe.
     */
    public function test_o_cancelamento_simples_nao_toca_num_servico_ja_aceite(): void
    {
        $service = $this->servico(ServiceStatus::ACCEPTED);

        (new CancelService($service))->customerCancel();

        $this->assertSame(ServiceStatus::ACCEPTED, $service->fresh()->status);
    }

    public function test_desistir_antes_de_o_mbway_confirmar_usa_um_estado_proprio(): void
    {
        $service = $this->servico(ServiceStatus::PENDING, [
            'pending_schedule_data' => ['scheduled_day' => '2026-10-01'],
        ]);

        (new CancelService($service))->customerCancelBeforePayment();

        $service->refresh();
        // Estado terminal próprio: o técnico nunca chegou a ser notificado
        // deste serviço, por isso não leva aviso de cancelamento.
        $this->assertSame(ServiceStatus::CANCELED_MBWAY, $service->status);
        $this->assertSame('internal/services.mbway.canceled', $service->status_justification);
        $this->assertNull($service->pending_schedule_data, 'a marcação por materializar tem de sair com ele');
    }

    // ── o cliente desiste com o técnico já a caminho ───────────────────────

    /**
     * Aceite mas ainda parado não cobra: ninguém se deslocou. O gatilho da
     * cobrança é "a caminho", à letra.
     */
    public function test_cancelar_com_o_tecnico_parado_nao_cobra_ninguem(): void
    {
        $service = $this->servico(ServiceStatus::ACCEPTED, ['on_the_way_at' => null]);
        $this->jaCapturado($service);
        $vendorUser = $service->vendor->user;
        $saldoAntes = (int) $vendorUser->balance;

        (new CancelService($service->fresh()))->cancelOpenService();

        $service->refresh();
        $this->assertSame(ServiceStatus::CANCELED, $service->status);
        $this->assertSame('internal/services.cancel.description', $service->status_justification);
        $this->assertSame($saldoAntes, (int) $vendorUser->fresh()->balance, 'ninguém recebe por uma deslocação que não houve');
    }

    public function test_cancelar_com_o_tecnico_no_local_cobra_e_reparte_ao_meio(): void
    {
        $service = $this->servico(ServiceStatus::ARRIVED);
        $this->jaCapturado($service);

        $vendorUser = $service->vendor->user;
        $saldoTecnicoAntes = (int) $vendorUser->balance;
        $saldoSistemaAntes = (int) system_wallet()->balance;

        (new CancelService($service->fresh()))->cancelOpenService();

        $service->refresh();
        $this->assertSame(ServiceStatus::CANCELED, $service->status);
        $this->assertSame('internal/services.cancel.charged', $service->status_justification);

        // 5000 cêntimos, 50/50 — a mesma repartição do cancelamento com o
        // técnico a caminho, porque também aqui não houve trabalho feito.
        $repartido = CancellationPolicy::split(5000);
        $this->assertSame(2500, $repartido['vendor']);
        $this->assertSame($saldoTecnicoAntes + 2500, (int) $vendorUser->fresh()->balance);
        $this->assertSame($saldoSistemaAntes + 2500, (int) system_wallet()->balance);
    }

    /**
     * A ordem importa: sem captura não se cobra ninguém. Se se depositasse
     * primeiro, a plataforma pagava ao técnico dinheiro que nunca conseguiu
     * cobrar ao cliente.
     */
    public function test_se_a_cobranca_falhar_cancela_na_mesma_sem_pagar_a_ninguem(): void
    {
        // Sem ordem de pagamento, capturePayment() devolve false.
        $service = $this->servico(ServiceStatus::ARRIVED);
        $vendorUser = $service->vendor->user;
        $saldoTecnicoAntes = (int) $vendorUser->balance;
        $saldoSistemaAntes = (int) system_wallet()->balance;

        (new CancelService($service))->cancelOpenService();

        $service->refresh();
        $this->assertSame(ServiceStatus::CANCELED, $service->status);
        $this->assertSame(
            'internal/services.cancel.description',
            $service->status_justification,
            'sem cobrança, a justificação não pode dizer que foi cobrado',
        );
        $this->assertSame($saldoTecnicoAntes, (int) $vendorUser->fresh()->balance);
        $this->assertSame($saldoSistemaAntes, (int) system_wallet()->balance);
    }

    // ── cancelar um agendado, com escalão de penalização ───────────────────

    public function test_um_agendado_cancelado_cedo_nao_leva_penalizacao(): void
    {
        $service = $this->servico(ServiceStatus::SCHEDULED);
        $vendorUser = $service->vendor->user;
        $saldoAntes = (int) $vendorUser->balance;

        // Escalão 0: cai no cancelamento normal.
        (new CancelService($service))->customerCancelScheduled(0.0);

        $service->refresh();
        $this->assertSame(ServiceStatus::CANCELED, $service->status);
        $this->assertSame('internal/services.cancel.description', $service->status_justification);
        $this->assertSame($saldoAntes, (int) $vendorUser->fresh()->balance);
    }

    public function test_um_agendado_com_penalizacao_mas_sem_cobranca_possivel_nao_penaliza(): void
    {
        // Sem ordem de pagamento não há o que capturar: mais vale não cobrar
        // do que depositar dinheiro que não entrou.
        $service = $this->servico(ServiceStatus::SCHEDULED);
        $vendorUser = $service->vendor->user;
        $saldoAntes = (int) $vendorUser->balance;

        (new CancelService($service))->customerCancelScheduled(1.0);

        $service->refresh();
        $this->assertSame(ServiceStatus::CANCELED, $service->status);
        $this->assertSame('internal/services.cancel.description', $service->status_justification);
        $this->assertSame($saldoAntes, (int) $vendorUser->fresh()->balance);
    }

    // ── falta do técnico: o cliente é reembolsado, sempre ──────────────────

    /**
     * Existe à parte do cancelOpenService() por uma razão que custa dinheiro a
     * quem se engane: esse, com o técnico a caminho, COBRA 100% ao cliente e
     * dá metade ao técnico. Numa falta seria ao contrário do que se quer.
     */
    public function test_a_falta_do_tecnico_cancela_sem_cobrar_o_cliente(): void
    {
        $service = $this->servico(ServiceStatus::ACCEPTED, ['on_the_way_at' => Carbon::now()->subHour()]);
        $vendorUser = $service->vendor->user;
        $saldoAntes = (int) $vendorUser->balance;

        (new CancelService($service))->vendorNoShowCancel();

        $service->refresh();
        $this->assertSame(ServiceStatus::CANCELED, $service->status);
        $this->assertSame('internal/services.cancel.vendor_no_show', $service->status_justification);
        $this->assertSame(
            $saldoAntes,
            (int) $vendorUser->fresh()->balance,
            'quem faltou não recebe metade por ter estado a caminho',
        );
    }

    public function test_a_falta_do_tecnico_tambem_age_num_servico_ainda_agendado(): void
    {
        $service = $this->servico(ServiceStatus::SCHEDULED);

        (new CancelService($service))->vendorNoShowCancel();

        $this->assertSame(ServiceStatus::CANCELED, $service->fresh()->status);
    }

    // ── o técnico cancela ──────────────────────────────────────────────────

    public function test_o_tecnico_cancela_um_servico_aceite_e_gera_se_a_fatura(): void
    {
        $service = $this->servico(ServiceStatus::ACCEPTED);

        (new CancelService($service))->vendorCancelService();

        $this->assertSame(ServiceStatus::CANCELED, $service->fresh()->status);
        Queue::assertPushed(CreateVendorCancellationInvoiceJob::class);
    }

    public function test_o_tecnico_cancela_um_agendado_sem_gerar_fatura(): void
    {
        $service = $this->servico(ServiceStatus::SCHEDULED);

        (new CancelService($service))->vendorCancelService();

        $this->assertSame(ServiceStatus::CANCELED, $service->fresh()->status);
        // Só o ramo do serviço já aceite gera fatura de cancelamento.
        Queue::assertNotPushed(CreateVendorCancellationInvoiceJob::class);
    }

    // ── o superadmin desfaz um serviço já fechado ──────────────────────────

    public function test_so_um_superadmin_pode_desfazer_um_servico_fechado(): void
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $service = $this->servico(ServiceStatus::CLOSED);

        $this->expectException(HttpException::class);

        (new CancelService($service))->superAdminCancelClosedService('engano');
    }

    public function test_desfazer_um_servico_fechado_devolve_o_que_ja_tinha_sido_pago(): void
    {
        Role::findOrCreate('super-admin');
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $this->actingAs($admin);

        $service = $this->servico(ServiceStatus::CLOSED);
        $vendorUser = $service->vendor->user;

        // Simula o fecho: o técnico recebeu 3750 e a plataforma os 1250 de comissão.
        $vendorUser->deposit(3750, $service->getMetaProduct());
        system_wallet()->deposit(1250, $service->getMetaProduct());

        $saldoTecnicoDepoisDoFecho = (int) $vendorUser->fresh()->balance;
        $saldoSistemaDepoisDoFecho = (int) system_wallet()->balance;

        (new CancelService($service))->superAdminCancelClosedService('serviço mal fechado');

        $service->refresh();
        $this->assertSame(ServiceStatus::CANCELED, $service->status);
        $this->assertSame('serviço mal fechado', $service->status_justification);

        $this->assertSame(
            $saldoTecnicoDepoisDoFecho - 3750,
            (int) $vendorUser->fresh()->balance,
            'o que o técnico recebeu no fecho tem de ser revertido',
        );
        $this->assertSame(
            $saldoSistemaDepoisDoFecho - 1250,
            (int) system_wallet()->balance,
            'a comissão da plataforma também volta atrás',
        );

        Queue::assertPushed(CreateCancellationInvoiceJob::class);
    }

    public function test_um_servico_que_nao_esta_fechado_nao_se_desfaz_por_aqui(): void
    {
        Role::findOrCreate('super-admin');
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $this->actingAs($admin);

        $service = $this->servico(ServiceStatus::ACCEPTED);

        $this->expectExceptionMessage('Only closed services can be cancelled and refunded here.');

        (new CancelService($service))->superAdminCancelClosedService('engano');
    }
}
