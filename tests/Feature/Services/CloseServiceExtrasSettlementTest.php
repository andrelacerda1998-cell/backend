<?php

namespace Tests\Feature\Services;

use App\Enums\Services\ServiceStatus;
use App\Models\GeneralSettings\Gender;
use App\Models\Service;
use App\Models\ServiceExtra;
use App\Services\Common\Services\CloseService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Crédito ao técnico dos extras aprovados, no fecho do serviço. O tempo extra
 * leva a MESMA proporção do serviço base; uma peça vai inteira para o técnico,
 * porque é o reembolso de um custo (decisão do André, 02/10/2026, ver
 * CloseService::settleExtras). O que nunca foi cobrado (ainda pendente, ou
 * falhou) NUNCA credita, e um extra já creditado nunca é creditado 2x.
 */
class CloseServiceExtrasSettlementTest extends TestCase
{
    // NÃO RefreshDatabase aqui: mesma razão documentada em
    // AdminVendorsApiTest e ChargeServiceExtraTest -- a CI corre contra uma
    // BD MySQL partilhada por toda a suite, e RefreshDatabase não limpa o
    // que outras classes com DatabaseTruncation já commitaram antes desta.
    use DatabaseTruncation;

    // 'transactions'/'transfers' entram porque CloseService credita a
    // wallet do técnico (Bavix\Wallet) ao fechar o serviço.
    protected array $tablesToTruncate = [
        'users', 'wallets', 'vendors', 'schedule_available',
        'services', 'services_types', 'operation_areas', 'service_extras',
        'transactions', 'transfers',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Ver AdminVendorsApiTest -- criar um Vendor dispara uma cadeia de
        // observers que tenta indexar no Meilisearch.
        config(['scout.driver' => 'null']);

        Gender::firstOrCreate(['name' => 'Masculino']);
        Notification::fake();
        Queue::fake(); // evita o job real de faturação (InvoiceXpress) disparado ao fechar o serviço
    }

    private function finishedService(int $amount = 10000, int $amountForVendor = 7500): Service
    {
        return Service::factory()->create([
            'status' => ServiceStatus::FINISHED,
            'amount' => $amount,
            'amount_for_vendor' => $amountForVendor,
        ]);
    }

    public function test_close_credits_the_vendor_for_an_approved_and_charged_extra_at_the_same_ratio_as_the_base_service(): void
    {
        // 75% para o técnico no serviço base (amount_for_vendor/amount = 7500/10000).
        $service = $this->finishedService(amount: 10000, amountForVendor: 7500);
        $extra = ServiceExtra::factory()->approved()->create([
            'service_id' => $service->id,
            'amount' => 2000,
            'payment_status' => 'paid',
            'charged_at' => now(),
        ]);

        $vendorWalletBefore = $service->vendor->user->balanceInt;

        (new CloseService($service))->close();

        $service->refresh();
        $this->assertSame(ServiceStatus::CLOSED, $service->status);

        $extra->refresh();
        $this->assertNotNull($extra->vendor_credited_at);

        // Serviço base (7500) + 75% de 2000 (=1500) do extra = 9000 no total.
        $vendorWalletAfter = $service->vendor->user->refresh()->balanceInt;
        $this->assertSame(9000, $vendorWalletAfter - $vendorWalletBefore);
    }

    /**
     * Uma peça vai inteira para o técnico. Com os 75/25 uma torneira de 50 €
     * devolvia-lhe 37,50 €: pagava 12,50 € por a ter comprado, e a reação
     * natural a isso é deixar de a declarar.
     */
    public function test_close_credits_a_part_in_full_without_commission(): void
    {
        $service = $this->finishedService(amount: 10000, amountForVendor: 7500);
        $extra = ServiceExtra::factory()->approved()->create([
            'service_id' => $service->id,
            'type' => 'part',
            'description' => 'Torneira nova',
            'minutes' => null,
            'amount' => 5000,
            'payment_status' => 'paid',
            'charged_at' => now(),
        ]);

        $antes = $service->vendor->user->balanceInt;
        $plataformaAntes = (int) system_wallet()->balance;

        (new CloseService($service))->close();

        $this->assertNotNull($extra->refresh()->vendor_credited_at);

        // Serviço base (7500) + a peça INTEIRA (5000) = 12500.
        $this->assertSame(12500, $service->vendor->user->refresh()->balanceInt - $antes);
        // A plataforma fica só com a comissão do serviço base (2500), nada da peça.
        $this->assertSame(2500, (int) system_wallet()->balance - $plataformaAntes);
    }

    /** O tempo extra continua a levar comissão: é a mão de obra do serviço que prolonga. */
    public function test_close_still_takes_commission_on_extra_time(): void
    {
        $service = $this->finishedService(amount: 10000, amountForVendor: 7500);
        ServiceExtra::factory()->approved()->create([
            'service_id' => $service->id,
            'type' => 'time',
            'minutes' => 60,
            'amount' => 4000,
            'payment_status' => 'paid',
            'charged_at' => now(),
        ]);

        $antes = $service->vendor->user->balanceInt;

        (new CloseService($service))->close();

        // Serviço base (7500) + 75% de 4000 (=3000) = 10500.
        $this->assertSame(10500, $service->vendor->user->refresh()->balanceInt - $antes);
    }

    public function test_close_never_credits_an_extra_that_was_never_actually_charged(): void
    {
        $service = $this->finishedService();
        $extra = ServiceExtra::factory()->approved()->create([
            'service_id' => $service->id,
            'amount' => 2000,
            'payment_status' => 'requires_action', // aprovado mas nunca cobrado
        ]);

        (new CloseService($service))->close();

        $this->assertNull($extra->refresh()->vendor_credited_at);
    }

    public function test_close_never_credits_the_same_extra_twice(): void
    {
        $service = $this->finishedService();
        $extra = ServiceExtra::factory()->approved()->create([
            'service_id' => $service->id,
            'amount' => 2000,
            'payment_status' => 'paid',
            'charged_at' => now(),
            'vendor_credited_at' => now()->subDay(), // já foi creditado antes
        ]);
        $vendorWalletBefore = $service->vendor->user->balanceInt;

        (new CloseService($service))->close();

        // O saldo só deve refletir o serviço base (amount_for_vendor) — nada do extra outra vez.
        $vendorWalletAfter = $service->vendor->user->refresh()->balanceInt;
        $this->assertSame($service->amount_for_vendor, $vendorWalletAfter - $vendorWalletBefore);
    }

    public function test_pending_extras_are_ignored_at_close(): void
    {
        $service = $this->finishedService();
        $extra = ServiceExtra::factory()->create(['service_id' => $service->id]); // ainda pending

        (new CloseService($service))->close();

        $this->assertNull($extra->refresh()->vendor_credited_at);
        $this->assertSame('pending', $extra->status);
    }
}
