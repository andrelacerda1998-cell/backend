<?php

namespace Tests\Feature\Matching;

use App\Enums\Services\AddressType;
use App\Enums\Services\CandidateStatus;
use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Enums\Vendors\StatusVendor;
use App\Events\Matching\MatchingCandidateAcceptedEvent;
use App\Events\Matching\MatchingCandidateLostEvent;
use App\Events\Matching\MatchingInvitationEvent;
use App\Events\Matching\MatchingRequestClosedEvent;
use App\Models\Address;
use App\Models\GeneralSettings\OperationArea;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Service;
use App\Models\ServiceCandidate;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Vendor\Location;
use App\Services\Matching\MatchingScope;
use App\Services\Matching\MatchingService;
use App\Services\RateService;
use App\Settings\MatchingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Pedido personalizado: o cliente descreve, o backoffice define tempo e
 * categorias, e so entao o matching arranca (ver docs/matching.md).
 *
 * O que se prova aqui e a fronteira nova: nada sai antes do backoffice; quando
 * sai, vai a quem e das categorias escolhidas, com o preco feito pela duracao
 * definida; e o relogio do pedido so comeca nesse momento.
 */
class PedidoPersonalizadoTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private OperationArea $canalizacao;

    private OperationArea $eletricidade;

    /** So canalizacao. */
    private Vendor $canalizador;

    /** So eletricidade. */
    private Vendor $eletricista;

    /** As duas. */
    private Vendor $faztudo;

    protected function setUp(): void
    {
        parent::setUp();

        MatchingSettings::fake([
            'shortlist_size' => 3,
            'wave_size' => 6,
            'wave_interval_seconds' => 45,
            'max_waves' => 3,
            'vendor_response_seconds_immediate' => 60,
            'vendor_response_seconds_scheduled' => 1800,
            'customer_choice_seconds' => 120,
            'customer_choice_seconds_scheduled' => 1800,
            'checkout_seconds' => 300,
            'request_deadline_seconds' => 180,
            'rating_bands' => [4.5, 4.0, 3.0],
            'new_vendor_min_ratings' => 5,
            'require_recent_activity_minutes' => 15,
            'customer_choice_seconds_custom' => 3600,
        ]);

        Event::fake([
            MatchingInvitationEvent::class,
            MatchingCandidateAcceptedEvent::class,
            MatchingRequestClosedEvent::class,
            MatchingCandidateLostEvent::class,
        ]);
        Notification::fake();
        config(['broadcasting.default' => 'null']);

        $this->canalizacao = OperationArea::factory()->create();
        $this->eletricidade = OperationArea::factory()->create();
        $tipoCanalizacao = ServicesType::factory()->create(['operation_area_id' => $this->canalizacao->id, 'time' => 60]);
        $tipoEletricidade = ServicesType::factory()->create(['operation_area_id' => $this->eletricidade->id, 'time' => 60]);

        $this->customer = User::factory()->create(['is_test' => true]);
        $this->makeAddress($this->customer, AddressType::HOUSE_ADDRESS, main: true);

        $this->canalizador = $this->makeVendor('Canalizador', 20, [$this->canalizacao], [$tipoCanalizacao]);
        $this->eletricista = $this->makeVendor('Eletricista', 22, [$this->eletricidade], [$tipoEletricidade]);
        $this->faztudo = $this->makeVendor('Faz-tudo', 24, [$this->canalizacao, $this->eletricidade], [$tipoCanalizacao, $tipoEletricidade]);
    }

    // ------------------------------------------------------------------ abrir

    public function test_abrir_um_pedido_personalizado_fica_em_analise_e_nao_convida_ninguem(): void
    {
        $response = $this->abrir('Trocar a fechadura da porta da rua e ligar um candeeiro na sala.')->assertOk();

        $service = Service::findOrFail($response->json('data.service.id'));

        $this->assertSame(ServiceStatus::PENDING_REVIEW, $service->status);
        $this->assertTrue($service->is_custom);
        $this->assertNull($service->services_type_id);
        $this->assertSame('Trocar a fechadura da porta da rua e ligar um candeeiro na sala.', $service->custom_description);
        $this->assertSame(PaymentStatus::PENDING, $service->payment_status);
        $this->assertNull($service->amount, 'sem tipo nem duracao nao ha preco');

        // A parte que importa: ninguem soube que isto existe.
        $this->assertSame(0, ServiceCandidate::where('service_id', $service->id)->count());
        Event::assertNotDispatched(MatchingInvitationEvent::class);

        $this->assertTrue($response->json('data.service.is_custom'));
        $this->assertSame([], $response->json('data.candidates'));
    }

    public function test_uma_descricao_curta_de_mais_e_recusada(): void
    {
        $this->abrir('abc')->assertStatus(422);
        $this->assertSame(0, Service::count());
    }

    public function test_um_segundo_pedido_devolve_o_que_ja_esta_em_analise(): void
    {
        $primeiro = $this->abrir('Montar um movel da IKEA que veio em tres caixas.')->json('data.service.id');
        $segundo = $this->abrir('Outra coisa qualquer, com dez letras.')->assertOk()->json('data.service.id');

        $this->assertSame($primeiro, $segundo);
        $this->assertSame(1, Service::where('customer_id', $this->customer->id)->count());
    }

    // --------------------------------------------------------------- enviar

    public function test_o_backoffice_envia_so_a_quem_e_das_categorias_e_o_preco_vem_da_duracao(): void
    {
        $service = $this->personalizadoEmAnalise();

        // O que a accao do backoffice escreve antes de despachar.
        $this->definirEEnviar($service, minutos: 90, areas: [$this->canalizacao]);

        $candidates = app(MatchingService::class)->dispatchNextWave($service->refresh());

        $convidados = $candidates->pluck('vendor_id')->sort()->values()->all();
        $this->assertSame(
            collect([$this->canalizador->id, $this->faztudo->id])->sort()->values()->all(),
            $convidados,
            'so quem faz algum tipo de canalizacao; o eletricista fica de fora'
        );
        $this->assertNotContains($this->eletricista->id, $convidados);

        // O preco de cada um vem dos 90 minutos definidos, nao de um tipo de
        // catalogo (que nao existe). Mesma conta do pricing, minutos dados.
        $candidato = $candidates->firstWhere('vendor_id', $this->canalizador->id);
        $rate = app(RateService::class);

        // Um personalizado e despachado como IMEDIATO, e num imediato o premio
        // entra no valor do trabalho: o profissional recebe sobre ele. Este
        // teste fixava `calculateForVendor` e por isso descrevia a regra
        // antiga, em que o cliente pagava o premio e o profissional recebia o
        // mesmo que receberia por um agendado.
        $esperadoParaVendor = (int) round($rate->calculateForVendorInstantService(
            $this->canalizador->getRawOriginal('price_rate'),
            90,
            (float) $candidato->quoted_distance,
        ));
        $this->assertSame($esperadoParaVendor, (int) $candidato->quoted_amount_for_vendor);
        $this->assertGreaterThan(0, (int) $candidato->quoted_amount);
        $this->assertGreaterThan((int) $candidato->quoted_amount_for_vendor, (int) $candidato->quoted_amount, 'o cliente paga a margem por cima');

        Event::assertDispatchedTimes(MatchingInvitationEvent::class, 2);
    }

    public function test_com_duas_categorias_vai_a_quem_faz_qualquer_uma_delas(): void
    {
        $service = $this->personalizadoEmAnalise();
        $this->definirEEnviar($service, minutos: 60, areas: [$this->canalizacao, $this->eletricidade]);

        $convidados = app(MatchingService::class)->dispatchNextWave($service->refresh())->pluck('vendor_id')->sort()->values()->all();

        $this->assertSame(
            collect([$this->canalizador->id, $this->eletricista->id, $this->faztudo->id])->sort()->values()->all(),
            $convidados,
        );
    }

    public function test_sem_duracao_ou_categorias_o_scope_recusa_em_vez_de_cotar_a_zero(): void
    {
        $service = $this->personalizadoEmAnalise();
        $service->update(['status' => ServiceStatus::MATCHING]); // sem duracao, sem areas

        $this->expectException(\LogicException::class);
        MatchingScope::forService($service->refresh());
    }

    // ---------------------------------------------------------------- prazo

    public function test_o_prazo_conta_do_envio_pelo_backoffice_e_nao_da_criacao(): void
    {
        // Criado ha dois dias — se o prazo contasse daqui, os 180 s ja tinham
        // passado e todos os convites nasciam mortos.
        $service = $this->personalizadoEmAnalise();
        Service::whereKey($service->id)->update(['created_at' => now()->subDays(2)]);

        $this->definirEEnviar($service, minutos: 60, areas: [$this->canalizacao]);
        $service->refresh();

        $this->assertTrue($service->matchingStartedAt()->equalTo($service->custom_dispatched_at));

        $candidates = app(MatchingService::class)->dispatchNextWave($service);

        $this->assertGreaterThan(0, $candidates->count());
        foreach ($candidates as $c) {
            $this->assertTrue($c->expires_at->isFuture(), "o convite de {$c->vendor_id} nasceu ja expirado");
            $this->assertSame(CandidateStatus::NOTIFIED, $c->status);
        }
    }

    // ------------------------------------------------------------- catalogo

    public function test_um_pedido_de_catalogo_continua_a_cotar_tempo_vezes_quantidade(): void
    {
        $tipo = ServicesType::factory()->create(['operation_area_id' => $this->canalizacao->id, 'time' => 45]);
        $service = Service::factory()->create([
            'customer_id' => $this->customer->id,
            'vendor_id' => null,
            'services_type_id' => $tipo->id,
            'quantity' => 2,
            'status' => ServiceStatus::MATCHING,
        ]);

        $scope = MatchingScope::forService($service);

        $this->assertFalse($scope->isCustom());
        $this->assertSame(90, $scope->minutes);
        $this->assertSame([$this->canalizacao->id], $scope->operationAreaIds);
    }

    // ---------------------------------------------------------------- apoio

    private function abrir(string $descricao)
    {
        return $this->actingAs($this->customer, 'api')
            ->postJson('/api/v1/customer/services/matching/custom', [
                'description' => $descricao,
                'scheduled' => false,
            ]);
    }

    private function personalizadoEmAnalise(): Service
    {
        $service = Service::factory()->create([
            'customer_id' => $this->customer->id,
            'vendor_id' => null,
            'services_type_id' => null,
            'is_custom' => true,
            'custom_description' => 'Trocar a fechadura e ligar um candeeiro.',
            'status' => ServiceStatus::PENDING_REVIEW,
            'payment_status' => PaymentStatus::PENDING,
            'amount' => null,
            'amount_for_vendor' => null,
            'is_test' => true,
            'address' => [
                'name' => 'Casa',
                'street_name' => 'Rua de Exemplo',
                'street_number' => '1',
                'postal_code' => '4000-000',
                'city' => 'Porto',
                'state' => 'Porto',
                'country' => 'Portugal',
                'latitude' => 41.1478,
                'longitude' => -8.6110,
            ],
        ]);

        return $service;
    }

    /** O mesmo que a accao "Definir e enviar" do backoffice escreve. */
    private function definirEEnviar(Service $service, int $minutos, array $areas): void
    {
        $service->custom_duration_minutes = $minutos;
        $service->custom_dispatched_at = now();
        $service->status = ServiceStatus::MATCHING;
        $service->save();
        $service->operationAreas()->sync(collect($areas)->pluck('id')->all());
    }

    private function makeAddress(User $user, $type, bool $main = false): Address
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
            'main_address' => $main,
            'address_type' => $type,
        ]);
    }

    /**
     * @param  OperationArea[]  $areas
     * @param  ServicesType[]  $tipos
     */
    private function makeVendor(string $name, int $ratePerHour, array $areas, array $tipos): Vendor
    {
        $user = User::factory()->create([
            'name' => $name,
            'is_test' => true,
            'email_verified_at' => now(),
            'phone_number_verified_at' => now(),
        ]);

        $vendor = Vendor::factory()->create([
            'user_id' => $user->id,
            'price_rate' => $ratePerHour,
            'status' => StatusVendor::ONLINE,
            'iban' => 'PT50000000000000000000000',
            'invoice_workspace' => 'ws-'.$user->id,
            'at_user' => '999999999/1',
            'at_valid' => true,
            'at_validated_at' => now(),
        ]);

        $vendor->operationAreas()->attach(collect($areas)->pluck('id')->all());
        $vendor->servicesTypes()->attach(collect($tipos)->pluck('id')->all());
        $vendor->currentLocation()->save(new Location(['latitude' => 41.15, 'longitude' => -8.61]));
        $this->makeAddress($user, AddressType::SCHEDULE_ADDRESS);
        $vendor->scheduleAvailable()->update(['auto_accept' => false]);

        return $vendor->fresh();
    }
}
