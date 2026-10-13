<?php

namespace Tests\Feature;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Enums\Vendors\StatusVendor;
use App\Models\GeneralSettings\AllowedZone;
use App\Models\GeneralSettings\City;
use App\Models\GeneralSettings\Document;
use App\Models\GeneralSettings\OperationArea;
use App\Models\GeneralSettings\ServicesType;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Vendor\VendorDocuments;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A lista de técnicos do backoffice: o estado de cada um (online, pronto, em
 * aprovação, suspenso) e o filtro por ele, os documentos em falta, a cidade e
 * a categoria em que trabalha, a pesquisa, a ordem "Online primeiro" e o
 * resumo dos serviços e da nota.
 *
 * As listas comparam-se só com os técnicos que CADA teste criou.
 */
class AdminListaDeTecnicosTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<int> */
    private array $criados = [];

    private Document $cartaoDeCidadao;

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null']);
        config(['services.admin_api.token' => 'a-valid-token']);
        Notification::fake();
        Queue::fake();

        // Os documentos obrigatórios são os DESTE teste. Outra classe pode ter
        // deixado documentos na base, e um obrigatório a mais tornava toda a
        // gente "em aprovação". Dentro da transação: no fim volta tudo.
        DB::table('documents')->delete();
        $this->cartaoDeCidadao = Document::create(['name' => 'Cartão de Cidadão', 'required' => true]);
    }

    private function api(): static
    {
        return $this->withHeaders(['Authorization' => 'Bearer a-valid-token']);
    }

    private function tecnico(StatusVendor $estado = StatusVendor::OFFLINE, array $pessoa = []): Vendor
    {
        $tecnico = Vendor::factory()->create([
            'status' => $estado,
            'user_id' => User::factory()->create($pessoa)->id,
        ]);
        $this->criados[] = $tecnico->id;

        return $tecnico;
    }

    private function entregar(Vendor $tecnico, Document $documento, string $estado = 'approved', ?string $validade = null): void
    {
        VendorDocuments::create([
            'vendor_id' => $tecnico->id,
            'document_id' => $documento->id,
            'status' => $estado,
            'expiration_date' => $validade,
        ]);
    }

    private function servico(Vendor $tecnico, ServiceStatus $status, ?int $nota = null, bool $teste = false, ?CarbonInterface $em = null): void
    {
        $em ??= now();

        DB::table('services')->insert([
            'vendor_id' => $tecnico->id,
            'status' => $status->value,
            'payment_status' => PaymentStatus::PAID->value,
            'rating_by_customer' => $nota,
            'is_test' => $teste,
            'created_at' => $em,
            'updated_at' => $em,
        ]);
    }

    private function localizacao(Vendor $tecnico, CarbonInterface $em): void
    {
        DB::table('vendors_location')->insert([
            'vendor_id' => $tecnico->id,
            'latitude' => 38.72,
            'longitude' => -9.14,
            'created_at' => $em,
            'updated_at' => $em,
        ]);
    }

    private function itensPorOrdem(string $query = ''): array
    {
        $separador = str_contains($query, '?') ? '&' : '?';

        return $this->api()->getJson('/api/v1/admin/vendors'.$query.$separador.'per_page=100')->assertOk()->json('data.items');
    }

    /** Os ids devolvidos, PELA ORDEM da resposta, só dos criados aqui. */
    private function ids(string $query = ''): array
    {
        return collect($this->itensPorOrdem($query))
            ->pluck('id')
            ->filter(fn (int $id) => in_array($id, $this->criados, true))
            ->values()
            ->all();
    }

    /** @return array<int, array> */
    private function itens(string $query = ''): array
    {
        return collect($this->itensPorOrdem($query))->keyBy('id')->all();
    }

    // -------------------------------------------------------------- estado

    public function test_o_estado_que_se_mostra_e_o_estado_por_que_se_filtra(): void
    {
        $online = $this->tecnico(StatusVendor::ONLINE);
        $this->entregar($online, $this->cartaoDeCidadao);
        $pronto = $this->tecnico();
        $this->entregar($pronto, $this->cartaoDeCidadao);
        $porRever = $this->tecnico();
        $this->entregar($porRever, $this->cartaoDeCidadao, 'pending');
        $caducado = $this->tecnico();
        $this->entregar($caducado, $this->cartaoDeCidadao, 'approved', now()->subDay()->toDateString());
        $suspenso = $this->tecnico();
        $this->entregar($suspenso, $this->cartaoDeCidadao);
        $suspenso->delete();
        // Suspenso com o interruptor ainda em Online: é suspenso.
        $suspensoOnline = $this->tecnico(StatusVendor::ONLINE);
        $suspensoOnline->delete();

        $estados = collect($this->itens('?incluir_suspensos=1'))->map(fn ($t) => $t['estado']);

        $this->assertSame('online', $estados[$online->id]);
        $this->assertSame('pronto', $estados[$pronto->id]);
        $this->assertSame('em_aprovacao', $estados[$porRever->id]);
        $this->assertSame('em_aprovacao', $estados[$caducado->id]);
        $this->assertSame('suspenso', $estados[$suspenso->id]);
        $this->assertSame('suspenso', $estados[$suspensoOnline->id]);

        $this->assertSame([$online->id], $this->ids('?estado=online'));
        $this->assertSame([$pronto->id], $this->ids('?estado=pronto'));
        $this->assertEqualsCanonicalizing([$porRever->id, $caducado->id], $this->ids('?estado=em_aprovacao'));
        $this->assertEqualsCanonicalizing([$suspenso->id, $suspensoOnline->id], $this->ids('?estado=suspenso'));
        // Pedir os suspensos não faz de um suspenso "online".
        $this->assertSame([$online->id], $this->ids('?estado=online&incluir_suspensos=1'));
    }

    public function test_um_estado_que_nao_existe_e_recusado(): void
    {
        $this->api()->getJson('/api/v1/admin/vendors?estado=ativo')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['estado']);
    }

    public function test_filtra_quem_tem_documentos_em_falta(): void
    {
        $emDia = $this->tecnico(StatusVendor::ONLINE);
        $this->entregar($emDia, $this->cartaoDeCidadao);
        $nuncaEntregou = $this->tecnico(StatusVendor::ONLINE);
        $recusado = $this->tecnico();
        $this->entregar($recusado, $this->cartaoDeCidadao, 'declined');
        $porRever = $this->tecnico();
        $this->entregar($porRever, $this->cartaoDeCidadao, 'pending');

        $this->assertEqualsCanonicalizing(
            [$nuncaEntregou->id, $recusado->id, $porRever->id],
            $this->ids('?documentos_em_falta=1'),
        );
    }

    /**
     * A regra dos documentos existe duas vezes -- o atributo
     * `all_documents_verified` (PHP) e Vendor::sqlDocumentosEmDia() (SQL, para
     * filtrar). Este teste é o que as mantém iguais: cada caso tem de dar a
     * mesma resposta nas duas.
     */
    public function test_a_regra_em_sql_diz_o_mesmo_que_o_atributo(): void
    {
        $registoCriminal = Document::create(['name' => 'Registo criminal', 'required' => true]);
        $certificadoDoGas = Document::create(['name' => 'Certificado do gás', 'required' => false]);
        $certificadoDaLuz = Document::create(['name' => 'Certificado DGEG', 'required' => false]);
        $apagado = Document::create(['name' => 'Documento antigo', 'required' => true]);
        $apagado->delete();

        // O Filament grava os ids das certificações como texto; podem vir números.
        $gas = OperationArea::factory()->create(['documents' => [(string) $certificadoDoGas->id]]);
        $luz = OperationArea::factory()->create(['documents' => [$certificadoDaLuz->id]]);
        $areaApagada = OperationArea::factory()->create(['documents' => [(string) $certificadoDoGas->id]]);
        $areaApagada->delete();

        $obrigatorios = fn (Vendor $t) => [
            $this->entregar($t, $this->cartaoDeCidadao),
            $this->entregar($t, $registoCriminal),
        ];

        $casos = [];

        $casos['tudo aprovado'] = $t = $this->tecnico();
        $obrigatorios($t);

        $casos['um por rever'] = $t = $this->tecnico();
        $this->entregar($t, $this->cartaoDeCidadao);
        $this->entregar($t, $registoCriminal, 'pending');

        $casos['um caducado ontem'] = $t = $this->tecnico();
        $this->entregar($t, $this->cartaoDeCidadao);
        $this->entregar($t, $registoCriminal, 'approved', now()->subDay()->toDateString());

        $casos['a caducar hoje'] = $t = $this->tecnico();
        $this->entregar($t, $this->cartaoDeCidadao);
        $this->entregar($t, $registoCriminal, 'approved', now()->toDateString());

        $casos['um recusado'] = $t = $this->tecnico();
        $this->entregar($t, $this->cartaoDeCidadao);
        $this->entregar($t, $registoCriminal, 'declined');

        $casos['recusado e depois aprovado'] = $t = $this->tecnico();
        $obrigatorios($t);
        $this->entregar($t, $registoCriminal, 'declined');

        $casos['nada entregue'] = $this->tecnico();

        $casos['área do gás sem o certificado'] = $t = $this->tecnico();
        $obrigatorios($t);
        $t->operationAreas()->attach($gas->id);

        $casos['área do gás com o certificado'] = $t = $this->tecnico();
        $obrigatorios($t);
        $t->operationAreas()->attach($gas->id);
        $this->entregar($t, $certificadoDoGas);

        $casos['área da luz (id em número) sem o certificado'] = $t = $this->tecnico();
        $obrigatorios($t);
        $t->operationAreas()->attach($luz->id);

        $casos['área apagada não exige nada'] = $t = $this->tecnico();
        $obrigatorios($t);
        $t->operationAreas()->attach($areaApagada->id);

        [$sql, $bindings] = Vendor::sqlDocumentosEmDia();
        $emSql = Vendor::query()
            ->select('vendors.id')
            ->selectRaw("CASE WHEN {$sql} THEN 1 ELSE 0 END AS em_dia", $bindings)
            ->whereIn('vendors.id', collect($casos)->pluck('id'))
            ->pluck('em_dia', 'id');

        $estados = collect($this->itens())->map(fn ($t) => $t['estado']);

        $esperado = [
            'tudo aprovado' => true,
            'um por rever' => false,
            'um caducado ontem' => false,
            'a caducar hoje' => true,
            'um recusado' => false,
            'recusado e depois aprovado' => true,
            'nada entregue' => false,
            'área do gás sem o certificado' => false,
            'área do gás com o certificado' => true,
            'área da luz (id em número) sem o certificado' => false,
            'área apagada não exige nada' => true,
        ];

        foreach ($casos as $caso => $tecnico) {
            $doAtributo = Vendor::query()->findOrFail($tecnico->id)->all_documents_verified;

            $this->assertSame($esperado[$caso], $doAtributo, "Atributo, caso: {$caso}");
            $this->assertSame($doAtributo, (bool) $emSql[$tecnico->id], "SQL, caso: {$caso}");
            $this->assertSame($doAtributo ? 'pronto' : 'em_aprovacao', $estados[$tecnico->id], "Lista, caso: {$caso}");
        }
    }

    // --------------------------------------------- onde e o que faz

    public function test_filtra_pela_cidade_onde_trabalha(): void
    {
        // As cidades já vêm semeadas pelas migrações.
        $lisboa = City::firstOrCreate(['name' => 'Lisboa', 'district' => 'Lisboa']);
        $porto = City::firstOrCreate(['name' => 'Porto', 'district' => 'Porto']);
        $zonaDeLisboa = AllowedZone::create(['city' => 'Lisboa']);

        $pelaApp = $this->tecnico();
        $pelaApp->availableCities()->attach($lisboa->id);
        $peloQuestionario = $this->tecnico();
        $peloQuestionario->allowedZones()->attach($zonaDeLisboa->id);
        $doPorto = $this->tecnico();
        $doPorto->availableCities()->attach($porto->id);
        $this->tecnico();

        $this->assertEqualsCanonicalizing([$pelaApp->id, $peloQuestionario->id], $this->ids('?cidade=Lisboa'));
        $this->assertEqualsCanonicalizing([$pelaApp->id, $peloQuestionario->id], $this->ids('?cidade=lisboa'));
        $this->assertSame([$doPorto->id], $this->ids('?cidade=Porto'));
    }

    public function test_filtra_pela_categoria_em_que_trabalha(): void
    {
        $canalizacao = OperationArea::factory()->create();
        $eletricidade = OperationArea::factory()->create();
        $desentupir = ServicesType::factory()->create(['operation_area_id' => $canalizacao->id]);
        $tomadas = ServicesType::factory()->create(['operation_area_id' => $eletricidade->id]);

        $pelosTipos = $this->tecnico();
        $pelosTipos->servicesTypes()->attach($desentupir->id);
        $pelaArea = $this->tecnico();
        $pelaArea->operationAreas()->attach($canalizacao->id);
        $deOutra = $this->tecnico();
        $deOutra->servicesTypes()->attach($tomadas->id);
        // Deixou de fazer desentupimentos: a ligação está apagada.
        $deixouDeFazer = $this->tecnico();
        DB::table('services_type_vendor')->insert([
            'vendor_id' => $deixouDeFazer->id,
            'services_type_id' => $desentupir->id,
            'deleted_at' => now(),
        ]);

        $this->assertEqualsCanonicalizing([$pelosTipos->id, $pelaArea->id], $this->ids("?categoria={$canalizacao->id}"));
        $this->assertSame([$deOutra->id], $this->ids("?categoria={$eletricidade->id}"));
    }

    // ----------------------------------------------------------- pesquisa

    public function test_procura_pelo_nome_completo_telefone_email_e_nif(): void
    {
        $alvo = $this->tecnico(pessoa: [
            'first_name' => 'Rui',
            'last_name' => 'Canalizador',
            'phone_number' => '+351936111222',
            'email' => 'rui.canos@example.test',
            'nif' => '245678901',
        ]);
        $this->tecnico(pessoa: [
            'first_name' => 'Rui',
            'last_name' => 'Eletricista',
            'phone_number' => '+351912000000',
            'email' => 'outro@example.test',
            'nif' => '198765432',
        ]);

        foreach (['Rui Canalizador', '936111222', '936 111 222', '+351 936 111 222', '00351936111222', 'rui.canos@', '245678901'] as $termo) {
            $this->assertSame([$alvo->id], $this->ids('?search='.urlencode($termo)), "Termo: {$termo}");
        }
    }

    public function test_os_suspensos_so_aparecem_quando_se_pedem(): void
    {
        $ativo = $this->tecnico();
        $suspenso = $this->tecnico();
        $suspenso->delete();

        $this->assertSame([$ativo->id], $this->ids());
        $this->assertEqualsCanonicalizing([$ativo->id, $suspenso->id], $this->ids('?incluir_suspensos=1'));
        $this->assertSame([$suspenso->id], $this->ids('?suspended=1'));
        $this->assertNotNull($this->itens('?incluir_suspensos=1')[$suspenso->id]['suspended_at']);
    }

    // -------------------------------------------------------------- ordem

    public function test_ordena_os_online_primeiro_e_depois_pela_ultima_atividade(): void
    {
        $this->travelTo(now()->startOfSecond());

        $offlineAtivoHaPouco = $this->tecnico();
        $this->localizacao($offlineAtivoHaPouco, now()->subMinutes(5));
        $onlineParado = $this->tecnico(StatusVendor::ONLINE);
        $this->localizacao($onlineParado, now()->subDays(2));
        $onlineAgora = $this->tecnico(StatusVendor::ONLINE);
        $this->localizacao($onlineAgora, now()->subMinute());
        // Sem localização, mas com um serviço há uma hora.
        $offlineComServico = $this->tecnico();
        $this->servico($offlineComServico, ServiceStatus::CLOSED, em: now()->subHour());
        $offlineNunca = $this->tecnico();

        $this->assertSame([
            $onlineAgora->id, $onlineParado->id,
            $offlineAtivoHaPouco->id, $offlineComServico->id, $offlineNunca->id,
        ], $this->ids('?sort=online'));

        $itens = $this->itens();
        $this->assertSame(now()->subMinute()->toIso8601String(), $itens[$onlineAgora->id]['last_active_at']);
        $this->assertSame(now()->subHour()->toIso8601String(), $itens[$offlineComServico->id]['last_active_at']);
        $this->assertNull($itens[$offlineNunca->id]['last_active_at']);
    }

    // ------------------------------------------------------------- resumo

    public function test_cada_tecnico_traz_os_servicos_e_a_nota(): void
    {
        $this->travelTo(now()->startOfSecond());

        $tecnico = $this->tecnico();
        $this->servico($tecnico, ServiceStatus::CLOSED, nota: 5, em: now()->subDays(2));
        // Fora dos 30 dias, mas a nota conta.
        $this->servico($tecnico, ServiceStatus::CLOSED, nota: 4, em: now()->subDays(40));
        $this->servico($tecnico, ServiceStatus::ARRIVED, em: now()->subDay());
        // Por cobrar: é trabalho dele, mas a nota só conta nos fechados.
        $this->servico($tecnico, ServiceStatus::CLOSED_PENDING_PAYMENT, nota: 1, em: now()->subDays(3));
        // Escolhido, mas o cliente não pagou: não foi trabalho nenhum.
        $this->servico($tecnico, ServiceStatus::EXPIRED_MBWAY, em: now());
        // De teste: não conta para nada.
        $this->servico($tecnico, ServiceStatus::CLOSED, nota: 1, teste: true, em: now());

        $semNada = $this->tecnico();

        $item = $this->itens()[$tecnico->id];

        $this->assertSame(3, $item['services_30d_count']);
        $this->assertSame(now()->subDay()->toIso8601String(), $item['last_service_at']);
        $this->assertEquals(4.5, $item['rating_avg']);
        $this->assertSame(2, $item['rating_count']);

        $vazio = $this->itens()[$semNada->id];
        $this->assertSame(0, $vazio['services_30d_count']);
        $this->assertNull($vazio['last_service_at']);
        $this->assertNull($vazio['rating_avg']);
        $this->assertSame(0, $vazio['rating_count']);
    }

    public function test_a_ficha_e_as_acoes_trazem_o_resumo_e_o_estado(): void
    {
        $tecnico = $this->tecnico();
        $this->entregar($tecnico, $this->cartaoDeCidadao);
        $this->servico($tecnico, ServiceStatus::CLOSED, nota: 5);

        $this->api()->getJson("/api/v1/admin/vendors/{$tecnico->id}")->assertOk()
            ->assertJsonPath('data.estado', 'pronto')
            ->assertJsonPath('data.services_30d_count', 1)
            ->assertJsonPath('data.rating_count', 1);

        $this->api()->putJson("/api/v1/admin/vendors/{$tecnico->id}/suspend")->assertOk()
            ->assertJsonPath('data.estado', 'suspenso')
            ->assertJsonPath('data.services_30d_count', 1);

        $this->api()->putJson("/api/v1/admin/vendors/{$tecnico->id}/restore")->assertOk()
            ->assertJsonPath('data.estado', 'pronto');
    }

    /** O resumo sai da consulta da página, e não de uma consulta por técnico. */
    public function test_o_resumo_nao_faz_uma_consulta_por_tecnico(): void
    {
        foreach (range(1, 4) as $i) {
            $this->servico($this->tecnico(), ServiceStatus::CLOSED, nota: 4);
        }

        DB::enableQueryLog();
        $this->api()->getJson('/api/v1/admin/vendors?per_page=100')->assertOk();
        $consultas = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        foreach (['documentos_em_dia', 'services_30d_count', 'last_service_at', 'rating_avg', 'rating_count', 'last_location_at'] as $coluna) {
            $this->assertCount(1, $consultas->filter(fn ($q) => str_contains($q, $coluna)), $coluna);
        }
    }
}
