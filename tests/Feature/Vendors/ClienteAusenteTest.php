<?php

namespace Tests\Feature\Vendors;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\Admin\ServiceProblemOpsNotification;
use App\Notifications\Customer\VendorCantFindCustomerNotification;
use App\Notifications\Vendor\ServiceProblemReportedNotification;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * "Cliente não está": o técnico chegou e não encontra ninguém.
 *
 * Não cobra nem cancela nada sozinho. Avisa o cliente na hora (resolve muitos
 * casos) e põe o caso nas mãos do backoffice, com o fecho automático parado.
 */
class ClienteAusenteTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GenderSeeder::class);
        Notification::fake();
        Role::findOrCreate('admin');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
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
        ], $extra));
    }

    private function reportar(Service $service, ?User $quem = null)
    {
        return $this->actingAs($quem ?? $service->vendor->user, 'api')
            ->postJson("/api/v1/vendor/services/{$service->id}/customer-absent", ['message' => 'Toquei três vezes.']);
    }

    public function test_no_local_avisa_o_cliente_e_o_backoffice_sem_cobrar_nada(): void
    {
        $service = $this->servico(ServiceStatus::ARRIVED, ['on_the_way_at' => now()->subMinutes(30)]);

        $this->reportar($service)
            ->assertOk()
            ->assertJsonPath('data.service.problem_reason', 'customer_absent')
            ->assertJsonPath('data.service.problem_reported_by', 'vendor');

        $service->refresh();
        $this->assertSame(ServiceStatus::ARRIVED, $service->status, 'nada se cancela nem se cobra sozinho');
        $this->assertSame(PaymentStatus::PAID, $service->payment_status);
        Notification::assertSentTo($service->customer, VendorCantFindCustomerNotification::class);
        Notification::assertSentTo($this->admin, ServiceProblemOpsNotification::class);
        // Foi ele que reportou: não se lhe manda o aviso de "o cliente reportou".
        Notification::assertNotSentTo($service->vendor->user, ServiceProblemReportedNotification::class);
    }

    public function test_a_caminho_tambem_pode(): void
    {
        $service = $this->servico(ServiceStatus::ACCEPTED, ['on_the_way_at' => now()->subMinutes(20)]);

        $this->reportar($service)->assertOk();
    }

    public function test_ainda_sem_sair_de_casa_nao_pode(): void
    {
        $service = $this->servico(ServiceStatus::ACCEPTED, ['on_the_way_at' => null]);

        $this->reportar($service)->assertStatus(409);
        $this->assertNull($service->fresh()->problem_reported_at);
    }

    public function test_so_o_tecnico_do_servico(): void
    {
        $service = $this->servico(ServiceStatus::ARRIVED, ['on_the_way_at' => now()]);

        $this->reportar($service, Vendor::factory()->create()->user)->assertStatus(404);
    }

    public function test_repetir_nao_volta_a_tocar_ao_cliente(): void
    {
        $service = $this->servico(ServiceStatus::ARRIVED, ['on_the_way_at' => now()]);

        $this->reportar($service)->assertOk();
        $this->reportar($service)->assertOk();

        Notification::assertSentToTimes($service->customer, VendorCantFindCustomerNotification::class, 1);
    }
}
