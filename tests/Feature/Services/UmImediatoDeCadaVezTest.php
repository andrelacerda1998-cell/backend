<?php

namespace Tests\Feature\Services;

use App\Enums\Services\CandidateStatus;
use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Exceptions\Api\Vendor\Service\VendorIsBusy;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Schedule\Schedule;
use App\Models\Service;
use App\Models\ServiceCandidate;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Common\Services\AcceptService;
use App\Enums\Vendors\StatusVendor;
use App\Services\Matching\MatchingScope;
use App\Services\Matching\MatchingService;
use App\Services\Matching\VendorRankingService;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Um imediato de cada vez (Vendor::ocupadoAgora).
 *
 * Um pedido imediato é "podes AGORA?". Nada impedia o profissional de dizer
 * que sim a dois, nem dois clientes de escolherem o mesmo com segundos de
 * diferença: comprometia-se a estar em dois sítios à mesma hora, e o segundo
 * cliente só o descobria quando ninguém aparecesse.
 *
 * Trazido do #136 (NaoAceitaDoisAoMesmoTempoTest) sem a parte de recusar
 * sozinho os outros pedidos dele: aqui o segundo fica por responder e expira
 * pelo caminho que já existe.
 */
class UmImediatoDeCadaVezTest extends TestCase
{
    use RefreshDatabase;

    private ServicesType $tipo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(GenderSeeder::class);
        Notification::fake();
        Queue::fake();
        Event::fake();
        config(['scout.driver' => 'null', 'broadcasting.default' => 'null']);

