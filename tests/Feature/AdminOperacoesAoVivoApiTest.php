<?php

namespace Tests\Feature;

use App\Enums\Services\CandidateStatus;
use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Enums\Vendors\StatusVendor;
use App\Models\GeneralSettings\OperationArea;
use App\Models\GeneralSettings\ServicesType;
use App\Models\ServiceCandidate;
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
}
