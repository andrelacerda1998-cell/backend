<?php

namespace Tests\Feature\Services;

use App\Enums\Services\AddressType;
use App\Enums\Services\CandidateStatus;
use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Enums\Vendors\StatusVendor;
use App\Models\Address;
use App\Models\GeneralSettings\OperationArea;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Vendor\Location;
use App\Services\InvoiceXpress\InvoiceVendorService;
use App\Services\Matching\MatchingService;
use App\Services\Matching\PlanoDeVisitas;
use App\Settings\MatchingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Cesto como encomenda: uma visita por técnico, várias linhas por visita.
 *
 * O cenário tem três técnicos que fazem canalização E eletricidade, um que
 * só faz canalização e uma que só faz limpezas — o retrato de Lisboa a
 * 06/10/2026, em pequeno.
 */
class EncomendaTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private ServicesType $cano;      // canalização, 60 min

    private ServicesType $torneira;  // canalização, 30 min

    private ServicesType $tomada;    // eletricidade, 45 min

    private ServicesType $limpeza;   // limpezas, 90 min

    private ServicesType $ninguem;   // nenhum técnico o faz

    /** @var Vendor[] */
    private array $generalistas = [];

    private Vendor $soCanalizador;

    private Vendor $soLimpeza;

    protected function setUp(): void
    {
        parent::setUp();

        MatchingSettings::fake([
            'shortlist_size' => 3,
            'wave_size' => 6,
            'wave_interval_seconds' => 45,
            'max_waves' => 3,
            'vendor_response_seconds_immediate' => 120,
            'vendor_response_seconds_scheduled' => 120,
            'customer_choice_seconds' => 300,
            'customer_choice_seconds_scheduled' => 300,
            'checkout_seconds' => 300,
            'rating_bands' => [4.5, 4.0, 3.0],
            'new_vendor_min_ratings' => 5,
            'require_recent_activity_minutes' => 15,
            'customer_choice_seconds_custom' => 3600,
            'request_deadline_seconds' => 600,
        ]);

        // Só os eventos que difundem. Um Event::fake() sem lista desliga
        // também os eventos dos modelos — e com eles o ServiceObserver, que é
        // quem fecha a encomenda quando a última visita fecha.
        Event::fake([
            \App\Events\Matching\MatchingInvitationEvent::class,
            \App\Events\Matching\MatchingCandidateAcceptedEvent::class,
            \App\Events\Matching\MatchingRequestClosedEvent::class,
            \App\Events\Matching\MatchingCandidateLostEvent::class,
            \App\Events\Customer\Schedule\AcceptScheduleEvent::class,
            \App\Events\Vendor\Schedule\CreateScheduleEvent::class,
            \App\Events\Vendor\Schedule\ServiceScheduledEvent::class,
            \App\Events\Vendor\Services\CreateServiceEvent::class,
            \App\Events\Common\Services\ServiceAcceptedEvent::class,
        ]);
        Notification::fake();
        config(['broadcasting.default' => 'null']);

        $canalizacao = OperationArea::factory()->create();
        $eletricidade = OperationArea::factory()->create();
        $limpezas = OperationArea::factory()->create();

        $this->cano = ServicesType::factory()->create(['operation_area_id' => $canalizacao->id, 'time' => 60]);
        $this->torneira = ServicesType::factory()->create(['operation_area_id' => $canalizacao->id, 'time' => 30]);
        $this->tomada = ServicesType::factory()->create(['operation_area_id' => $eletricidade->id, 'time' => 45]);
        $this->limpeza = ServicesType::factory()->create(['operation_area_id' => $limpezas->id, 'time' => 90]);
        $this->ninguem = ServicesType::factory()->create(['operation_area_id' => $limpezas->id, 'time' => 30]);

        $this->customer = User::factory()->create(['is_test' => true]);
        $this->morada($this->customer, AddressType::HOUSE_ADDRESS, principal: true);

        foreach ([20, 22, 24] as $i => $taxa) {
            $this->generalistas[] = $this->tecnico("Generalista {$i}", $taxa, [$this->cano, $this->torneira, $this->tomada]);
        }

        $this->soCanalizador = $this->tecnico('Só canalização', 21, [$this->cano, $this->torneira]);
        $this->soLimpeza = $this->tecnico('Só limpezas', 15, [$this->limpeza]);
    }

    // ------------------------------------------------------------- plano

    public function test_dois_servicos_que_tres_tecnicos_fazem_juntos_sao_uma_visita(): void
    {
        $plano = $this->plano([$this->cano, $this->tomada]);

        $this->assertCount(1, $plano['visits']);
        $this->assertSame(3, $plano['visits'][0]['eligible_vendors']);
        $this->assertNotNull($plano['visits'][0]['from_price']);
        $this->assertSame([], $plano['unavailable']);
    }

    public function test_servicos_sem_tecnico_em_comum_dividem_se_em_visitas(): void
    {
        $plano = $this->plano([$this->cano, $this->limpeza]);

        $this->assertCount(2, $plano['visits']);
        $this->assertEqualsCanonicalizing(
            PlanoDeVisitas::assinatura([[$this->cano->id], [$this->limpeza->id]]),
            PlanoDeVisitas::assinatura(array_map(fn ($v) => array_column($v['items'], 'service_type_id'), $plano['visits'])),
        );
    }

    public function test_um_servico_sem_ninguem_na_zona_vai_para_indisponiveis(): void
    {
        $plano = $this->plano([$this->cano, $this->ninguem]);

        $this->assertSame([$this->ninguem->id], $plano['unavailable']);
        $this->assertCount(1, $plano['visits']);
    }

    /**
     * A canalização tem quatro técnicos; só dois fazem também eletricidade.
     * Juntar deixava a canalização com dois — perde escolha — por isso divide.
     */
    public function test_nao_se_junta_quando_um_dos_lados_perde_escolha(): void
    {
        $this->generalistas[2]->servicesTypes()->detach($this->tomada->id);

        $plano = $this->plano([$this->cano, $this->tomada]);

        $this->assertCount(2, $plano['visits']);
    }

    /** Só dois fazem cada um e os mesmos dois fazem os dois: não há escolha a perder. */
    public function test_junta_quando_os_dois_lados_ja_tinham_menos_de_tres(): void
    {
        $this->generalistas[2]->servicesTypes()->detach([$this->cano->id, $this->tomada->id]);
        $this->soCanalizador->servicesTypes()->detach($this->cano->id);

        $plano = $this->plano([$this->cano, $this->tomada]);

        $this->assertCount(1, $plano['visits']);
        $this->assertSame(2, $plano['visits'][0]['eligible_vendors']);
    }

    // ------------------------------------------------------------- pedir

    public function test_pedir_cria_uma_visita_com_as_linhas_e_convida_so_quem_faz_tudo(): void
    {
        $data = $this->pedir([$this->cano, $this->tomada], [[$this->cano->id, $this->tomada->id]])
            ->assertOk()->json('data');

        $order = ServiceOrder::findOrFail($data['order']['id']);
        $this->assertCount(1, $order->visits);

        $visita = $order->visits->first();
        $this->assertSame(ServiceStatus::MATCHING, $visita->status);
        // O principal é o que leva mais tempo.
        $this->assertSame($this->cano->id, (int) $visita->services_type_id);
        $this->assertSame(105, $visita->durationMinutes());
        $this->assertCount(2, $visita->items);

        $convidados = $visita->candidates()->pluck('vendor_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $this->assertSame(collect($this->generalistas)->pluck('id')->sort()->values()->all(), $convidados);

        $this->assertCount(2, $data['visits'][0]['service']['items']);
    }

    /** A visita cota os minutos somados: mais cara do que só o serviço maior. */
    public function test_a_visita_e_cotada_pelos_minutos_somados(): void
    {
        $junta = $this->pedir([$this->cano, $this->tomada], [[$this->cano->id, $this->tomada->id]])->json('data.visits.0.service.id');
        $tecnico = $this->generalistas[0]->id;
        $cotaJunta = (int) Service::find($junta)->candidates()->where('vendor_id', $tecnico)->value('quoted_amount');

        $sozinha = $this->actingAs($this->customer, 'api')
            ->postJson('/api/v1/customer/services/matching', ['service_type' => $this->cano->id])
            ->json('data.service.id');
        $cotaSozinha = (int) Service::find($sozinha)->candidates()->where('vendor_id', $tecnico)->value('quoted_amount');

        $this->assertGreaterThan($cotaSozinha, $cotaJunta);
    }

    public function test_se_o_plano_mudou_devolve_o_novo_e_nao_cria_nada(): void
    {
        $this->pedir([$this->cano, $this->tomada], [[$this->cano->id], [$this->tomada->id]])
            ->assertStatus(409)
            ->assertJsonCount(1, 'data.plan.visits');

        $this->assertSame(0, ServiceOrder::count());
        // Deste cliente: há um teste noutro ficheiro que deixa um serviço
        // para trás na base, e contar todos dependia da ordem da suite.
        $this->assertSame(0, Service::where('customer_id', $this->customer->id)->count());
    }

    public function test_um_servico_indisponivel_tambem_e_409(): void
    {
        $this->pedir([$this->cano, $this->ninguem], [[$this->cano->id], [$this->ninguem->id]])
            ->assertStatus(409)
            ->assertJsonPath('data.plan.unavailable', [$this->ninguem->id]);

        $this->assertSame(0, ServiceOrder::count());
    }

    public function test_visitas_de_um_servico_sao_pedidos_como_os_de_hoje(): void
    {
        $data = $this->pedir([$this->cano, $this->limpeza], [[$this->cano->id], [$this->limpeza->id]])
            ->assertOk()->json('data');

        $visitas = ServiceOrder::findOrFail($data['order']['id'])->visits;
        $this->assertCount(2, $visitas);

        foreach ($visitas as $visita) {
            $this->assertCount(0, $visita->items);
        }

        $limpeza = $visitas->firstWhere('services_type_id', $this->limpeza->id);
        $this->assertSame([$this->soLimpeza->id], $limpeza->candidates()->pluck('vendor_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_um_pedido_avulso_nao_substitui_as_visitas_de_um_cesto(): void
    {
        $orderId = $this->pedir([$this->cano, $this->tomada], [[$this->cano->id, $this->tomada->id]])->json('data.order.id');

        $this->actingAs($this->customer, 'api')
            ->postJson('/api/v1/customer/services/matching', ['service_type' => $this->limpeza->id])
            ->assertOk();

        $this->assertSame(ServiceStatus::MATCHING, ServiceOrder::find($orderId)->visits->first()->status);
    }

    // ------------------------------------------------------------- técnico

    public function test_o_convite_mostra_todos_os_servicos_da_visita(): void
    {
        $this->pedir([$this->cano, $this->tomada], [[$this->cano->id, $this->tomada->id]])->assertOk();

        $convite = $this->actingAs($this->generalistas[0]->user, 'api')
            ->getJson('/api/v1/vendor/services/matching')
            ->assertOk()
            ->json('data.0');

        $this->assertCount(2, $convite['items']);
        $this->assertSame(105, $convite['duration_minutes']);
        $this->assertNotNull($convite['title']);
    }

    // ------------------------------------------------------------- pagar e fechar

    public function test_a_visita_e_paga_pelo_caminho_de_sempre_e_a_encomenda_fecha_com_ela(): void
    {
        $data = $this->pedir([$this->cano, $this->tomada], [[$this->cano->id, $this->tomada->id]])->json('data');
        $visita = Service::find($data['visits'][0]['service']['id']);
        $order = ServiceOrder::find($data['order']['id']);

        $candidato = $visita->candidates()->orderBy('rank')->first();
        $cota = (int) $candidato->quoted_amount;
        app(MatchingService::class)->accept($candidato);

        $this->actingAs($this->customer, 'api')
            ->postJson("/api/v1/customer/services/matching/{$visita->id}/select/{$candidato->id}")
            ->assertOk();
        $this->actingAs($this->customer, 'api')
            ->postJson("/api/v1/customer/services/matching/{$visita->id}/checkout")
            ->assertOk();

        $visita->refresh();
        $this->assertSame($cota, $visita->amount);
        $this->assertSame(PaymentStatus::PAID, $visita->payment_status);
        $this->assertSame(ServiceOrder::ABERTA, $order->refresh()->status);

        $visita->update(['status' => ServiceStatus::CLOSED]);

        $this->assertSame(ServiceOrder::CONCLUIDA, $order->refresh()->status);
    }

    public function test_cancelar_a_encomenda_so_cancela_o_que_ainda_nao_tem_pagamento(): void
    {
        $data = $this->pedir([$this->cano, $this->limpeza], [[$this->cano->id], [$this->limpeza->id]])->json('data');
        $order = ServiceOrder::find($data['order']['id']);
        [$primeira, $segunda] = $order->visits->all();

        // A segunda já tem técnico e um pagamento a meio.
        $segunda->forceFill(['status' => ServiceStatus::AWAITING_PAYMENT, 'payment_order_id' => $this->ordemDePagamento($segunda)])->save();

        $this->actingAs($this->customer, 'api')
            ->postJson("/api/v1/customer/services/matching/orders/{$order->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.not_canceled', [$segunda->id]);

        $this->assertSame(ServiceStatus::CANCELED, $primeira->refresh()->status);
        $this->assertSame(ServiceStatus::AWAITING_PAYMENT, $segunda->refresh()->status);
        $this->assertSame(ServiceOrder::ABERTA, $order->refresh()->status);
    }

    public function test_so_o_proprio_cliente_ve_e_cancela_a_encomenda(): void
    {
        $orderId = $this->pedir([$this->cano], [[$this->cano->id]])->json('data.order.id');
        $outro = User::factory()->create(['is_test' => true]);

        $this->actingAs($outro, 'api')->getJson("/api/v1/customer/services/matching/orders/{$orderId}")->assertStatus(404);
        $this->actingAs($outro, 'api')->postJson("/api/v1/customer/services/matching/orders/{$orderId}/cancel")->assertStatus(404);
    }

    // ------------------------------------------------------------- fatura

    public function test_a_fatura_lista_todos_os_servicos_numa_linha(): void
    {
        $id = $this->pedir([$this->cano, $this->tomada], [[$this->cano->id, $this->tomada->id]])->json('data.visits.0.service.id');
        $visita = Service::find($id);

        $descricao = InvoiceVendorService::descricaoDoServico($visita);

        $this->assertStringStartsWith('Serviços: ', $descricao);
        $this->assertStringContainsString($this->cano->getTranslation('name', 'pt-pt'), $descricao);
        $this->assertStringContainsString($this->tomada->getTranslation('name', 'pt-pt'), $descricao);
    }

    public function test_a_fatura_de_um_servico_so_fica_como_estava(): void
    {
        $id = $this->actingAs($this->customer, 'api')
            ->postJson('/api/v1/customer/services/matching', ['service_type' => $this->cano->id])
            ->json('data.service.id');

        $this->assertSame(
            'Serviço: '.$this->cano->getTranslation('name', 'pt-pt'),
            InvoiceVendorService::descricaoDoServico(Service::find($id)),
        );
    }

    // ------------------------------------------------------------- ajudantes

    private function plano(array $tipos): array
    {
        return $this->actingAs($this->customer, 'api')
            ->postJson('/api/v1/common/services/orders/plan', [
                'items' => array_map(fn (ServicesType $t) => ['service_type_id' => $t->id], $tipos),
                'latitude' => 41.1478,
                'longitude' => -8.6110,
                'city' => 'Porto',
            ])
            ->assertOk()
            ->json('data');
    }

    private function pedir(array $tipos, array $visitasEsperadas)
    {
        return $this->actingAs($this->customer, 'api')
            ->postJson('/api/v1/customer/services/matching/orders', [
                'items' => array_map(fn (ServicesType $t) => ['service_type_id' => $t->id], $tipos),
                'expected_visits' => $visitasEsperadas,
            ]);
    }

    private function ordemDePagamento(Service $service): int
    {
        return \RwInteractive\PayshopSdk\Models\PaymentOrder::query()->create([
            'user_id' => $service->customer_id,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'amount' => 5000,
            'paid' => false,
            'status' => \RwInteractive\PayshopSdk\Enums\Payment\Status::CREATED,
            'type' => \RwInteractive\PayshopSdk\Enums\Payment\OperationType::DEFERRED,
            'refunded' => 0,
            'service' => 'piquet-testes',
            'service_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => (string) \Illuminate\Support\Str::uuid(),
            'ip' => '127.0.0.1',
        ])->id;
    }

    private function morada(User $user, $tipo, bool $principal = false): Address
    {
        return Address::create([
            'user_id' => $user->id,
            'name' => 'Casa',
            'street_name' => 'Rua de Exemplo',
            'street_number' => '1',
            'postal_code' => '4000-000',
            'city' => 'Porto',
            'municipality' => 'Porto',
            'state' => 'Porto',
            'country' => 'Portugal',
            'latitude' => 41.1478,
            'longitude' => -8.6110,
            'main_address' => $principal,
            'address_type' => $tipo,
        ]);
    }

    /** Técnico com o onboarding completo (ver MatchingFlowTest::makeVendor). */
    private function tecnico(string $nome, int $taxa, array $tipos): Vendor
    {
        $user = User::factory()->create([
            'name' => $nome,
            'is_test' => true,
            'email_verified_at' => now(),
            'phone_number_verified_at' => now(),
        ]);

        $vendor = Vendor::factory()->create([
            'user_id' => $user->id,
            'price_rate' => $taxa,
            'status' => StatusVendor::ONLINE,
            'iban' => 'PT50000000000000000000000',
            'invoice_workspace' => 'ws-'.$user->id,
            'at_user' => '999999999/1',
            'at_valid' => true,
            'at_validated_at' => now(),
        ]);

        foreach ($tipos as $tipo) {
            $vendor->operationAreas()->syncWithoutDetaching([$tipo->operation_area_id]);
            $vendor->servicesTypes()->attach($tipo->id);
        }

        $vendor->currentLocation()->save(new Location(['latitude' => 41.15, 'longitude' => -8.61]));
        $this->morada($user, AddressType::SCHEDULE_ADDRESS);
        $vendor->scheduleAvailable()->update(['auto_accept' => false]);

        return $vendor->fresh();
    }
}
