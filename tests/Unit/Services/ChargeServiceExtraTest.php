<?php

namespace Tests\Unit\Services;

use App\Models\GeneralSettings\Gender;
use App\Models\Service;
use App\Models\ServiceExtra;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\Support\FakeChargeServiceExtra;
use Tests\TestCase;

/**
 * Estado da cobrança de um extra (tempo/peças) aprovado — a peça mais sensível
 * do fluxo, porque envolve dinheiro real e tem de ser idempotente. O Payshop é
 * substituído pelo FakeChargeServiceExtra (ver tests/Support), que controla o
 * desfecho da chamada sem qualquer pedido HTTP real.
 */
class ChargeServiceExtraTest extends TestCase
{
    // NÃO RefreshDatabase aqui: a CI corre TODA a suite contra a mesma BD
    // MySQL partilhada, e várias outras classes (AdminVendorsApiTest,
    // AdminServicesTypesApiTest, etc.) usam DatabaseTruncation em modo
    // autocommit -- os dados que criam ficam mesmo gravados, fora de
    // qualquer transação. RefreshDatabase só embrulha CADA teste numa
    // transação revertida no fim; não limpa o que outra classe já tinha
    // committado antes desta começar (ver comentário em
    // AdminVendorsApiTest::class para o precedente exato deste problema).
    use DatabaseTruncation;

    // 'wallets' entra por causa do UserObserver (cria uma wallet por User
    // novo); 'schedule_available' por causa do VendorObserver (cria 7 linhas
    // por Vendor novo). 'services_types'/'operation_areas' porque o
    // ServiceFactory aninha os dois. 'payshop_payment_methods' tem de entrar
    // OBRIGATORIAMENTE: sem truncar, um cartão criado num teste anterior
    // (paymentMethods()->create(...)) fica na BD; quando o TRUNCATE de
    // 'users' reinicia o auto_increment, o próximo customer#1 herda esse
    // cartão órfão e passa a parecer "tem cartão guardado" quando não devia
    // -- foi exatamente isto que rebentou "no_stored_payment_method" nos
    // testes seguintes. 'payshop_payments_orders' pela mesma razão, porque o
    // FakeChargeServiceExtra cria PaymentOrder reais nessa tabela.
    protected array $tablesToTruncate = [
        'users', 'wallets', 'vendors', 'schedule_available',
        'services', 'services_types', 'operation_areas', 'service_extras',
        'payshop_payment_methods', 'payshop_payments_orders',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Ver AdminVendorsApiTest -- criar um Vendor dispara uma cadeia de
        // observers (VendorObserver → ScheduleAvailable → ScheduleAvailableObserver)
        // que tenta indexar no Meilisearch.
        config(['scout.driver' => 'null']);

        Gender::firstOrCreate(['name' => 'Masculino']);
    }

    private function makeService(bool $isTest = false): Service
    {
        return Service::factory()->create(['is_test' => $isTest]);
    }

    public function test_charge_succeeds_with_a_stored_card(): void
    {
        $service = $this->makeService();
        $service->customer->paymentMethods()->create([
            'type' => 'card',
            'uuid' => 'card-uuid',
        ]);
        $extra = ServiceExtra::factory()->approved()->create(['service_id' => $service->id, 'amount' => 1500]);

        $charger = new FakeChargeServiceExtra;
        $charger->outcome = 'success';
        $result = $charger->charge($service, $extra);

        $this->assertSame('paid', $result);
        $extra->refresh();
        $this->assertSame('paid', $extra->payment_status);
        $this->assertNotNull($extra->payment_order_id);
        $this->assertNotNull($extra->charged_at);
        $this->assertTrue($extra->isCharged());
    }

    public function test_charge_marks_requires_action_and_stores_validation_url_on_3ds(): void
    {
        $service = $this->makeService();
        $service->customer->paymentMethods()->create(['type' => 'card', 'uuid' => 'card-uuid']);
        $extra = ServiceExtra::factory()->approved()->create(['service_id' => $service->id]);

        $charger = new FakeChargeServiceExtra;
        $charger->outcome = '3ds';
        $charger->validationUrl = 'https://fake-payshop.test/3ds/validate/xyz';
        $result = $charger->charge($service, $extra);

        $this->assertSame('requires_action', $result);
        $extra->refresh();
        $this->assertSame('requires_action', $extra->payment_status);
        $this->assertSame('3ds_required', $extra->payment_error);
        $this->assertSame('https://fake-payshop.test/3ds/validate/xyz', $extra->payment_validation_url);
        $this->assertNotNull($extra->payment_order_id); // ordem viva, à espera do 3DS
        $this->assertFalse($extra->isCharged());
    }

