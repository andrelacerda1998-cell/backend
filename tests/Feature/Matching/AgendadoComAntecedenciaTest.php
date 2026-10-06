<?php

namespace Tests\Feature\Matching;

use App\Enums\Services\AddressType;
use App\Enums\Services\CandidateStatus;
use App\Enums\Services\ServiceStatus;
use App\Events\Matching\MatchingCandidateLostEvent;
use App\Events\Matching\MatchingInvitationEvent;
use App\Events\Matching\MatchingRequestClosedEvent;
use App\Models\Address;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Service;
use App\Models\ServiceCandidate;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Matching\MatchingService;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * AGENDADOS COM ANTECEDÊNCIA SÃO ASSÍNCRONOS.
 *
 * O diagnóstico de produção de 06/10 encontrou os 3 agendados do mês marcados
 * para 48 a 71 h depois do pedido, e os três mortos em 2 a 3 minutos com todos
 * os convites expirados e zero recusas. A regra de 29/09 dava 120 s ao técnico
 * em qualquer agendado, porque o cliente espera no ecrã — mas um serviço para
 * daqui a dois dias não precisa de resposta em dois minutos.
 *
 * O que este ficheiro prende:
 *
 *  - QUEM é assíncrono: agendados com pelo menos 24 h de antecedência, e mais
 *    nenhum. O imediato, o agendado próximo e o personalizado ficam como
 *    estavam — os 80 testes de matching anteriores passam sem mudanças;
 *  - os PRAZOS do assíncrono: 2 h para o técnico, fase de convites de 4 h, 1 h
 *    para o cliente escolher e pagar depois do primeiro sim;
 *  - que a decisão é tomada UMA vez, ao abrir o pedido, e não muda com o tempo;
 *  - que a app fica a saber, pelo endpoint real.
 */
class AgendadoComAntecedenciaTest extends TestCase
{
    use RefreshDatabase;

    private MatchingService $matching;

    protected function setUp(): void
    {
        parent::setUp();

        // Websocket e push não existem no ambiente de teste, e não é o que se
        // está a medir.
        Event::fake([
            MatchingInvitationEvent::class,
            MatchingRequestClosedEvent::class,
            MatchingCandidateLostEvent::class,
        ]);
        Notification::fake();

        $this->matching = app(MatchingService::class);
    }

    /** Um pedido em seleção, marcado para daqui a `$horas` (null = imediato). */
    private function pedido(?int $horas, bool $async = false, ?string $criadoHa = null, bool $custom = false): Service
    {
        $inicio = $horas === null ? null : now()->addHours($horas);

        $service = Service::factory()->create([
            'status' => ServiceStatus::MATCHING,
            'is_custom' => $custom,
            'matching_async' => $async,
            'pending_schedule_data' => $inicio ? [
                'scheduled' => true,
                'schedule' => [
                    'scheduled_day' => $inicio->format('Y-m-d'),
                    'scheduled_time_start' => $inicio->format('H:i'),
                ],
            ] : null,
        ]);

        if ($criadoHa) {
            $service->forceFill(['created_at' => now()->sub($criadoHa)])->saveQuietly();
        }

        return $service->refresh();
    }

    private function candidato(
        Service $service,
        CandidateStatus $status,
        ?CarbonInterface $respondidoEm = null,
        ?CarbonInterface $expiraEm = null,
    ): ServiceCandidate {
        return ServiceCandidate::create([
            'service_id' => $service->id,
            'vendor_id' => Vendor::factory()->create()->id,
            'rank' => 1,
            'wave' => 1,
            'status' => $status,
            'quoted_amount' => 5000,
            'quoted_amount_for_vendor' => 3750,
            'quoted_distance' => 3,
            'notified_at' => now()->subMinute(),
            'responded_at' => $respondidoEm,
            'expires_at' => $expiraEm,
        ]);
    }

    // ---------------------------------------------------------------- quem

    public function test_um_agendado_para_daqui_a_dois_dias_e_assincrono(): void
    {
        $this->assertTrue($this->matching->shouldBeAsync($this->pedido(48)));
    }

