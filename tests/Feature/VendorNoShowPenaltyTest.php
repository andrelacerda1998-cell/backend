<?php

namespace Tests\Feature;

use App\Enums\Services\ServiceStatus;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\Vendor\NoShowPenaltyNotification;
use App\Services\Common\Services\VendorNoShowPolicy;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Falta do tecnico: em vez de receber, e penalizado em metade do que ia receber.
 *
 * Estas regras tiram dinheiro a pessoas. O que os testes tem de garantir nao e
 * so que a conta esta certa — e que ninguem e penalizado duas vezes pela mesma
 * falta, e que a penalizacao nao desaparece por a carteira estar vazia.
 */
class VendorNoShowPenaltyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GenderSeeder::class);
        Notification::fake();
        config(['services.admin_api.token' => 'token-de-teste']);
    }

    private function makeService(int $amountForVendor = 4000, ServiceStatus $status = ServiceStatus::SCHEDULED): Service
    {
        $customer = User::factory()->create();
        $vendor = Vendor::factory()->create();
        $serviceType = ServicesType::factory()->create();

        return Service::factory()->create([
            'customer_id' => $customer->id,
            'vendor_id' => $vendor->id,
            'services_type_id' => $serviceType->id,
            'status' => $status,
            'amount' => 5000,
            'amount_for_vendor' => $amountForVendor,
        ]);
    }

    private function declareNoShow(Service $service)
    {
        return $this->withHeaders(['Authorization' => 'Bearer token-de-teste'])
            ->postJson("/api/v1/admin/services/{$service->id}/vendor-no-show");
    }

    // --- a conta -----------------------------------------------------------

    public function test_a_penalizacao_e_metade_do_que_o_tecnico_ia_receber(): void
    {
        $this->assertSame(2000, VendorNoShowPolicy::penaltyAmount(4000));
        $this->assertSame(1750, VendorNoShowPolicy::penaltyAmount(3500));
        // Arredonda ao centimo, nao trunca: 15,01 € -> 7,505 € -> 7,51 €.
        $this->assertSame(751, VendorNoShowPolicy::penaltyAmount(1501));
    }

    public function test_um_servico_sem_valor_para_o_tecnico_nao_gera_penalizacao(): void
    {
        $this->assertSame(0, VendorNoShowPolicy::penaltyAmount(0));
    }

    // --- o efeito na carteira ----------------------------------------------

    public function test_o_tecnico_e_debitado_em_metade_do_que_ia_receber(): void
    {
        $service = $this->makeService(4000);
        $vendorUser = $service->vendor->user;
        $vendorUser->deposit(10000);

        $this->declareNoShow($service)->assertOk();

        $this->assertSame('8000', $vendorUser->fresh()->balance);
        $this->assertSame(2000, $service->fresh()->vendor_no_show_penalty);
    }

    public function test_a_carteira_vazia_fica_em_divida_em_vez_de_perdoar(): void
    {
        $service = $this->makeService(4000);
        $vendorUser = $service->vendor->user;

        $this->declareNoShow($service)->assertOk();

        // Sem isto, quem falta com a carteira vazia nao era penalizado — e e
        // precisamente quem falta mais vezes. A divida sai dos ganhos seguintes.
        $this->assertSame('-2000', $vendorUser->fresh()->balance);
    }

    public function test_o_tecnico_e_avisado_do_desconto(): void
    {
        $service = $this->makeService(4000);

        $this->declareNoShow($service)->assertOk();

        Notification::assertSentTo($service->vendor->user, NoShowPenaltyNotification::class);
    }

    public function test_o_servico_fica_cancelado(): void
    {
        $service = $this->makeService();

        $this->declareNoShow($service)->assertOk();

        $this->assertSame(ServiceStatus::CANCELED, $service->fresh()->status);
    }

    // --- as guardas --------------------------------------------------------

    public function test_a_mesma_falta_nao_e_cobrada_duas_vezes(): void
    {
        $service = $this->makeService(4000);
        $vendorUser = $service->vendor->user;
        $vendorUser->deposit(10000);

        $this->declareNoShow($service)->assertOk();
        $this->declareNoShow($service)->assertStatus(409);

        $this->assertSame('8000', $vendorUser->fresh()->balance);
    }

    public function test_um_servico_ja_iniciado_nao_pode_ser_dado_como_falta(): void
    {
        // A partir de ARRIVED ele esteve la: o que houver a resolver e uma
        // disputa sobre o trabalho, nao uma falta.
        $service = $this->makeService(4000, ServiceStatus::ARRIVED);

        $this->declareNoShow($service)->assertStatus(409);

        $this->assertNull($service->fresh()->vendor_no_show_at);
    }

    public function test_a_declaracao_exige_o_token_de_admin(): void
    {
        $service = $this->makeService();

        $this->postJson("/api/v1/admin/services/{$service->id}/vendor-no-show")
            ->assertStatus(401);

        $this->assertNull($service->fresh()->vendor_no_show_at);
    }
}
