<?php

namespace Tests\Feature\Services;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Schedule\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Voucher;
use App\Models\VoucherUsage;
use App\Services\Common\Services\RefuseService;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RwInteractive\PayshopSdk\Enums\Payment\OperationType;
use RwInteractive\PayshopSdk\Enums\Payment\Status;
use RwInteractive\PayshopSdk\Models\PaymentOrder;
use Tests\TestCase;

/**
 * O profissional recusa um pedido.
 *
 * Esta classe não tinha um único teste, em camada nenhuma, e decide o que
 * acontece ao dinheiro de um cliente: liberta o cativo ou reembolsa, devolve o
 * crédito usado, devolve o voucher, e liberta a hora que estava reservada na
 * agenda. Uma regressão aqui não dá erro — dá dinheiro retido e horas presas.
 */
class RefuseServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A UserFactory escolhe um género da tabela; sem seed, sai null.
        $this->seed(GenderSeeder::class);
        Notification::fake();

        // O SDK do Payshop aponta por omissão para o sandbox REAL do Paylands.
        // Um teste nunca pode lá bater: aponta-se para uma porta onde não há
        // ninguém a ouvir, para a ligação ser recusada de imediato em vez de
        // ficar a bloquear — e assim exercita-se, de propósito, o caminho de
        // "o gateway não respondeu".
        config([
            'payshop-sdk.environment' => 'sandbox',
            'payshop-sdk.api_endpoint.sandbox' => 'http://127.0.0.1:9/',
            'payshop-sdk.connect_timeout' => 0.25,
            'payshop-sdk.timeout' => 0.25,
        ]);
    }

    private function pedido(ServiceStatus $estado = ServiceStatus::PENDING, array $extra = []): Service
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
     * Uma ordem de pagamento com o dinheiro CATIVO (autorizado, por capturar) —
     * é o estado em que está um pedido à espera da resposta do profissional.
     * A tabela do SDK tem quase tudo NOT NULL, daí o preenchimento completo.
     */
    private function cativoDe(Service $service): PaymentOrder
    {
        return PaymentOrder::query()->create([
            'user_id' => $service->customer_id,
            'uuid' => (string) Str::uuid(),
            'amount' => (int) $service->getRawOriginal('amount'),
            'paid' => false,
            'status' => Status::PENDING_CONFIRMATION,
            'type' => OperationType::DEFERRED,
            'refunded' => 0,
            'service' => 'piquet-testes',
            'service_uuid' => (string) Str::uuid(),
            'token' => (string) Str::uuid(),
            'ip' => '127.0.0.1',
        ]);
    }

    // --- o que a recusa faz ------------------------------------------------

    public function test_um_pedido_pendente_fica_recusado_e_diz_porque(): void
    {
        $service = $this->pedido();

        (new RefuseService($service))->refuse();

        $service->refresh();
        $this->assertSame(ServiceStatus::REFUSED, $service->status);
        $this->assertSame('internal/services.refused.vendor', $service->status_justification);
    }

    public function test_a_recusa_liberta_a_hora_que_estava_reservada(): void
    {
        $service = $this->pedido();
        $schedule = Schedule::query()->create([
            'vendor_id' => $service->vendor_id,
            'customer_id' => $service->customer_id,
            'service_type_id' => $service->services_type_id,
            'service_id' => $service->id,
            'scheduled_day' => Carbon::today('Europe/Lisbon')->addWeek()->toDateString(),
            'scheduled_time_start' => '14:30:00',
            'scheduled_time_end' => '15:30:00',
            'is_pending' => true,
        ]);

        (new RefuseService($service))->refuse();

        // Soft delete: a linha fica na base, mas deixa de contar na agenda.
        $this->assertSoftDeleted('schedule', ['id' => $schedule->id]);
    }

    public function test_a_recusa_devolve_o_credito_que_o_cliente_tinha_usado(): void
    {
        $service = $this->pedido(extra: ['credit_used' => 1200]);
        $customer = $service->customer;

        $saldoAntes = (int) $customer->balance;

        (new RefuseService($service))->refuse();

        $this->assertSame(
            $saldoAntes + 1200,
            (int) $customer->fresh()->balance,
            'O crédito usado num pedido recusado tem de voltar para a carteira do cliente',
        );
    }

    public function test_a_recusa_devolve_o_voucher_para_poder_ser_usado_outra_vez(): void
    {
        $service = $this->pedido();
        $voucher = Voucher::query()->create([
            'name' => 'BEMVINDO',
            'discount_percentage' => 10,
            'is_active' => true,
        ]);
        VoucherUsage::query()->create([
            'voucher_id' => $voucher->id,
            'user_id' => $service->customer_id,
            'service_id' => $service->id,
            'used_at' => now(),
        ]);

        (new RefuseService($service))->refuse();

        $this->assertDatabaseMissing('voucher_usages', ['service_id' => $service->id]);
    }

    // --- o que a recusa NÃO faz -------------------------------------------

    public function test_recusar_duas_vezes_o_mesmo_pedido_avisa_em_vez_de_repetir(): void
    {
        $service = $this->pedido(ServiceStatus::CANCELED);

        $this->expectExceptionMessage('Service was already canceled');

        (new RefuseService($service))->refuse();
    }

    /**
     * Esta é a que mais vale a pena prender: a classe só age em PENDING, e em
     * qualquer outro estado sai em silêncio — sem erro, sem aviso, sem sinal
     * nenhum de que não fez nada. Quem chamar isto num serviço já aceite fica
     * a acreditar que o recusou.
     */
    public function test_um_servico_ja_aceite_nao_e_recusado_e_ninguem_e_avisado_disso(): void
    {
        $service = $this->pedido(ServiceStatus::ACCEPTED);

        (new RefuseService($service))->refuse();

        $this->assertSame(
            ServiceStatus::ACCEPTED,
            $service->fresh()->status,
            'A recusa não mexe num serviço já aceite — e também não se queixa',
        );
    }

    public function test_um_servico_agendado_tambem_sai_em_silencio(): void
    {
        $service = $this->pedido(ServiceStatus::SCHEDULED);

        (new RefuseService($service))->refuse();

        $this->assertSame(ServiceStatus::SCHEDULED, $service->fresh()->status);
    }

    // --- quando o gateway não responde -------------------------------------

    /**
     * Com o Payshop em baixo, a recusa avança na mesma: o profissional não pode
     * ficar preso a um pedido por o gateway estar mal.
     *
     * Mas o teste prende também o preço disso, que é o que interessa saber: a
     * escrita de `payment_status = REFUNDED` está DENTRO do try, por isso uma
     * falha deixa o serviço recusado com o pagamento ainda por libertar e sem
     * marca nenhuma no estado. Só o Log::warning sabe. Se um dia isto mudar
     * para melhor, este teste falha e obriga a olhar.
     */
    public function test_com_o_gateway_em_baixo_a_recusa_avanca_mas_o_pagamento_fica_por_libertar(): void
    {
        $service = $this->pedido();
        $order = $this->cativoDe($service);
        // forceFill: payment_order_id não está no fillable do Service.
        $service->forceFill(['payment_order_id' => $order->id])->save();

        // Sem isto o teste passaria à mesma com o pagamento por ligar — e não
        // estaria a exercitar caminho nenhum.
        $this->assertNotNull($service->fresh()->paymentOrder, 'o cenário exige um cativo ligado ao serviço');

        (new RefuseService($service))->refuse();

        $service->refresh();
        $this->assertSame(ServiceStatus::REFUSED, $service->status, 'a recusa não pode ficar refém do gateway');
        $this->assertNotSame(
            PaymentStatus::REFUNDED,
            $service->payment_status,
            'o reembolso não aconteceu: o estado do pagamento não pode dizer que sim',
        );
        $this->assertSame(
            Status::PENDING_CONFIRMATION,
            $order->fresh()->status,
            'o cativo continua de pé — fica para reconciliação',
        );
    }
}