    /** A fronteira: 24 h conta, 23 h não. */
    public function test_a_fronteira_e_de_vinte_e_quatro_horas(): void
    {
        $this->assertTrue($this->matching->shouldBeAsync($this->pedido(25)));
        $this->assertFalse($this->matching->shouldBeAsync($this->pedido(23)));
    }

    /** O cliente de um agendado para logo à tarde está mesmo à espera no ecrã. */
    public function test_um_agendado_para_daqui_a_tres_horas_fica_com_as_regras_de_29_09(): void
    {
        $this->assertFalse($this->matching->shouldBeAsync($this->pedido(3)));
    }

    public function test_um_pedido_imediato_nunca_e_assincrono(): void
    {
        $this->assertFalse($this->matching->shouldBeAsync($this->pedido(null)));
    }

    /** O personalizado já tem o seu regime, e a seleção dele arranca no backoffice. */
    public function test_um_personalizado_fica_com_o_regime_dele(): void
    {
        $this->assertFalse($this->matching->shouldBeAsync($this->pedido(48, custom: true)));
    }

    // -------------------------------------------------------------- prazos

    public function test_o_tecnico_tem_duas_horas_no_assincrono_e_dois_minutos_no_resto(): void
    {
        $this->assertSame(7200, $this->matching->vendorResponseSeconds($this->pedido(48, async: true)));
        $this->assertSame(120, $this->matching->vendorResponseSeconds($this->pedido(3)));
        $this->assertSame(120, $this->matching->vendorResponseSeconds($this->pedido(null)));
    }

    public function test_a_fase_de_convites_dura_quatro_horas_no_assincrono(): void
    {
        $async = $this->pedido(48, async: true);
        $proximo = $this->pedido(3);

        $this->assertEqualsWithDelta(
            $async->created_at->copy()->addHours(4)->timestamp,
            $this->matching->invitationDeadline($async)->timestamp,
            1,
        );
        $this->assertEqualsWithDelta(
            $proximo->created_at->copy()->addMinutes(10)->timestamp,
            $this->matching->invitationDeadline($proximo)->timestamp,
            1,
        );
    }

    /**
     * O caso de produção, ao contrário. Com as regras de 29/09 um pedido sem
     * resposta morre aos 10 min (ver PrazoDosConvitesTest). Marcado para daqui
     * a dois dias, ainda está vivo — o convite tem horas pela frente.
     */
    public function test_o_assincrono_nao_morre_aos_dez_minutos(): void
    {
        $service = $this->pedido(48, async: true, criadoHa: '11 minutes');
        $this->candidato($service, CandidateStatus::NOTIFIED, null, now()->addHour());

        $this->artisan('matching:advance')->assertSuccessful();

        $this->assertSame(ServiceStatus::MATCHING, $service->refresh()->status);
    }

    /** Mas tem fim: a fase de convites fecha às 4 h, como qualquer rede de segurança. */
    public function test_mas_morre_ao_fim_da_fase_de_convites(): void
    {
        $service = $this->pedido(48, async: true, criadoHa: '4 hours 1 minute');
        $this->candidato($service, CandidateStatus::NOTIFIED, null, now()->addMinutes(30));

        $this->artisan('matching:advance')->assertSuccessful();

        $this->assertSame(ServiceStatus::MATCHING_FAILED, $service->refresh()->status);
    }

    /**
     * O cliente já não está a olhar quando o primeiro técnico aceita: chega-lhe
     * uma notificação. Uma hora para escolher e pagar, como no personalizado —
     * e não os 10 min do agendado próximo.
     */
    public function test_o_cliente_tem_uma_hora_depois_do_primeiro_sim(): void
    {
        $service = $this->pedido(48, async: true);
        $sim = now()->subMinutes(15);
        $this->candidato($service, CandidateStatus::ACCEPTED, $sim);
        $service->forceFill(['candidates_ready_at' => $sim])->saveQuietly();

        $this->assertEqualsWithDelta(
            $sim->copy()->addHour()->timestamp,
            $this->matching->customerDeadline($service->refresh())->timestamp,
            1,
        );

        // Quinze minutos depois do sim: com os 10 min do agendado próximo já
        // tinha morrido. Aqui continua à espera do cliente.
        $this->artisan('matching:advance')->assertSuccessful();
        $this->assertSame(ServiceStatus::MATCHING, $service->refresh()->status);
    }

