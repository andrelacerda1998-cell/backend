<?php

namespace Tests\Feature\Services;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Common\Services\CancelService;
use App\Services\InvoiceXpress\InvoiceVendorService;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RwInteractive\PayshopSdk\Enums\Payment\OperationType;
use RwInteractive\PayshopSdk\Enums\Payment\Status;
use RwInteractive\PayshopSdk\Models\PaymentOrder;
use Tests\TestCase;

/**
 * Um pedido personalizado não tem tipo de serviço (`services_type_id` é null
 * de propósito). Dois sítios liam o nome do tipo sem `?->`:
 *  - a fatura do técnico: Error fatal, a fatura nunca saía e o dinheiro dele
 *    ficava retido (só se paga o que está faturado);
 *  - os Ganhos (Service::getMetaProduct): o cancelamento com taxa rebentava.
 */
class PersonalizadoSemTipoDeServicoTest extends TestCase
{
    use RefreshDatabase;

    private const DESCRICAO = 'Trocar a fechadura da porta da rua.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(GenderSeeder::class);
        Notification::fake();
        Queue::fake();
        config(['scout.driver' => 'null']);

        // O SDK aponta por omissão para o sandbox REAL do Paylands: nunca lá
        // bater (ver CancelServiceTest).
        config([
            'payshop-sdk.environment' => 'sandbox',
            'payshop-sdk.api_endpoint.sandbox' => 'http://127.0.0.1:9/',
            'payshop-sdk.connect_timeout' => 0.25,
            'payshop-sdk.timeout' => 0.25,
        ]);
    }

    private function personalizado(ServiceStatus $estado, array $extra = []): Service
    {
        $service = Service::factory()->create(array_merge([
            'customer_id' => User::factory()->create(['first_name' => 'Ana', 'last_name' => 'Silva'])->id,
            'vendor_id' => Vendor::factory()->create()->id,
            'services_type_id' => null,
            'status' => $estado,
            'payment_status' => PaymentStatus::PAID,
            'amount' => 5000,
            'amount_for_vendor' => 3750,
            'credit_used' => 0,
        ], $extra));

        $service->forceFill([
            'is_custom' => true,
            'custom_description' => self::DESCRICAO,
            'custom_duration_minutes' => 90,
        ])->save();

        return $service->fresh();
    }

    /** Ordem já capturada: o capturePayment() sai a true sem ir à rede. */
    private function jaCapturado(Service $service): void
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

        $service->forceFill(['payment_order_id' => $order->id])->save();
    }

    public function test_os_ganhos_de_um_personalizado_levam_o_que_o_cliente_descreveu(): void
    {
        $meta = $this->personalizado(ServiceStatus::CLOSED)->getMetaProduct();

        $this->assertStringContainsString('Trocar a fechadura', $meta['description']);
    }

    public function test_um_servico_normal_continua_com_o_nome_do_tipo(): void
    {
        $tipo = ServicesType::factory()->create(['name' => ['pt-pt' => 'Canalização', 'en' => 'Plumbing']]);
        $service = $this->personalizado(ServiceStatus::CLOSED);
        $service->forceFill(['services_type_id' => $tipo->id, 'is_custom' => false])->save();

        $this->assertStringContainsString($tipo->fresh()->name, $service->fresh()->getMetaProduct()['description']);
    }

    /** O caso que rebentava: taxa de cancelamento com o técnico no local. */
    public function test_cancelar_um_personalizado_com_o_tecnico_no_local_ja_nao_rebenta(): void
    {
        $service = $this->personalizado(ServiceStatus::ARRIVED);
        $this->jaCapturado($service);
        $saldoAntes = (int) $service->vendor->user->balance;

        (new CancelService($service->fresh()))->cancelOpenService();

        $service->refresh();
        $this->assertSame(ServiceStatus::CANCELED, $service->status);
        $this->assertSame('internal/services.cancel.charged', $service->status_justification);
        $this->assertGreaterThan($saldoAntes, (int) $service->vendor->user->fresh()->balance, 'o técnico recebe a parte dele');
    }

    public function test_a_fatura_de_um_personalizado_sai_com_o_que_o_cliente_descreveu(): void
    {
        Http::fake(['*' => Http::response(['invoice_receipt' => ['id' => 4242]], 200)]);
        $service = $this->personalizado(ServiceStatus::CLOSED);
        $service->vendor->update(['invoice_workspace' => 'oficina-teste', 'auth_token' => 'chave-de-teste']);

        $id = (new InvoiceVendorService($service->vendor->fresh()))->createServiceInvoice($service->fresh());

        $this->assertSame(4242, $id);

        /** @var Request $pedido */
        $pedido = Http::recorded()->last()[0];
        $this->assertSame('Serviço: '.self::DESCRICAO, $pedido['invoice']['items'][0]['description']);
    }

    public function test_sem_descricao_a_fatura_diz_pedido_personalizado(): void
    {
        Http::fake(['*' => Http::response(['invoice_receipt' => ['id' => 4243]], 200)]);
        $service = $this->personalizado(ServiceStatus::CLOSED);
        $service->forceFill(['custom_description' => null])->save();
        $service->vendor->update(['invoice_workspace' => 'oficina-teste', 'auth_token' => 'chave-de-teste']);

        (new InvoiceVendorService($service->vendor->fresh()))->createServiceInvoice($service->fresh());

        $pedido = Http::recorded()->last()[0];
        $this->assertSame('Serviço: Pedido personalizado', $pedido['invoice']['items'][0]['description']);
    }
}
