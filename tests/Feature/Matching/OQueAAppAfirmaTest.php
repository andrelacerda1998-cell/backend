<?php

namespace Tests\Feature\Matching;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Service;
use App\Models\ServiceCandidate;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\Vendor\MatchingInvitationNotification;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * O que a app afirma e o sistema tem de garantir.
 */
class OQueAAppAfirmaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GenderSeeder::class);
        Notification::fake();
    }

    /**
     * O convite só tinha canal `expo`. O trait das preferências diz que
     * silencia o push e mantém o histórico — mas não havia histórico nenhum
     * para manter. Quem desligasse "Novos pedidos" ficava sem aviso E sem
     * registo, sem forma de saber que tinha havido trabalho para ele.
     */
    public function test_o_convite_deixa_registo_mesmo_com_os_avisos_desligados(): void
    {
        $vendor = Vendor::factory()->create(['notification_preferences' => ['new_requests' => false]]);
        $service = Service::factory()->create(['services_type_id' => ServicesType::factory()]);
        $candidate = ServiceCandidate::query()->create([
            'service_id' => $service->id,
            'vendor_id' => $vendor->id,
            'rank' => 1,
            'wave' => 1,
            'expires_at' => now()->addMinute(),
        ]);

        $canais = (new MatchingInvitationNotification($candidate))->via($vendor->user);

        $this->assertNotContains('expo', $canais, 'o push fica silenciado, como ele pediu');
        $this->assertContains('database', $canais, 'mas o convite tem de ficar no histórico dele');
    }

    public function test_com_os_avisos_ligados_o_convite_vai_pelos_dois(): void
    {
        $vendor = Vendor::factory()->create(['notification_preferences' => ['new_requests' => true]]);
        $service = Service::factory()->create(['services_type_id' => ServicesType::factory()]);
        $candidate = ServiceCandidate::query()->create([
            'service_id' => $service->id,
            'vendor_id' => $vendor->id,
            'rank' => 1,
            'wave' => 1,
            'expires_at' => now()->addMinute(),
        ]);

        $canais = (new MatchingInvitationNotification($candidate))->via($vendor->user);

        $this->assertEqualsCanonicalizing(['expo', 'database'], $canais);
    }

    /**
     * O ecrã de acompanhamento decidia o texto só pelo estado e escrevia
     * "{nome} está a caminho" três segundos depois do pagamento. Não podia
     * fazer melhor: o payload não trazia os dois carimbos que dizem se ele
     * saiu mesmo — até os componentes que já os liam os recebiam vazios.
     */
    public function test_o_cliente_passa_a_saber_se_o_tecnico_ja_saiu(): void
    {
        $customer = User::factory()->create();
        $service = Service::factory()->create([
            'customer_id' => $customer->id,
            'vendor_id' => Vendor::factory(),
            'services_type_id' => ServicesType::factory(),
            'status' => ServiceStatus::ACCEPTED,
            'payment_status' => PaymentStatus::PAID,
        ]);

        $dados = $this->actingAs($customer, 'api')
            ->getJson("/api/v1/customer/services/{$service->id}")
            ->assertSuccessful()
            ->json('data.service');

        $this->assertArrayHasKey('on_the_way_at', $dados);
        $this->assertArrayHasKey('arrived_at', $dados);
        $this->assertNull($dados['on_the_way_at'], 'aceite mas ainda parado: não saiu');

        $service->forceFill(['on_the_way_at' => Carbon::now()])->save();

        $depois = $this->actingAs($customer, 'api')
            ->getJson("/api/v1/customer/services/{$service->id}")
            ->json('data.service');

        $this->assertNotNull($depois['on_the_way_at'], 'agora sim, e só agora é que se pode dizer "a caminho"');
    }
}
