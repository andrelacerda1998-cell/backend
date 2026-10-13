<?php

namespace Tests\Feature;

use App\Enums\Services\CandidateStatus;
use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Enums\Vendors\StatusVendor;
use App\Http\Controllers\Api\Admin\OperacoesAoVivoController;
use App\Models\GeneralSettings\City;
use App\Models\GeneralSettings\OperationArea;
use App\Models\GeneralSettings\ServicesType;
use App\Models\ServiceCandidate;
use App\Models\ServiceEvent;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Vendor\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Operações ao vivo: os pedidos a morrer, a oferta real e a liquidez.
 */
class AdminOperacoesAoVivoApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null']);
        /*
         * Uma data longe de tudo. A liquidez soma TODOS os pedidos dos últimos
         * 7 e 30 dias, e na bateria completa há testes que deixam serviços
         * gravados com a data real: aqui, nenhum cai dentro da janela.
         */
        $this->travelTo(Carbon::parse('2031-03-12 11:00:00')->utc());
    }

    private function ler(string $query = ''): array
    {
        config(['services.admin_api.token' => 'a-valid-token']);

        return $this->withHeaders(['Authorization' => 'Bearer a-valid-token'])
            ->getJson('/api/v1/admin/operacoes/ao-vivo'.$query)
            ->assertOk()
            ->json('data');
    }

    private function servico(ServiceStatus $status, int $haMinutos = 5, array $extra = []): int
    {
        $quando = now()->subMinutes($haMinutos);

        return DB::table('services')->insertGetId([
            'status' => $status->value,
            'payment_status' => PaymentStatus::PENDING->value ?? 'Pending',
            'is_test' => false,
            'created_at' => $quando,
            'updated_at' => $extra['updated_at'] ?? $quando,
            ...array_diff_key($extra, ['updated_at' => true]),
        ]);
    }

    private function candidato(int $servico, CandidateStatus $status, ?int $respondeuHaMinutos = null): void
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
            'notified_at' => now()->subMinutes(4),
            'responded_at' => $respondeuHaMinutos === null ? null : now()->subMinutes($respondeuHaMinutos),
            'expires_at' => now()->addMinutes(2),
        ]);
    }

    private function item(array $lista, int $id): array
    {
        $item = collect($lista)->firstWhere('id', (string) $id);
        $this->assertNotNull($item, "o pedido {$id} não está na lista");

        return $item;
    }

    public function test_pedido_sem_ninguem_convidado_e_critico(): void
    {
        $id = $this->servico(ServiceStatus::MATCHING, haMinutos: 5);

        $item = $this->item($this->ler()['a_procura'], $id);

        $this->assertSame(0, $item['convidados']);
        $this->assertSame(['nivel' => 'critico', 'motivo' => 'ninguem_convidado'], $item['alerta']);
    }

    public function test_convidados_que_ja_nao_podem_responder_e_critico(): void
    {
        $id = $this->servico(ServiceStatus::MATCHING, haMinutos: 3);
        $this->candidato($id, CandidateStatus::EXPIRED);
        $this->candidato($id, CandidateStatus::DECLINED, respondeuHaMinutos: 2);

        $item = $this->item($this->ler()['a_procura'], $id);

        $this->assertSame(2, $item['convidados']);
        $this->assertSame('ninguem_a_responder', $item['alerta']['motivo']);
        $this->assertSame('convites', $item['prazo_de']);
        $this->assertNotNull($item['segundos_restantes']);
    }

    public function test_alguem_aceitou_e_a_vez_do_cliente(): void
    {
        $id = $this->servico(ServiceStatus::MATCHING, haMinutos: 2);
        $this->candidato($id, CandidateStatus::ACCEPTED, respondeuHaMinutos: 0);
        $this->candidato($id, CandidateStatus::NOTIFIED);

        $item = $this->item($this->ler()['a_procura'], $id);

        $this->assertSame(1, $item['aceitaram']);
        $this->assertSame(1, $item['por_responder']);
        $this->assertSame('cliente', $item['prazo_de']);
        $this->assertSame('cliente_a_escolher', $item['alerta']['motivo']);
    }

    public function test_personalizado_por_rever_e_critico(): void
    {
        $id = $this->servico(ServiceStatus::PENDING_REVIEW, haMinutos: 60, extra: ['is_custom' => true]);

        $item = $this->item($this->ler()['a_procura'], $id);

        $this->assertSame('personalizado', $item['modo']);
        $this->assertSame('personalizado_por_rever', $item['alerta']['motivo']);
    }

    public function test_os_pedidos_de_teste_ficam_de_fora(): void
    {
        $teste = $this->servico(ServiceStatus::MATCHING, extra: ['is_test' => true]);

        $this->assertNull(collect($this->ler()['a_procura'])->firstWhere('id', (string) $teste));
        $this->assertNotNull(collect($this->ler('?incluir_testes=1')['a_procura'])->firstWhere('id', (string) $teste));
    }

    public function test_em_curso_assinala_o_pagamento_por_capturar_e_o_cliente_que_nao_confirma(): void
    {
        $captura = $this->servico(ServiceStatus::CLOSED_PENDING_PAYMENT);
        $porConfirmar = $this->servico(ServiceStatus::FINISHED, haMinutos: 3000, extra: ['updated_at' => now()->subDays(2)]);
        $recente = $this->servico(ServiceStatus::FINISHED, haMinutos: 60);

        $emCurso = $this->ler()['em_curso'];

        $this->assertSame('pagamento_por_capturar', $this->item($emCurso, $captura)['alerta']['motivo']);
        $this->assertSame('cliente_nao_confirmou', $this->item($emCurso, $porConfirmar)['alerta']['motivo']);
        $this->assertNull($this->item($emCurso, $recente)['alerta']);
    }

    // ---------------------------------------------------------------- oferta

    private function tecnico(StatusVendor $status, ?int $localizacaoHaMinutos, ?ServicesType $tipo = null): Vendor
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'phone_number_verified_at' => now()]);
        $vendor = Vendor::factory()->create([
            'user_id' => $user->id,
            'status' => $status,
            'iban' => 'PT50000000000000000000000',
            'invoice_workspace' => 'ws-'.$user->id,
            'at_user' => '999999999/1',
            'at_valid' => true,
            'at_validated_at' => now(),
        ]);
        if ($tipo) {
            $vendor->servicesTypes()->attach($tipo->id);
        }
        if ($localizacaoHaMinutos !== null) {
            $location = $vendor->currentLocation()->save(new Location(['latitude' => 38.71, 'longitude' => -9.14]));
            Location::whereKey($location->getKey())->update(['updated_at' => now()->subMinutes($localizacaoHaMinutos)]);
        }

        return $vendor;
    }

    public function test_a_oferta_separa_online_de_pronto(): void
    {
        $area = OperationArea::factory()->create(['name' => ['pt-pt' => 'Canalização']]);
        $tipo = ServicesType::factory()->create(['operation_area_id' => $area->id]);
        $this->tecnico(StatusVendor::ONLINE, localizacaoHaMinutos: 10, tipo: $tipo);
        $this->tecnico(StatusVendor::ONLINE, localizacaoHaMinutos: 3 * 24 * 60, tipo: $tipo);
        $this->tecnico(StatusVendor::OFFLINE, localizacaoHaMinutos: 5, tipo: $tipo);

        $oferta = $this->ler()['oferta'];

        $this->assertSame(2, $oferta['online']);
        $this->assertSame(1, $oferta['prontos']);
        $this->assertSame([['area_id' => $area->id, 'area' => 'Canalização', 'online' => 2, 'prontos' => 1]], $oferta['por_area']);
    }

    // -------------------------------------------------------------- liquidez

    public function test_liquidez_conta_os_desfechos_e_o_tempo_ate_ao_primeiro_sim(): void
    {
        $vendor = Vendor::factory()->create();
        $servido = $this->servico(ServiceStatus::CLOSED, haMinutos: 60, extra: ['vendor_id' => $vendor->id]);
        $this->candidato($servido, CandidateStatus::SELECTED, respondeuHaMinutos: 58); // 2 min depois do pedido
        $semOferta = $this->servico(ServiceStatus::MATCHING_FAILED, haMinutos: 120);
        $semResposta = $this->servico(ServiceStatus::MATCHING_FAILED, haMinutos: 180);
        $this->candidato($semResposta, CandidateStatus::EXPIRED);
        $this->servico(ServiceStatus::MATCHING, haMinutos: 1); // ainda aberto
        $this->servico(ServiceStatus::CLOSED, haMinutos: 10 * 24 * 60); // fora dos 7 dias
        $this->servico(ServiceStatus::CLOSED, haMinutos: 40 * 24 * 60); // fora dos 30 dias

        $liquidez = $this->ler()['liquidez'];
        $semana = $liquidez['7d'];

        $this->assertSame(4, $semana['pedidos']);
        $this->assertSame(3, $semana['terminados']);
        $this->assertSame(1, $semana['com_sim']);
        $this->assertSame(1, $semana['servidos']);
        $this->assertSame(33.3, $semana['taxa_servidos']);
        $this->assertSame(120, $semana['mediana_segundos_ate_primeiro_sim']);
        $this->assertSame(1, $semana['por_desfecho']['sem_oferta']);
        $this->assertSame(1, $semana['por_desfecho']['sem_resposta']);
        $this->assertSame(1, $semana['por_desfecho']['em_aberto']);
        $this->assertSame(5, $liquidez['30d']['pedidos']);

        $perdidos = collect($this->ler()['perdidos'])->pluck('desfecho', 'id');
        $this->assertSame('sem_oferta', $perdidos[(string) $semOferta]);
        $this->assertSame('sem_resposta', $perdidos[(string) $semResposta]);
    }

    public function test_sem_pedidos_as_taxas_sao_nulas_e_nao_zero(): void
    {
        $semana = $this->ler()['liquidez']['7d'];

        $this->assertSame(0, $semana['pedidos']);
        $this->assertNull($semana['taxa_servidos']);
        $this->assertNull($semana['mediana_segundos_ate_primeiro_sim']);
    }

    public function test_sem_token_nao_entra(): void
    {
        config(['services.admin_api.token' => 'a-valid-token']);
        $this->getJson('/api/v1/admin/operacoes/ao-vivo')->assertUnauthorized();
    }

    // ----------------------------------------------------------------- agora

    /** Marca o serviço para um dia e hora de Lisboa (a 12/03 Lisboa está em UTC). */
    private function agenda(int $servico, string $dia, string $hora): void
    {
        DB::table('schedule')->insert([
            'vendor_id' => Vendor::factory()->create()->id,
            'customer_id' => User::factory()->create()->id,
            'service_id' => $servico,
            'scheduled_day' => $dia,
            'scheduled_time_start' => $hora,
            'scheduled_time_end' => Carbon::parse($hora)->addHour()->format('H:i:s'),
            'is_pending' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Um passo do histórico: o serviço entrou em `$estado` há `$haMinutos`. */
    private function passou(int $servico, ServiceStatus $estado, int $haMinutos): void
    {
        ServiceEvent::create([
            'service_id' => $servico,
            'tipo' => 'estado',
            'estado_para' => $estado->value,
            'ocorreu_em' => now()->subMinutes($haMinutos),
        ]);
    }

    public function test_agora_conta_os_agendados_de_hoje_que_ainda_nao_comecaram(): void
    {
        $porComecar = $this->servico(ServiceStatus::SCHEDULED, haMinutos: 3000);
        $this->agenda($porComecar, '2031-03-12', '15:00:00');
        // Já a caminho, mas ainda não chegou: não começou.
        $aCaminho = $this->servico(ServiceStatus::ACCEPTED, haMinutos: 3000, extra: ['on_the_way_at' => now()->subMinutes(5)]);
        $this->agenda($aCaminho, '2031-03-12', '10:30:00');
        $jaComecou = $this->servico(ServiceStatus::ARRIVED, haMinutos: 3000);
        $this->agenda($jaComecou, '2031-03-12', '09:00:00');
        $amanha = $this->servico(ServiceStatus::SCHEDULED, haMinutos: 3000);
        $this->agenda($amanha, '2031-03-13', '09:00:00');
        $teste = $this->servico(ServiceStatus::SCHEDULED, haMinutos: 3000, extra: ['is_test' => true]);
        $this->agenda($teste, '2031-03-12', '16:00:00');

        $this->assertSame(['agendados_hoje' => 2], $this->ler()['agora']);
        $this->assertSame(1, $this->ler('?incluir_testes=1')['agora']['agendados_hoje']);
    }

    // ------------------------------------------------------ oferta por cidade

    public function test_a_oferta_por_cidade_usa_as_cidades_que_cada_tecnico_escolheu(): void
    {
        $lisboa = City::firstOrCreate(['name' => 'Lisboa', 'district' => 'Lisboa']);
        $amadora = City::firstOrCreate(['name' => 'Amadora', 'district' => 'Lisboa']);

        $pronto = $this->tecnico(StatusVendor::ONLINE, localizacaoHaMinutos: 10);
        $pronto->availableCities()->attach([$lisboa->id, $amadora->id]);
        $semPosicao = $this->tecnico(StatusVendor::ONLINE, localizacaoHaMinutos: 3 * 24 * 60);
        $semPosicao->availableCities()->attach($lisboa->id);
        $this->tecnico(StatusVendor::ONLINE, localizacaoHaMinutos: 5); // sem cidades
        $this->tecnico(StatusVendor::OFFLINE, localizacaoHaMinutos: 5)->availableCities()->attach($lisboa->id);

        $this->assertSame([
            ['cidade' => 'Lisboa', 'online' => 2, 'podem_aceitar' => 2, 'prontos' => 1],
            ['cidade' => 'Amadora', 'online' => 1, 'podem_aceitar' => 1, 'prontos' => 1],
            ['cidade' => null, 'online' => 1, 'podem_aceitar' => 1, 'prontos' => 1],
        ], $this->ler()['oferta']['por_cidade']);
    }

    // ---------------------------------------------------------- liquidez/mês

    public function test_a_liquidez_do_mes_conta_os_criados_e_os_servidos_desde_o_dia_1(): void
    {
        $vendor = Vendor::factory()->create();
        // 12/03 às 11:00: o mês começou há 11 dias e 11 horas.
        $this->servico(ServiceStatus::CLOSED, haMinutos: 7 * 24 * 60, extra: ['vendor_id' => $vendor->id]);
        $this->servico(ServiceStatus::ARCHIVED, haMinutos: 5 * 24 * 60, extra: ['vendor_id' => $vendor->id]);
        $this->servico(ServiceStatus::MATCHING_FAILED, haMinutos: 2 * 24 * 60);
        $this->servico(ServiceStatus::MATCHING, haMinutos: 1); // aberto: ainda não servido
        $this->servico(ServiceStatus::CLOSED, haMinutos: 12 * 24 * 60, extra: ['vendor_id' => $vendor->id]); // fevereiro
        $this->servico(ServiceStatus::CLOSED, haMinutos: 60, extra: ['vendor_id' => $vendor->id, 'is_test' => true]);

        $mes = $this->ler()['liquidez']['mes'];

        $this->assertSame('2031-03-01T00:00:00+00:00', $mes['desde']);
        $this->assertSame(4, $mes['criados']);
        $this->assertSame(2, $mes['servidos']);
        $this->assertSame(0.5, $mes['taxa']);
    }

    public function test_sem_pedidos_no_mes_a_taxa_e_nula(): void
    {
        $mes = $this->ler()['liquidez']['mes'];

        $this->assertSame(0, $mes['criados']);
        $this->assertNull($mes['taxa']);
    }

    // ---------------------------------------------------------- estado desde

    public function test_cada_pedido_diz_desde_quando_esta_no_estado_e_de_onde_vem_a_hora(): void
    {
        $comHistorico = $this->servico(ServiceStatus::AWAITING_PAYMENT, haMinutos: 40);
        $this->passou($comHistorico, ServiceStatus::MATCHING, haMinutos: 40);
        $this->passou($comHistorico, ServiceStatus::AWAITING_PAYMENT, haMinutos: 3);
        // Sem histórico (pedido anterior a 08/10): fica o updated_at.
        $semHistorico = $this->servico(ServiceStatus::MATCHING, haMinutos: 20, extra: ['updated_at' => now()->subMinutes(15)]);
        // O último passo foi para outro estado: a mudança seguinte não passou
        // pelo modelo, e o histórico não sabe dela.
        $desatualizado = $this->servico(ServiceStatus::FINISHED, haMinutos: 90, extra: ['updated_at' => now()->subMinutes(10)]);
        $this->passou($desatualizado, ServiceStatus::ARRIVED, haMinutos: 60);

        $dados = $this->ler();
        $a = $this->item($dados['a_procura'], $comHistorico);
        $b = $this->item($dados['a_procura'], $semHistorico);
        $c = $this->item($dados['em_curso'], $desatualizado);

        $this->assertSame(now()->subMinutes(3)->toIso8601String(), $a['estado_desde']);
        $this->assertSame('historico', $a['estado_desde_fonte']);
        $this->assertSame(now()->subMinutes(15)->toIso8601String(), $b['estado_desde']);
        $this->assertSame('updated_at', $b['estado_desde_fonte']);
        $this->assertSame(now()->subMinutes(10)->toIso8601String(), $c['estado_desde']);
        $this->assertSame('updated_at', $c['estado_desde_fonte']);
    }

    // ------------------------------------------------- alertas de pedido preso

    public function test_agendado_meia_hora_depois_da_hora_sem_o_tecnico_e_critico(): void
    {
        $atrasado = $this->servico(ServiceStatus::SCHEDULED, haMinutos: 3000);
        $this->agenda($atrasado, '2031-03-12', '10:25:00'); // 35 min
        $aCaminhoEAtrasado = $this->servico(ServiceStatus::ACCEPTED, haMinutos: 3000, extra: ['on_the_way_at' => now()->subMinutes(50)]);
        $this->agenda($aCaminhoEAtrasado, '2031-03-12', '10:00:00');
        $dentroDaTolerancia = $this->servico(ServiceStatus::SCHEDULED, haMinutos: 3000);
        $this->agenda($dentroDaTolerancia, '2031-03-12', '10:45:00'); // 15 min

        $emCurso = $this->ler()['em_curso'];

        $this->assertSame(['nivel' => 'critico', 'motivo' => 'tecnico_nao_chegou'], $this->item($emCurso, $atrasado)['alerta']);
        $this->assertSame('tecnico_nao_chegou', $this->item($emCurso, $aCaminhoEAtrasado)['alerta']['motivo']);
        $this->assertNull($this->item($emCurso, $dentroDaTolerancia)['alerta']);
    }

    public function test_imediato_conta_a_viagem_antes_do_atraso(): void
    {
        $limite = OperacoesAoVivoController::MINUTOS_DE_VIAGEM_IMEDIATO + OperacoesAoVivoController::MINUTOS_DE_ATRASO_SEM_CHEGAR;

        $atrasado = $this->servico(ServiceStatus::ACCEPTED, haMinutos: $limite + 20);
        $this->passou($atrasado, ServiceStatus::ACCEPTED, haMinutos: $limite + 5);
        $aChegar = $this->servico(ServiceStatus::ACCEPTED, haMinutos: $limite + 20);
        $this->passou($aChegar, ServiceStatus::ACCEPTED, haMinutos: $limite - 5);

        $emCurso = $this->ler()['em_curso'];

        $this->assertSame('tecnico_nao_chegou', $this->item($emCurso, $atrasado)['alerta']['motivo']);
        $this->assertNull($this->item($emCurso, $aChegar)['alerta']);
    }

    public function test_em_execucao_ha_mais_de_oito_horas_pede_atencao(): void
    {
        $horas = OperacoesAoVivoController::HORAS_EM_EXECUCAO;
        $esquecido = $this->servico(ServiceStatus::ARRIVED, haMinutos: 900, extra: ['arrived_at' => now()->subHours($horas)->subMinute()]);
        $normal = $this->servico(ServiceStatus::ARRIVED, haMinutos: 900, extra: ['arrived_at' => now()->subHours(2)]);

        $emCurso = $this->ler()['em_curso'];

        $this->assertSame(['nivel' => 'atencao', 'motivo' => 'em_execucao_ha_muito'], $this->item($emCurso, $esquecido)['alerta']);
        $this->assertNull($this->item($emCurso, $normal)['alerta']);
    }

    public function test_um_dia_a_espera_do_cliente_pede_atencao(): void
    {
        // Um extra por aprovar há mais de um dia ganha ao "em execução há
        // muito": é o que explica o pedido estar parado.
        $extraParado = $this->servico(ServiceStatus::ARRIVED, haMinutos: 30 * 60, extra: ['arrived_at' => now()->subHours(26)]);
        DB::table('service_extras')->insert([
            'service_id' => $extraParado, 'type' => 'time', 'minutes' => 30, 'amount' => 1500, 'status' => 'pending',
            'created_at' => now()->subHours(25), 'updated_at' => now()->subHours(25),
        ]);
        $extraRecente = $this->servico(ServiceStatus::ARRIVED, haMinutos: 120, extra: ['arrived_at' => now()->subHour()]);
        DB::table('service_extras')->insert([
            'service_id' => $extraRecente, 'type' => 'time', 'minutes' => 30, 'amount' => 1500, 'status' => 'pending',
            'created_at' => now()->subMinutes(30), 'updated_at' => now()->subMinutes(30),
        ]);
        // 3-D Secure por confirmar há mais de um dia: nenhum prazo o apanha.
        $cartao = $this->servico(ServiceStatus::PENDING_3DS, haMinutos: 27 * 60);
        $this->passou($cartao, ServiceStatus::PENDING_3DS, haMinutos: 25 * 60);

        $dados = $this->ler();

        $this->assertSame(['nivel' => 'atencao', 'motivo' => 'cliente_sem_resposta'], $this->item($dados['em_curso'], $extraParado)['alerta']);
        $this->assertNull($this->item($dados['em_curso'], $extraRecente)['alerta']);
        $this->assertSame(['nivel' => 'atencao', 'motivo' => 'cliente_sem_resposta'], $this->item($dados['a_procura'], $cartao)['alerta']);
    }
}
