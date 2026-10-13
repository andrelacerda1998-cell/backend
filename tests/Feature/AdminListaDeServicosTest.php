<?php

namespace Tests\Feature;

use App\Enums\Services\CandidateStatus;
use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Http\Controllers\Api\Admin\OperacoesAoVivoController;
use App\Models\ServiceCandidate;
use App\Models\User;
use App\Models\Vendor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A lista de pedidos do backoffice: os pedidos de uma pessoa, de um dia, os
 * abertos, as marcações por vir, a ordenação, o que pede atenção e a pesquisa
 * pelo número e pelo telefone em qualquer formato.
 *
 * As listas comparam-se só com os pedidos que CADA teste criou: um pedido
 * deixado na base por outra classe não decide se este passa.
 */
class AdminListaDeServicosTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<int> */
    private array $criados = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null']);
        config(['services.admin_api.token' => 'a-valid-token']);
        Notification::fake();
        Queue::fake();
    }

    private function api(): static
    {
        return $this->withHeaders(['Authorization' => 'Bearer a-valid-token']);
    }

    /** INSERT direto: controla estado e datas sem passar pelos observers. */
    private function servico(ServiceStatus $status, array $extra = []): int
    {
        return $this->criados[] = DB::table('services')->insertGetId([
            'status' => $status->value,
            'payment_status' => PaymentStatus::PAID->value,
            'is_test' => false,
            'created_at' => now(),
            'updated_at' => now(),
            ...$extra,
        ]);
    }

    private function marcacao(int $servico, string $dia, string $hora): void
    {
        $vendorId = DB::table('services')->where('id', $servico)->value('vendor_id') ?? Vendor::factory()->create()->id;

        DB::table('schedule')->insert([
            'vendor_id' => $vendorId,
            'customer_id' => User::factory()->create()->id,
            'service_id' => $servico,
            'scheduled_day' => $dia,
            'scheduled_time_start' => $hora,
            'scheduled_time_end' => $hora,
            'is_pending' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function candidato(int $servico, CandidateStatus $status): void
    {
        ServiceCandidate::create([
            'service_id' => $servico,
            'vendor_id' => Vendor::factory()->create()->id,
            'rank' => 1,
            'wave' => 1,
            'status' => $status,
            'quoted_amount' => 5000,
            'quoted_amount_for_vendor' => 3750,
            'quoted_distance' => 3,
            'notified_at' => now()->subMinutes(5),
            'expires_at' => now()->addMinutes(2),
        ]);
    }

    /** Um passo no histórico: o pedido entrou neste estado há tantos minutos. */
    private function passou(int $servico, ServiceStatus $estado, int $haMinutos): void
    {
        DB::table('service_events')->insert([
            'service_id' => $servico,
            'tipo' => 'estado',
            'estado_para' => $estado->value,
            'ocorreu_em' => now()->subMinutes($haMinutos),
        ]);
    }

    private function extraPorAprovar(int $servico, int $haHoras): void
    {
        DB::table('service_extras')->insert([
            'service_id' => $servico, 'type' => 'time', 'minutes' => 30, 'amount' => 1500, 'status' => 'pending',
            'created_at' => now()->subHours($haHoras), 'updated_at' => now()->subHours($haHoras),
        ]);
    }

    /** Uma marcação para há (ou daqui a) tantos minutos, em hora de Lisboa. */
    private function marcadoHa(int $servico, int $minutos): void
    {
        $quando = now('Europe/Lisbon')->subMinutes($minutos);
        $this->marcacao($servico, $quando->toDateString(), $quando->format('H:i:s'));
    }

    /**
     * Os pedidos presos das Operações ao vivo, um de cada, e um que ainda não
     * o é ao lado de cada um.
     *
     * @return array<string, int>
     */
    private function pedidosPresos(): array
    {
        $atraso = OperacoesAoVivoController::MINUTOS_DE_ATRASO_SEM_CHEGAR;
        $imediato = OperacoesAoVivoController::MINUTOS_DE_VIAGEM_IMEDIATO + $atraso;
        $execucao = OperacoesAoVivoController::HORAS_EM_EXECUCAO;
        $cliente = OperacoesAoVivoController::HORAS_A_ESPERA_DO_CLIENTE;
        $tecnico = ['vendor_id' => Vendor::factory()->create()->id];

        $p = [
            'agendado_atrasado' => $this->servico(ServiceStatus::SCHEDULED, $tecnico),
            'agendado_a_tempo' => $this->servico(ServiceStatus::SCHEDULED, $tecnico),
            'agendado_ja_chegou' => $this->servico(ServiceStatus::SCHEDULED, [...$tecnico, 'arrived_at' => now()->subMinutes(40)]),
            'imediato_atrasado' => $this->servico(ServiceStatus::ACCEPTED, [...$tecnico, 'updated_at' => now()]),
            'imediato_ainda_a_tempo' => $this->servico(ServiceStatus::ACCEPTED, [...$tecnico, 'updated_at' => now()]),
            'execucao_longa' => $this->servico(ServiceStatus::ARRIVED, [...$tecnico, 'arrived_at' => now()->subHours($execucao)->subMinutes(5)]),
            'execucao_normal' => $this->servico(ServiceStatus::ARRIVED, [...$tecnico, 'arrived_at' => now()->subHours(2)]),
            'extra_parado' => $this->servico(ServiceStatus::ARRIVED, [...$tecnico, 'arrived_at' => now()->subHours(2)]),
            'extra_recente' => $this->servico(ServiceStatus::ARRIVED, [...$tecnico, 'arrived_at' => now()->subHours(2)]),
            'cartao_parado' => $this->servico(ServiceStatus::PENDING_3DS, ['updated_at' => now()]),
            'cartao_recente' => $this->servico(ServiceStatus::PENDING_3DS, ['updated_at' => now()->subHours($cliente + 5)]),
        ];

        $this->marcadoHa($p['agendado_atrasado'], $atraso + 5);
        $this->marcadoHa($p['agendado_a_tempo'], $atraso - 15);
        $this->marcadoHa($p['agendado_ja_chegou'], $atraso + 15);
        // O histórico manda: o `updated_at` mexe com tudo e engana.
        $this->passou($p['imediato_atrasado'], ServiceStatus::ACCEPTED, $imediato + 5);
        $this->passou($p['imediato_ainda_a_tempo'], ServiceStatus::ACCEPTED, $imediato - 10);
        $this->extraPorAprovar($p['extra_parado'], $cliente + 1);
        $this->extraPorAprovar($p['extra_recente'], 1);
        $this->passou($p['cartao_parado'], ServiceStatus::PENDING_3DS, ($cliente + 1) * 60);
        $this->passou($p['cartao_recente'], ServiceStatus::PENDING_3DS, 30);

        return $p;
    }

    /** Os ids devolvidos, PELA ORDEM da resposta, só dos criados aqui. */
    private function ids(string $query = ''): array
    {
        $separador = str_contains($query, '?') ? '&' : '?';

        return collect($this->api()->getJson('/api/v1/admin/services'.$query.$separador.'per_page=100')->assertOk()->json('data.items'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => in_array($id, $this->criados, true))
            ->values()
            ->all();
    }

    /** @return array<int, array> os itens da resposta, pelo id */
    private function itens(string $query = ''): array
    {
        $separador = str_contains($query, '?') ? '&' : '?';

        return collect($this->api()->getJson('/api/v1/admin/services'.$query.$separador.'per_page=100')->assertOk()->json('data.items'))
            ->keyBy(fn ($item) => (int) $item['id'])
            ->all();
    }

    // ------------------------------------------------- de quem e de quando

    public function test_filtra_pelo_cliente_e_pelo_tecnico(): void
    {
        $ana = User::factory()->create();
        $rui = Vendor::factory()->create();
        $daAna = $this->servico(ServiceStatus::CLOSED, ['customer_id' => $ana->id]);
        $doRui = $this->servico(ServiceStatus::CLOSED, ['vendor_id' => $rui->id]);
        $dosDois = $this->servico(ServiceStatus::CLOSED, ['customer_id' => $ana->id, 'vendor_id' => $rui->id]);
        $this->servico(ServiceStatus::CLOSED);

        $this->assertSame([$dosDois, $daAna], $this->ids("?customer_id={$ana->id}"));
        $this->assertSame([$dosDois, $doRui], $this->ids("?vendor_id={$rui->id}"));
        $this->assertSame([$dosDois], $this->ids("?customer_id={$ana->id}&vendor_id={$rui->id}"));
    }

    public function test_o_cliente_e_o_tecnico_tem_de_ser_numeros(): void
    {
        $this->api()->getJson('/api/v1/admin/services?customer_id=ana')->assertStatus(422);
        $this->api()->getJson('/api/v1/admin/services?vendor_id=rui')->assertStatus(422);
    }

    public function test_o_dia_junta_os_marcados_para_ele_e_os_pedidos_nele_sem_marcacao(): void
    {
        // 15 de julho: hora de verão, Lisboa = UTC+1.
        $marcadoParaODia = $this->servico(ServiceStatus::SCHEDULED, ['created_at' => '2026-07-01 10:00:00']);
        $this->marcacao($marcadoParaODia, '2026-07-15', '10:00');

        // Pedido nesse dia, mas marcado para outro: é do dia da marcação.
        $pedidoNoDiaParaOutro = $this->servico(ServiceStatus::SCHEDULED, ['created_at' => '2026-07-15 10:00:00']);
        $this->marcacao($pedidoNoDiaParaOutro, '2026-07-20', '10:00');

        // 00:30 de Lisboa do dia 15 são 23:30 UTC do dia 14.
        $aMeiaNoiteDeLisboa = $this->servico(ServiceStatus::MATCHING, ['created_at' => '2026-07-14 23:30:00']);
        // 00:30 de Lisboa do dia 16 são 23:30 UTC do dia 15: já é o dia seguinte.
        $doDiaSeguinte = $this->servico(ServiceStatus::MATCHING, ['created_at' => '2026-07-15 23:30:00']);
        $aMeioDoDia = $this->servico(ServiceStatus::CLOSED, ['created_at' => '2026-07-15 12:00:00']);

        $doDia = $this->ids('?dia=2026-07-15');

        $this->assertEqualsCanonicalizing([$marcadoParaODia, $aMeiaNoiteDeLisboa, $aMeioDoDia], $doDia);
        $this->assertNotContains($pedidoNoDiaParaOutro, $doDia);
        $this->assertNotContains($doDiaSeguinte, $doDia);
        $this->assertSame([$pedidoNoDiaParaOutro], $this->ids('?dia=2026-07-20'));
    }

    public function test_um_dia_mal_escrito_e_recusado(): void
    {
        $this->api()->getJson('/api/v1/admin/services?dia=15-07-2026')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['dia']);
    }

    // ------------------------------------------------- abertos e agendados

    public function test_abertos_e_finais_dividem_todos_os_estados_sem_sobrar_nenhum(): void
    {
        $finais = array_map(fn (ServiceStatus $e) => $e->value, ServiceStatus::finais());
        $abertos = array_map(fn (ServiceStatus $e) => $e->value, ServiceStatus::abertos());

        $this->assertSame([], array_values(array_intersect($finais, $abertos)));
        $this->assertEqualsCanonicalizing(
            array_map(fn (ServiceStatus $e) => $e->value, ServiceStatus::cases()),
            [...$finais, ...$abertos],
        );
        // Por cobrar é trabalho para a equipa, não é final.
        $this->assertContains(ServiceStatus::CLOSED_PENDING_PAYMENT->value, $abertos);
    }

    public function test_abertos_deixa_de_fora_os_que_ja_acabaram(): void
    {
        $abertos = [];
        foreach (ServiceStatus::abertos() as $estado) {
            $abertos[] = $this->servico($estado);
        }
        foreach (ServiceStatus::finais() as $estado) {
            $this->servico($estado);
        }

        $this->assertEqualsCanonicalizing($abertos, $this->ids('?abertos=1'));
    }

    public function test_agendados_sao_as_marcacoes_por_vir_com_tecnico(): void
    {
        // 10:00 UTC = 11:00 em Lisboa (verão).
        $this->travelTo(CarbonImmutable::parse('2026-07-15 10:00:00', 'UTC'));
        $rui = Vendor::factory()->create();

        $amanha = $this->servico(ServiceStatus::SCHEDULED, ['vendor_id' => $rui->id]);
        $this->marcacao($amanha, '2026-07-16', '09:00');
        $hojeMaisLogo = $this->servico(ServiceStatus::ACCEPTED, ['vendor_id' => $rui->id]);
        $this->marcacao($hojeMaisLogo, '2026-07-15', '15:00');
        // Pago, à espera de o técnico confirmar: também fica sem técnico.
        $porConfirmar = $this->servico(ServiceStatus::PENDING, ['vendor_id' => $rui->id]);
        $this->marcacao($porConfirmar, '2026-07-20', '10:00');
        // 10:30 de Lisboa já passou (são 11:00) — em UTC ainda não teria passado.
        $hojeJaPassou = $this->servico(ServiceStatus::SCHEDULED, ['vendor_id' => $rui->id]);
        $this->marcacao($hojeJaPassou, '2026-07-15', '10:30');
        $ontem = $this->servico(ServiceStatus::SCHEDULED, ['vendor_id' => $rui->id]);
        $this->marcacao($ontem, '2026-07-14', '18:00');
        $cancelado = $this->servico(ServiceStatus::CANCELED, ['vendor_id' => $rui->id]);
        $this->marcacao($cancelado, '2026-07-16', '09:00');
        $semMarcacao = $this->servico(ServiceStatus::ACCEPTED, ['vendor_id' => $rui->id]);
        $deOutroTecnico = $this->servico(ServiceStatus::SCHEDULED, ['vendor_id' => Vendor::factory()->create()->id]);
        $this->marcacao($deOutroTecnico, '2026-07-16', '09:00');

        $this->assertEqualsCanonicalizing(
            [$amanha, $hojeMaisLogo, $porConfirmar],
            $this->ids("?agendados=1&vendor_id={$rui->id}"),
        );
        $this->assertContains($deOutroTecnico, $this->ids('?agendados=1'));
    }

    // ------------------------------------------------------------- ordem

    public function test_ordena_pelo_valor_com_os_sem_preco_no_fim(): void
    {
        $caro = $this->servico(ServiceStatus::CLOSED, ['amount' => 9000]);
        $barato = $this->servico(ServiceStatus::CLOSED, ['amount' => 1000]);
        $medio = $this->servico(ServiceStatus::CLOSED, ['amount' => 5000]);
        $semPreco = $this->servico(ServiceStatus::MATCHING, ['amount' => null]);

        $this->assertSame([$barato, $medio, $caro, $semPreco], $this->ids('?sort=amount&dir=asc'));
        $this->assertSame([$caro, $medio, $barato, $semPreco], $this->ids('?sort=amount&dir=desc'));
    }

    public function test_ordena_pela_marcacao_com_os_sem_marcacao_no_fim(): void
    {
        $depois = $this->servico(ServiceStatus::SCHEDULED);
        $this->marcacao($depois, '2026-11-20', '09:00');
        $antes = $this->servico(ServiceStatus::SCHEDULED);
        $this->marcacao($antes, '2026-11-10', '18:00');
        $maisCedoNoMesmoDia = $this->servico(ServiceStatus::SCHEDULED);
        $this->marcacao($maisCedoNoMesmoDia, '2026-11-10', '08:00');
        $semMarcacao = $this->servico(ServiceStatus::MATCHING);

        $this->assertSame([$maisCedoNoMesmoDia, $antes, $depois, $semMarcacao], $this->ids('?sort=scheduled_at&dir=asc'));
        $this->assertSame([$depois, $antes, $maisCedoNoMesmoDia, $semMarcacao], $this->ids('?sort=scheduled_at&dir=desc'));
    }

    public function test_ordena_pela_data_e_pelo_numero(): void
    {
        $velho = $this->servico(ServiceStatus::CLOSED, ['created_at' => now()->subDays(3)]);
        $novo = $this->servico(ServiceStatus::CLOSED, ['created_at' => now()->subDay()]);
        $meio = $this->servico(ServiceStatus::CLOSED, ['created_at' => now()->subDays(2)]);

        $this->assertSame([$velho, $meio, $novo], $this->ids('?sort=created_at&dir=asc'));
        // Sem sentido, é do mais recente para o mais antigo.
        $this->assertSame([$novo, $meio, $velho], $this->ids('?sort=created_at'));
        $this->assertSame([$velho, $novo, $meio], $this->ids('?sort=id&dir=asc'));
    }

    public function test_uma_ordenacao_fora_da_lista_e_ignorada(): void
    {
        $a = $this->servico(ServiceStatus::CLOSED, ['amount' => 9000]);
        $b = $this->servico(ServiceStatus::CLOSED, ['amount' => 1000]);
        $c = $this->servico(ServiceStatus::CLOSED, ['amount' => 5000]);

        // A ordem de sempre: o mais recente primeiro.
        $this->assertSame([$c, $b, $a], $this->ids());
        $this->assertSame([$c, $b, $a], $this->ids('?sort=password'));
        $this->assertSame([$c, $b, $a], $this->ids('?sort='.urlencode('amount; DROP TABLE services')));
        // O sentido sozinho não muda nada...
        $this->assertSame([$c, $b, $a], $this->ids('?dir=asc'));
        // ...e um sentido que não existe fica o de omissão.
        $this->assertSame([$a, $c, $b], $this->ids('?sort=amount&dir=sideways'));
    }

    // ------------------------------------------------------------ atenção

    public function test_atencao_poe_primeiro_o_que_precisa_de_uma_pessoa(): void
    {
        $normalAntigo = $this->servico(ServiceStatus::CLOSED);
        $porConfirmarHaUmDia = $this->servico(ServiceStatus::FINISHED, ['updated_at' => now()->subHours(30)]);
        $porRever = $this->servico(ServiceStatus::PENDING_REVIEW, ['is_custom' => true]);
        $porCobrar = $this->servico(ServiceStatus::CLOSED_PENDING_PAYMENT);
        $semConvidados = $this->servico(ServiceStatus::MATCHING, ['created_at' => now()->subMinutes(5)]);
        $acabadoDeTerminar = $this->servico(ServiceStatus::FINISHED, ['updated_at' => now()->subHours(2)]);
        $acabadoDePedir = $this->servico(ServiceStatus::MATCHING, ['created_at' => now()->subSeconds(30)]);
        $normalRecente = $this->servico(ServiceStatus::ARRIVED);

        // Críticos, depois os de atenção, depois o resto — cada grupo pelo mais recente.
        $this->assertSame([
            $semConvidados, $porCobrar, $porRever,
            $porConfirmarHaUmDia,
            $normalRecente, $acabadoDePedir, $acabadoDeTerminar, $normalAntigo,
        ], $this->ids('?atencao=1'));

        // Sem `atencao`, a ordem de sempre.
        $this->assertSame([
            $normalRecente, $acabadoDePedir, $acabadoDeTerminar, $semConvidados,
            $porCobrar, $porRever, $porConfirmarHaUmDia, $normalAntigo,
        ], $this->ids());
    }

    public function test_cada_pedido_diz_se_pede_atencao_e_porque(): void
    {
        $porRever = $this->servico(ServiceStatus::PENDING_REVIEW, ['is_custom' => true]);
        $porCobrar = $this->servico(ServiceStatus::CLOSED_PENDING_PAYMENT);
        $porConfirmar = $this->servico(ServiceStatus::FINISHED, ['updated_at' => now()->subHours(30)]);
        $semConvidados = $this->servico(ServiceStatus::MATCHING, ['created_at' => now()->subMinutes(5)]);
        $ninguemResponde = $this->servico(ServiceStatus::MATCHING);
        $this->candidato($ninguemResponde, CandidateStatus::DECLINED);
        $this->candidato($ninguemResponde, CandidateStatus::EXPIRED);
        $alguemAceitou = $this->servico(ServiceStatus::MATCHING);
        $this->candidato($alguemAceitou, CandidateStatus::ACCEPTED);
        $aindaPodemResponder = $this->servico(ServiceStatus::MATCHING);
        $this->candidato($aindaPodemResponder, CandidateStatus::NOTIFIED);
        $normal = $this->servico(ServiceStatus::ARRIVED);

        $itens = $this->itens();

        $this->assertSame(['nivel' => 'critico', 'motivo' => 'personalizado_por_rever'], $itens[$porRever]['atencao']);
        $this->assertSame(['nivel' => 'critico', 'motivo' => 'pagamento_por_capturar'], $itens[$porCobrar]['atencao']);
        $this->assertSame(['nivel' => 'atencao', 'motivo' => 'cliente_nao_confirmou'], $itens[$porConfirmar]['atencao']);
        $this->assertSame(['nivel' => 'critico', 'motivo' => 'ninguem_convidado'], $itens[$semConvidados]['atencao']);
        $this->assertSame(['nivel' => 'critico', 'motivo' => 'ninguem_a_responder'], $itens[$ninguemResponde]['atencao']);
        $this->assertNull($itens[$alguemAceitou]['atencao']);
        $this->assertNull($itens[$aindaPodemResponder]['atencao']);
        $this->assertNull($itens[$normal]['atencao']);

        // O detalhe diz o mesmo.
        $this->api()->getJson("/api/v1/admin/services/{$porCobrar}")->assertOk()
            ->assertJsonPath('data.atencao', ['nivel' => 'critico', 'motivo' => 'pagamento_por_capturar']);
        $this->api()->getJson("/api/v1/admin/services/{$normal}")->assertOk()
            ->assertJsonPath('data.atencao', null);
    }

    public function test_os_pedidos_presos_tambem_pedem_atencao(): void
    {
        $p = $this->pedidosPresos();
        $itens = $this->itens();

        $this->assertSame(['nivel' => 'critico', 'motivo' => 'tecnico_nao_chegou'], $itens[$p['agendado_atrasado']]['atencao']);
        $this->assertSame(['nivel' => 'critico', 'motivo' => 'tecnico_nao_chegou'], $itens[$p['imediato_atrasado']]['atencao']);
        $this->assertSame(['nivel' => 'atencao', 'motivo' => 'em_execucao_ha_muito'], $itens[$p['execucao_longa']]['atencao']);
        $this->assertSame(['nivel' => 'atencao', 'motivo' => 'cliente_sem_resposta'], $itens[$p['extra_parado']]['atencao']);
        $this->assertSame(['nivel' => 'atencao', 'motivo' => 'cliente_sem_resposta'], $itens[$p['cartao_parado']]['atencao']);

        foreach (['agendado_a_tempo', 'agendado_ja_chegou', 'imediato_ainda_a_tempo', 'execucao_normal', 'extra_recente', 'cartao_recente'] as $ainda) {
            $this->assertNull($itens[$p[$ainda]]['atencao'], $ainda);
        }

        // Os críticos à frente dos de atenção.
        $primeiros = array_slice($this->ids('?atencao=1'), 0, 5);
        $this->assertEqualsCanonicalizing([$p['agendado_atrasado'], $p['imediato_atrasado']], array_slice($primeiros, 0, 2));
        $this->assertEqualsCanonicalizing([$p['execucao_longa'], $p['extra_parado'], $p['cartao_parado']], array_slice($primeiros, 2, 3));
    }

    /** A pagar ou a escolher há mais de um dia: o cliente não responde (nas Operações ao vivo, o prazo esgotou). */
    public function test_a_pagar_ou_a_escolher_ha_mais_de_um_dia_pede_atencao(): void
    {
        $horas = OperacoesAoVivoController::HORAS_A_ESPERA_DO_CLIENTE;
        $aPagar = $this->servico(ServiceStatus::AWAITING_PAYMENT, ['updated_at' => now()->subHours($horas + 2)]);
        $aPagarHaPouco = $this->servico(ServiceStatus::AWAITING_PAYMENT, ['updated_at' => now()->subMinutes(5)]);
        $aEscolher = $this->servico(ServiceStatus::MATCHING, ['candidates_ready_at' => now()->subHours($horas + 2)]);
        $this->candidato($aEscolher, CandidateStatus::ACCEPTED);
        $aEscolherHaPouco = $this->servico(ServiceStatus::MATCHING, ['candidates_ready_at' => now()->subMinutes(3)]);
        $this->candidato($aEscolherHaPouco, CandidateStatus::ACCEPTED);

        $itens = $this->itens();

        $this->assertSame(['nivel' => 'atencao', 'motivo' => 'cliente_sem_resposta'], $itens[$aPagar]['atencao']);
        $this->assertSame(['nivel' => 'atencao', 'motivo' => 'cliente_sem_resposta'], $itens[$aEscolher]['atencao']);
        $this->assertNull($itens[$aPagarHaPouco]['atencao']);
        $this->assertNull($itens[$aEscolherHaPouco]['atencao']);
    }

    /** As mesmas regras, os mesmos nomes: a lista não pode contradizer as Operações ao vivo. */
    public function test_os_alertas_sao_os_mesmos_das_operacoes_ao_vivo(): void
    {
        $pedidos = [
            $this->servico(ServiceStatus::PENDING_REVIEW, ['is_custom' => true]),
            $this->servico(ServiceStatus::CLOSED_PENDING_PAYMENT),
            $this->servico(ServiceStatus::FINISHED, ['updated_at' => now()->subHours(30)]),
            $this->servico(ServiceStatus::FINISHED, ['updated_at' => now()->subHours(2)]),
            $this->servico(ServiceStatus::MATCHING, ['created_at' => now()->subMinutes(5)]),
            ...array_values($this->pedidosPresos()),
        ];

        $aoVivo = $this->api()->getJson('/api/v1/admin/operacoes/ao-vivo')->assertOk();
        $alertas = collect([...$aoVivo->json('data.a_procura'), ...$aoVivo->json('data.em_curso')])
            ->keyBy(fn ($p) => (int) $p['id'])
            ->map(fn ($p) => $p['alerta']);

        $itens = $this->itens();

        foreach ($pedidos as $id) {
            $this->assertTrue($alertas->has($id), "O pedido {$id} não está nas Operações ao vivo.");
            $this->assertSame($alertas[$id], $itens[$id]['atencao'], "Pedido {$id}");
        }
    }

    // ----------------------------------------------------------- pesquisa

    public function test_o_numero_exato_vem_primeiro_mesmo_com_outros_resultados(): void
    {
        $alvo = $this->servico(ServiceStatus::CLOSED);
        // Um pedido mais recente, de um cliente com o número do alvo no telefone.
        $cliente = User::factory()->create(['phone_number' => '+3519'.str_pad((string) $alvo, 8, '0', STR_PAD_LEFT)]);
        $doTelefone = $this->servico(ServiceStatus::CLOSED, ['customer_id' => $cliente->id]);

        $this->assertSame([$alvo, $doTelefone], $this->ids("?search={$alvo}"));
        // Com cardinal é sem dúvida o número do pedido: os telefones não entram.
        $this->assertSame([$alvo], $this->ids('?search=%23'.$alvo));
        $this->assertSame([$alvo], $this->ids('?search='.urlencode("# {$alvo}")));
    }

    public function test_procura_o_telefone_do_cliente_em_qualquer_formato(): void
    {
        $cliente = User::factory()->create(['phone_number' => '+351912345678']);
        $doCliente = $this->servico(ServiceStatus::CLOSED, ['customer_id' => $cliente->id]);
        $this->servico(ServiceStatus::CLOSED, ['customer_id' => User::factory()->create(['phone_number' => '+351936000000'])->id]);

        foreach (['912345678', '912 345 678', '+351 912 345 678', '00351912345678', '00351 912 345 678', '912-345-678', '(+351) 912.345.678', '+351912345678', '345 678'] as $formato) {
            $this->assertSame([$doCliente], $this->ids('?search='.urlencode($formato)), "Formato: {$formato}");
        }
    }

    /** Linhas antigas foram gravadas sem indicativo e com espaços. */
    public function test_procura_telefones_antigos_gravados_noutro_formato(): void
    {
        $cliente = User::factory()->create();
        DB::table('users')->where('id', $cliente->id)->update(['phone_number' => '912 345 678']);
        $doCliente = $this->servico(ServiceStatus::CLOSED, ['customer_id' => $cliente->id]);

        $this->assertSame([$doCliente], $this->ids('?search='.urlencode('+351912345678')));
    }

    public function test_procura_o_telefone_e_o_nome_do_tecnico(): void
    {
        $tecnico = Vendor::factory()->create();
        $tecnico->user->forceFill(['first_name' => 'Rui', 'last_name' => 'Canalizador', 'phone_number' => '+351936111222'])->save();
        $doTecnico = $this->servico(ServiceStatus::CLOSED, ['vendor_id' => $tecnico->id]);
        $this->servico(ServiceStatus::CLOSED, ['vendor_id' => Vendor::factory()->create()->id]);

        $this->assertSame([$doTecnico], $this->ids('?search='.urlencode('936 111 222')));
        $this->assertSame([$doTecnico], $this->ids('?search='.urlencode('Rui Canalizador')));
    }

    public function test_procura_o_nome_completo_do_cliente(): void
    {
        $ana = User::factory()->create(['first_name' => 'Ana', 'last_name' => 'Costa']);
        $daAna = $this->servico(ServiceStatus::CLOSED, ['customer_id' => $ana->id]);
        $outraAna = User::factory()->create(['first_name' => 'Ana', 'last_name' => 'Marques']);
        $this->servico(ServiceStatus::CLOSED, ['customer_id' => $outraAna->id]);
        // Conta só de telemóvel, sem apelido: a coluna `name` é NULL.
        $soNome = User::factory()->create(['first_name' => 'Zulmira', 'last_name' => null]);
        $daZulmira = $this->servico(ServiceStatus::CLOSED, ['customer_id' => $soNome->id]);

        $this->assertSame([$daAna], $this->ids('?search='.urlencode('Ana Costa')));
        $this->assertSame([$daAna], $this->ids('?search='.urlencode('  ana   costa ')));
        $this->assertSame([$daZulmira], $this->ids('?search=Zulmira'));
    }

    public function test_a_pesquisa_respeita_o_filtro_do_cliente(): void
    {
        $ana = User::factory()->create(['phone_number' => '+351912345678']);
        $bruno = User::factory()->create(['phone_number' => '+351912345679']);
        $daAna = $this->servico(ServiceStatus::CLOSED, ['customer_id' => $ana->id]);
        $this->servico(ServiceStatus::CLOSED, ['customer_id' => $bruno->id]);

        $this->assertSame([$daAna], $this->ids("?search=91234567&customer_id={$ana->id}"));
    }

    // ------------------------------------------------------------- campos

    public function test_cada_pedido_traz_os_ids_e_os_telefones_das_duas_pessoas(): void
    {
        $cliente = User::factory()->create(['phone_number' => '+351912000111']);
        $tecnico = Vendor::factory()->create();
        $tecnico->user->forceFill(['phone_number' => '+351936000222'])->save();
        $comTecnico = $this->servico(ServiceStatus::ARRIVED, ['customer_id' => $cliente->id, 'vendor_id' => $tecnico->id]);
        $semTecnico = $this->servico(ServiceStatus::MATCHING, ['customer_id' => $cliente->id]);

        $itens = $this->itens();

        $this->assertSame($cliente->id, $itens[$comTecnico]['customer_id']);
        $this->assertSame($tecnico->id, $itens[$comTecnico]['vendor_id']);
        $this->assertSame('+351912000111', $itens[$comTecnico]['customer_phone']);
        $this->assertSame('+351936000222', $itens[$comTecnico]['vendor_phone']);
        $this->assertNull($itens[$semTecnico]['vendor_id']);
        $this->assertNull($itens[$semTecnico]['vendor_phone']);
    }
}