        $this->tipo = ServicesType::factory()->create(['time' => 60]);
    }

    private function servico(Vendor $vendor, ServiceStatus $estado, array $extra = []): Service
    {
        return Service::factory()->create(array_merge([
            'customer_id' => User::factory()->create()->id,
            'vendor_id' => $vendor->id,
            'services_type_id' => $this->tipo->id,
            'status' => $estado,
            'payment_status' => PaymentStatus::PAID,
            'amount' => 4500,
            'amount_for_vendor' => 3375,
        ], $extra))->fresh();
    }

    private function agendado(Service $servico, Carbon $quando): Service
    {
        Schedule::create([
            'vendor_id' => $servico->vendor_id,
            'customer_id' => $servico->customer_id,
            'service_type_id' => $this->tipo->id,
            'service_id' => $servico->id,
            'scheduled_day' => $quando->toDateString(),
            'scheduled_time_start' => $quando->format('H:i:s'),
            'scheduled_time_end' => $quando->copy()->addHour()->format('H:i:s'),
            'is_pending' => false,
        ]);

        return $servico->fresh();
    }

    private function aceitar(Service $servico): Service
    {
        return app(AcceptService::class)->accept($servico->fresh());
    }

    // ── o pedido direto (o técnico aceita) ────────────────────────────────

    public function test_dois_pedidos_imediatos_aceita_o_primeiro_e_nao_o_segundo(): void
    {
        $vendor = Vendor::factory()->create();
        $a = $this->servico($vendor, ServiceStatus::PENDING);
        $b = $this->servico($vendor, ServiceStatus::PENDING);

        $this->aceitar($a);

        try {
            $this->aceitar($b);
            $this->fail('aceitou dois imediatos ao mesmo tempo');
        } catch (VendorIsBusy) {
        }

        $this->assertSame(ServiceStatus::ACCEPTED, $a->fresh()->status);
        $this->assertSame(ServiceStatus::PENDING, $b->fresh()->status, 'o segundo fica por responder e expira sozinho');
    }

    public function test_no_local_de_outro_trabalho_nao_aceita_um_imediato(): void
    {
        $vendor = Vendor::factory()->create();
        $this->servico($vendor, ServiceStatus::ARRIVED);

        $this->expectException(VendorIsBusy::class);
        $this->aceitar($this->servico($vendor, ServiceStatus::PENDING));
    }

    /** O GoToLocationController põe um agendado em ACCEPTED quando ele sai. */
    public function test_a_caminho_de_um_agendado_nao_aceita_um_imediato(): void
    {
        $vendor = Vendor::factory()->create();
        $this->agendado(
            $this->servico($vendor, ServiceStatus::ACCEPTED, ['on_the_way_at' => now()]),
            Carbon::now('Europe/Lisbon')->addMinutes(20),
        );

        $this->expectException(VendorIsBusy::class);
        $this->aceitar($this->servico($vendor, ServiceStatus::PENDING));
    }

    /** Um trabalho de quinta não colide com um que começa agora. */
    public function test_um_agendado_para_mais_tarde_nao_impede(): void
    {
        $vendor = Vendor::factory()->create();
        $this->agendado($this->servico($vendor, ServiceStatus::SCHEDULED), Carbon::now('Europe/Lisbon')->addDays(2));

        $b = $this->aceitar($this->servico($vendor, ServiceStatus::PENDING));

        $this->assertSame(ServiceStatus::ACCEPTED, $b->fresh()->status);
    }

    /** Concluído é livre, mesmo que o cliente ainda não tenha confirmado. */
    public function test_um_trabalho_concluido_nao_impede(): void
    {
        $vendor = Vendor::factory()->create();
        $this->servico($vendor, ServiceStatus::FINISHED);

        $b = $this->aceitar($this->servico($vendor, ServiceStatus::PENDING));

        $this->assertSame(ServiceStatus::ACCEPTED, $b->fresh()->status);
    }

    public function test_o_trabalho_de_outro_profissional_nao_conta(): void
    {
        $this->servico(Vendor::factory()->create(), ServiceStatus::ARRIVED);
        $vendor = Vendor::factory()->create();

        $b = $this->aceitar($this->servico($vendor, ServiceStatus::PENDING));

        $this->assertSame(ServiceStatus::ACCEPTED, $b->fresh()->status);
    }

    /** Um agendado não é um "agora": aceitar um não depende de estar livre já. */
    public function test_ocupado_ainda_pode_aceitar_um_agendado(): void
    {
        $vendor = Vendor::factory()->create();
        $this->servico($vendor, ServiceStatus::ARRIVED);
        $agendado = $this->agendado($this->servico($vendor, ServiceStatus::PENDING), Carbon::now('Europe/Lisbon')->addDays(2));

        $this->assertSame(ServiceStatus::ACCEPTED, $this->aceitar($agendado)->fresh()->status);
    }

    /** A app do técnico mostra a mensagem do servidor (useRequestActions). */
    public function test_a_app_recebe_409_com_a_mensagem(): void
    {
        $vendor = Vendor::factory()->create();
        $this->servico($vendor, ServiceStatus::ACCEPTED);
        $b = $this->servico($vendor, ServiceStatus::PENDING);

        // A app manda a língua no Accept-Language (HandleLocale).
        $resposta = $this->actingAs($vendor->user, 'api')
            ->withHeader('Accept-Language', 'pt-pt')
            ->postJson("/api/v1/vendor/services/{$b->id}/accept")
            ->assertStatus(409);

        $this->assertStringContainsString('Já tens um serviço a decorrer', json_encode($resposta->json(), JSON_UNESCAPED_UNICODE));
    }

    // ── a seleção (o cliente escolhe) ─────────────────────────────────────

    private function emSelecao(Vendor $vendor): ServiceCandidate
    {
        $pedido = Service::factory()->create([
            'customer_id' => User::factory()->create()->id,
            'vendor_id' => null,
            'services_type_id' => $this->tipo->id,
            'status' => ServiceStatus::MATCHING,
            'payment_status' => PaymentStatus::PENDING,
        ]);

        return ServiceCandidate::query()->create([
            'service_id' => $pedido->id,
            'vendor_id' => $vendor->id,
            'rank' => 1,
            'wave' => 1,
            'status' => CandidateStatus::ACCEPTED,
            'notified_at' => now()->subMinute(),
            'expires_at' => now()->addMinutes(2),
            'quoted_amount' => 4500,
            'quoted_amount_for_vendor' => 3375,
            'quoted_distance' => 2,
        ]);
    }

    public function test_o_cliente_nao_escolhe_quem_esta_ocupado_agora(): void
    {
        $vendor = Vendor::factory()->create();
        $this->servico($vendor, ServiceStatus::ACCEPTED);
        $candidato = $this->emSelecao($vendor);

        $this->assertFalse(app(MatchingService::class)->select($candidato));

        $this->assertSame(CandidateStatus::LOST, $candidato->fresh()->status);
        $this->assertSame(ServiceStatus::MATCHING, $candidato->service->fresh()->status, 'o cliente escolhe outro');
    }

    /** Dois clientes a escolher o mesmo com segundos de diferença. */
    public function test_escolhido_para_um_imediato_que_esta_a_ser_pago_nao_e_escolhido_outra_vez(): void
    {
        $vendor = Vendor::factory()->create();
        $primeiro = $this->emSelecao($vendor);
        $segundo = $this->emSelecao($vendor);

        $this->assertTrue(app(MatchingService::class)->select($primeiro));
        $this->assertSame(ServiceStatus::AWAITING_PAYMENT, $primeiro->service->fresh()->status);

        $this->assertFalse(app(MatchingService::class)->select($segundo));
        $this->assertSame(CandidateStatus::LOST, $segundo->fresh()->status);
    }

    public function test_livre_e_escolhido_como_antes(): void
    {
        $vendor = Vendor::factory()->create();
        $this->servico($vendor, ServiceStatus::FINISHED);
        $candidato = $this->emSelecao($vendor);

        $this->assertTrue(app(MatchingService::class)->select($candidato));
        $this->assertSame(CandidateStatus::SELECTED, $candidato->fresh()->status);
    }

    // ── os convites ───────────────────────────────────────────────────────

    /** Profissional que passa o `can_accept_service` (como no MatchingFlowTest). */
    private function pronto(): Vendor
    {
        $user = User::factory()->create([
            'is_test' => true,
            'email_verified_at' => now(),
            'phone_number_verified_at' => now(),
        ]);
        $vendor = Vendor::factory()->create([
            'user_id' => $user->id,
            'status' => StatusVendor::ONLINE,
            'iban' => 'PT50000000000000000000000',
            'invoice_workspace' => 'ws-'.$user->id,
            'at_user' => '999999999/1',
            'at_valid' => true,
            'at_validated_at' => now(),
        ]);
        $vendor->servicesTypes()->attach($this->tipo->id);

        return $vendor->fresh();
    }

    /** @return int[] ids de quem seria convidado */
    private function convidaveis(bool $imediato): array
    {
        $pedido = Service::factory()->create([
            'customer_id' => User::factory()->create(['is_test' => true])->id,
            'vendor_id' => null,
            'services_type_id' => $this->tipo->id,
            'status' => ServiceStatus::MATCHING,
        ]);

        $metodo = new ReflectionMethod(VendorRankingService::class, 'eligibleVendors');

        return $metodo->invoke(app(VendorRankingService::class), MatchingScope::forService($pedido), $pedido->customer, $imediato, null)
            ->pluck('id')->all();
    }

    /**
     * Quem foi escolhido para um imediato que está a ser pago não tem nenhum
     * serviço "aberto" (o `can_accept_service` não o tira), mas não pode vir.
     */
    public function test_quem_esta_a_ser_pago_por_outro_imediato_nao_e_convidado_para_um_imediato(): void
    {
        $livre = $this->pronto();
        $escolhido = $this->pronto();
        $this->assertTrue(app(MatchingService::class)->select($this->emSelecao($escolhido)));

        $convidaveis = $this->convidaveis(imediato: true);

        $this->assertContains($livre->id, $convidaveis);
        $this->assertNotContains($escolhido->id, $convidaveis);
        $this->assertContains($escolhido->id, $this->convidaveis(imediato: false), 'para um agendado continua a poder ser convidado');
    }
}
