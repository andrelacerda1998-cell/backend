<?php

namespace Tests\Feature;

use App\Enums\Services\ServiceStatus;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Schedule\Schedule;
use App\Models\Service;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\Vendor\NoShowPenaltyNotification;
use App\Services\Common\Services\VendorNoShowPolicy;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    // --- o que o tecnico ve e pode fazer ------------------------------------

    public function test_o_tecnico_ve_as_suas_faltas_com_o_valor_cobrado(): void
    {
        $service = $this->makeService(4000);
        $this->declareNoShow($service)->assertOk();

        $response = $this->actingAs($service->vendor->user, 'api')->getJson('/api/v1/vendor/no-shows');

        $response->assertOk();
        $this->assertCount(1, $response->json('data.no_shows'));
        $this->assertSame(2000, $response->json('data.no_shows.0.penalty'));
        $this->assertFalse($response->json('data.no_shows.0.disputed'));
    }

    public function test_contestar_abre_um_ticket_de_suporte_e_nao_reverte_nada(): void
    {
        $service = $this->makeService(4000);
        $vendorUser = $service->vendor->user;
        $this->declareNoShow($service)->assertOk();

        $this->actingAs($vendorUser, 'api')
            ->postJson("/api/v1/vendor/no-shows/{$service->id}/dispute", ['message' => 'O cliente nao estava em casa.'])
            ->assertOk();

        // Quem decide se houve engano e uma pessoa: o saldo fica como estava.
        $this->assertSame('-2000', $vendorUser->fresh()->balance);
        $this->assertSame(1, SupportTicket::where('vendor_id', $service->vendor_id)->count());
        $this->assertTrue(
            $this->actingAs($vendorUser, 'api')->getJson('/api/v1/vendor/no-shows')->json('data.no_shows.0.disputed')
        );
    }

    public function test_a_mesma_falta_nao_se_contesta_duas_vezes(): void
    {
        $service = $this->makeService(4000);
        $this->declareNoShow($service)->assertOk();
        $vendorUser = $service->vendor->user;

        $this->actingAs($vendorUser, 'api')->postJson("/api/v1/vendor/no-shows/{$service->id}/dispute", ['message' => 'x'])->assertOk();
        $this->actingAs($vendorUser, 'api')->postJson("/api/v1/vendor/no-shows/{$service->id}/dispute", ['message' => 'x'])->assertStatus(409);
    }

    public function test_nao_se_contesta_a_falta_de_outro_tecnico(): void
    {
        $service = $this->makeService(4000);
        $this->declareNoShow($service)->assertOk();
        $outro = Vendor::factory()->create();

        $this->actingAs($outro->user, 'api')
            ->postJson("/api/v1/vendor/no-shows/{$service->id}/dispute", ['message' => 'x'])
            ->assertStatus(404);
    }

    // --- a fila do backoffice --------------------------------------------------

    public function test_o_backoffice_ve_as_suspeitas_e_as_declaradas(): void
    {
        // Suspeita: marcado para ha 2 horas e nunca chegou a "Cheguei".
        $suspeito = $this->makeService(4000);
        Schedule::query()->create([
            'vendor_id' => $suspeito->vendor_id,
            'customer_id' => $suspeito->customer_id,
            'service_type_id' => $suspeito->services_type_id,
            'service_id' => $suspeito->id,
            'scheduled_day' => Carbon::now('Europe/Lisbon')->subHours(2)->toDateString(),
            'scheduled_time_start' => Carbon::now('Europe/Lisbon')->subHours(2)->format('H:i:s'),
            'scheduled_time_end' => Carbon::now('Europe/Lisbon')->subHour()->format('H:i:s'),
            'is_pending' => false,
        ]);

        $declarado = $this->makeService(3000);
        $this->declareNoShow($declarado)->assertOk();

        $response = $this->withHeaders(['Authorization' => 'Bearer token-de-teste'])
            ->getJson('/api/v1/admin/services/vendor-no-shows');

        $response->assertOk();
        $this->assertSame([$suspeito->id], array_column($response->json('data.suspected'), 'service_id'));
        $this->assertSame(2000, $response->json('data.suspected.0.penalty_if_declared'));
        $this->assertSame([$declarado->id], array_column($response->json('data.declared'), 'service_id'));
        $this->assertSame(1500, $response->json('data.declared.0.vendor_no_show_penalty'));
    }

    // --- o caminho que faltava: falta com o servico ja ACEITE ---------------

    public function test_um_servico_aceite_tambem_e_cancelado_e_reembolsado(): void
    {
        // "Marcou A caminho e nunca apareceu": o cliente pagou e continua a
        // pagar se o servico nao for cancelado.
        $service = $this->makeService(4000, ServiceStatus::ACCEPTED);
        $vendorUser = $service->vendor->user;
        $vendorUser->deposit(10000);

        $this->declareNoShow($service)->assertOk();

        $fresh = $service->fresh();
        $this->assertSame(2000, $fresh->vendor_no_show_penalty);
        $this->assertSame('8000', $vendorUser->fresh()->balance);
        // O reembolso ao cliente vive no ServiceObserver e so dispara quando o
        // servico passa a CANCELED. Se ficar em ACCEPTED, o tecnico e
        // penalizado E o cliente fica cobrado por um servico que ninguem fez.
        $this->assertSame(ServiceStatus::CANCELED, $fresh->status);
    }

    public function test_uma_falta_com_o_tecnico_a_caminho_nao_cobra_o_cliente(): void
    {
        // A ARMADILHA: `cancelOpenService()` — o cancelamento normal de um
        // servico aberto — quando o tecnico ja marcou "a caminho" COBRA 100% ao
        // cliente e da metade ao tecnico (CancellationPolicy). E a regra certa
        // para o CLIENTE que desiste em cima da hora, e o oposto do que se quer
        // numa falta. Este teste existe para quem "arranjar" isto a seguir.
        $service = $this->makeService(4000, ServiceStatus::ACCEPTED);
        $service->forceFill(['on_the_way_at' => now()->subMinutes(30)])->save();
        $vendorUser = $service->vendor->user;
        $vendorUser->deposit(10000);

        $this->declareNoShow($service)->assertOk();

        // So o debito da penalizacao: 10000 - 2000. Se o cliente tivesse sido
        // cobrado, o tecnico teria RECEBIDO metade por cima disto.
        $this->assertSame('8000', $vendorUser->fresh()->balance);
        $this->assertSame(ServiceStatus::CANCELED, $service->fresh()->status);
        $this->assertFalse($service->fresh()->skipCancellationRefund);
    }
}