    public function test_charge_marks_requires_action_without_order_when_no_stored_payment_method(): void
    {
        $service = $this->makeService();
        // Sem paymentMethods() — nenhum cartão gravado.
        $extra = ServiceExtra::factory()->approved()->create(['service_id' => $service->id]);

        $charger = new FakeChargeServiceExtra;
        $result = $charger->charge($service, $extra);

        $this->assertSame('requires_action', $result);
        $extra->refresh();
        $this->assertSame('no_stored_payment_method', $extra->payment_error);
        $this->assertNull($extra->payment_order_id); // nenhuma ordem chegou a ser criada
        $this->assertNull($extra->payment_validation_url);
    }

    public function test_charge_marks_failed_when_the_bank_declines(): void
    {
        $service = $this->makeService();
        $service->customer->paymentMethods()->create(['type' => 'card', 'uuid' => 'card-uuid']);
        $extra = ServiceExtra::factory()->approved()->create(['service_id' => $service->id]);

        $charger = new FakeChargeServiceExtra;
        $charger->outcome = 'declined';
        $result = $charger->charge($service, $extra);

        $this->assertSame('failed', $result);
        $extra->refresh();
        $this->assertSame('failed', $extra->payment_status);
        $this->assertNotNull($extra->payment_order_id);
    }

    public function test_test_service_or_zero_amount_extra_needs_no_charge(): void
    {
        $service = $this->makeService(isTest: true);
        $extra = ServiceExtra::factory()->approved()->create(['service_id' => $service->id, 'amount' => 1500]);

        $charger = new FakeChargeServiceExtra;
        $result = $charger->charge($service, $extra);

        $this->assertSame('not_required', $result);
        $extra->refresh();
        $this->assertSame('not_required', $extra->payment_status);
        $this->assertTrue($extra->isCharged());
        $this->assertNull($extra->payment_order_id); // nunca chegou a criar ordem nenhuma
    }

    public function test_charge_never_creates_a_second_order_once_paid(): void
    {
        $service = $this->makeService();
        $service->customer->paymentMethods()->create(['type' => 'card', 'uuid' => 'card-uuid']);
        $extra = ServiceExtra::factory()->approved()->create(['service_id' => $service->id]);

        $charger = new FakeChargeServiceExtra;
        $charger->outcome = 'success';
        $charger->charge($service, $extra);
        $firstOrderId = $extra->refresh()->payment_order_id;

        // Segunda chamada (ex.: double-tap, retry de rede) — idempotente.
        $result = $charger->charge($service, $extra->refresh());

        $this->assertSame('paid', $result);
        $this->assertSame($firstOrderId, $extra->refresh()->payment_order_id);
    }

    public function test_charge_never_creates_a_second_order_while_3ds_is_pending(): void
    {
        $service = $this->makeService();
        $service->customer->paymentMethods()->create(['type' => 'card', 'uuid' => 'card-uuid']);
        $extra = ServiceExtra::factory()->approved()->create(['service_id' => $service->id]);

        $charger = new FakeChargeServiceExtra;
        $charger->outcome = '3ds';
        $charger->charge($service, $extra);
        $firstOrderId = $extra->refresh()->payment_order_id;

        // Uma nova tentativa NÃO deve criar uma segunda ordem enquanto a primeira
        // está viva à espera do 3DS — mesmo que o outcome mude entretanto.
        $charger->outcome = 'success';
        $result = $charger->charge($service, $extra->refresh());

        $this->assertSame('requires_action', $result); // devolve o estado tal como estava
        $this->assertSame($firstOrderId, $extra->refresh()->payment_order_id);
    }

    public function test_retry_is_possible_after_no_stored_payment_method_once_a_card_exists(): void
    {
        $service = $this->makeService();
        $extra = ServiceExtra::factory()->approved()->create(['service_id' => $service->id]);

        $charger = new FakeChargeServiceExtra;
        $charger->charge($service, $extra); // sem cartão -> requires_action, sem ordem

        $this->assertNull($extra->refresh()->payment_order_id);

        // O cliente adiciona um cartão...
        $service->customer->paymentMethods()->create(['type' => 'card', 'uuid' => 'card-uuid']);

        $charger->outcome = 'success';
        $result = $charger->charge($service, $extra->refresh());

        $this->assertSame('paid', $result);
        $this->assertNotNull($extra->refresh()->payment_order_id);
    }
}