    /** O mesmo cenário num agendado próximo, para provar que a diferença é do regime. */
    public function test_no_agendado_proximo_o_cliente_continua_a_ter_dez_minutos(): void
    {
        $service = $this->pedido(3);
        $sim = now()->subMinutes(15);
        $this->candidato($service, CandidateStatus::ACCEPTED, $sim);
        $service->forceFill(['candidates_ready_at' => $sim])->saveQuietly();

        $this->artisan('matching:advance')->assertSuccessful();

        $this->assertSame(ServiceStatus::MATCHING_FAILED, $service->refresh()->status);
    }

    // ------------------------------------------------------------ decisão

    /**
     * Pedido feito com 25 h de antecedência; duas horas depois faltam 23 h.
     * Recalculado, mudava de regras a meio dos convites. Gravado, não muda.
     */
    public function test_a_decisao_fica_gravada_e_nao_muda_com_o_tempo(): void
    {
        $service = $this->pedido(25, async: true);

        $this->travel(2)->hours();

        $this->assertFalse($this->matching->shouldBeAsync($service->refresh()), 'já não seria assíncrono se se decidisse agora');
        $this->assertTrue($this->matching->isAsync($service), 'mas a decisão foi tomada ao abrir');
        $this->assertSame(7200, $this->matching->vendorResponseSeconds($service));
    }

    // ----------------------------------------------------------- endpoint

    private function cliente(): User
    {
        $user = User::factory()->create(['phone_number_verified_at' => now()]);

        Address::create([
            'user_id' => $user->id,
            'name' => 'Casa',
            'street_name' => 'Rua de Exemplo',
            'street_number' => '1',
            'postal_code' => '1000-000',
            'city' => 'Lisboa',
            'municipality' => 'Lisboa',
            'state' => 'Lisboa',
            'country' => 'Portugal',
            'latitude' => 38.7223,
            'longitude' => -9.1393,
            'main_address' => true,
            'address_type' => AddressType::HOUSE_ADDRESS,
        ]);

        return $user->fresh();
    }

    private function abrir(User $cliente, int $horas): \Illuminate\Testing\TestResponse
    {
        $inicio = now()->addHours($horas);

        return $this->actingAs($cliente, 'api')->postJson('/api/v1/customer/services/matching', [
            'service_type' => ServicesType::factory()->create()->id,
            'scheduled' => true,
            'schedule' => [
                'scheduled_day' => $inicio->format('Y-m-d'),
                'scheduled_time_start' => $inicio->format('H:i'),
            ],
        ]);
    }

    /**
     * O caminho real: o regime é decidido ao abrir o pedido e a app fica a
     * saber. Sem técnicos elegíveis o pedido falha logo — o que interessa aqui
     * é a decisão gravada e o que a resposta diz.
     */
    public function test_ao_abrir_um_agendado_com_antecedencia_a_app_fica_a_saber(): void
    {
        $resposta = $this->abrir($this->cliente(), 48)->assertOk();

        $this->assertTrue($resposta->json('data.service.async'));
        $this->assertNotNull($resposta->json('data.service.respond_by'));
        $this->assertTrue((bool) Service::query()->latest('id')->first()->matching_async);
    }

    public function test_ao_abrir_um_agendado_proximo_nada_muda_para_a_app(): void
    {
        $resposta = $this->abrir($this->cliente(), 3)->assertOk();

        $this->assertFalse($resposta->json('data.service.async'));
        $this->assertNull($resposta->json('data.service.respond_by'));
        $this->assertFalse((bool) Service::query()->latest('id')->first()->matching_async);
    }
}
