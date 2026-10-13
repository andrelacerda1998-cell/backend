<?php

namespace Tests\Feature;

use App\Enums\Services\CandidateStatus;
use App\Enums\Services\ServiceStatus;
use App\Enums\Vendors\StatusVendor;
use App\Models\GeneralSettings\City;
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
 * Mercado: procura e oferta por semana, por cidade e por categoria.
 *
 * O relógio fica numa quarta-feira de março de 2031 (Lisboa em UTC+0), longe
 * dos serviços que outros testes deixam com a data real.
 */
class AdminMercadoApiTest extends TestCase
{
    use RefreshDatabase;

    private OperationArea $canalizacao;

    private OperationArea $eletricidade;

    private ServicesType $desentupir;

    private ServicesType $tomada;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2031-03-12 11:00:00')->utc());

        $this->canalizacao = OperationArea::factory()->create(['name' => ['pt-pt' => 'Canalização']]);
        $this->eletricidade = OperationArea::factory()->create(['name' => ['pt-pt' => 'Eletricidade']]);
        $this->desentupir = ServicesType::factory()->create(['operation_area_id' => $this->canalizacao->id]);
        $this->tomada = ServicesType::factory()->create(['operation_area_id' => $this->eletricidade->id]);
    }

    private function ler(string $query = ''): array
    {
        config(['services.admin_api.token' => 'a-valid-token']);

        return $this->withHeaders(['Authorization' => 'Bearer a-valid-token'])
            ->getJson('/api/v1/admin/mercado'.$query)
            ->assertOk()
            ->json('data');
    }

    private function servico(ServiceStatus $status, string $criadoEm = '2031-03-11 10:00:00', array $extra = []): int
    {
        $quando = Carbon::parse($criadoEm, 'UTC');
        $cidade = $extra['cidade'] ?? 'Lisboa';
        unset($extra['cidade']);

        return DB::table('services')->insertGetId([
            'status' => $status->value,
            'payment_status' => 'Pending',
            'is_test' => false,
            'services_type_id' => $this->desentupir->id,
            'address' => json_encode(['city' => $cidade, 'latitude' => 38.7, 'longitude' => -9.1]),
            'created_at' => $quando,
            'updated_at' => $quando,
            ...$extra,
        ]);
    }

    private function candidato(int $servico, CandidateStatus $status, ?string $respondeuEm = null): void
    {
        ServiceCandidate::create([
            'service_id' => $servico,
            // Offline: quem só serve de convidado não entra na oferta de agora.
            'vendor_id' => Vendor::factory()->create(['status' => StatusVendor::OFFLINE])->id,
            'rank' => 1,
            'wave' => 1,
            'status' => $status,
            'quoted_amount' => 5000,
            'quoted_amount_for_vendor' => 3750,
            'quoted_distance' => 3,
            'notified_at' => now()->subMinutes(4),
            'responded_at' => $respondeuEm ? Carbon::parse($respondeuEm, 'UTC') : null,
            'expires_at' => now()->addMinutes(2),
        ]);
    }

    private function cidade(string $nome): City
    {
        return City::firstOrCreate(['name' => $nome], ['district' => 'Lisboa']);
    }

    /** Um técnico que cumpre tudo para aceitar; a posição decide se está "pronto". */
    private function tecnico(StatusVendor $status, ?int $posicaoHaMinutos, array $tipos = [], array $cidades = [], bool $teste = false, string $nome = 'Rui Silva'): Vendor
    {
        [$primeiro, $ultimo] = explode(' ', $nome, 2);
        $user = User::factory()->create([
            'first_name' => $primeiro,
            'last_name' => $ultimo,
            'email_verified_at' => now(),
            'phone_number_verified_at' => now(),
        ]);
        DB::table('users')->where('id', $user->id)->update(['is_test' => $teste]);

        $vendor = Vendor::factory()->create([
            'user_id' => $user->id,
            'status' => $status,
            'iban' => 'PT50000000000000000000000',
            'invoice_workspace' => 'ws-'.$user->id,
            'at_user' => '999999999/1',
            'at_valid' => true,
            'at_validated_at' => now(),
        ]);
        foreach ($tipos as $tipo) {
            $vendor->servicesTypes()->attach($tipo->id);
        }
        foreach ($cidades as $nomeDaCidade) {
            $vendor->availableCities()->attach($this->cidade($nomeDaCidade)->id);
        }
        if ($posicaoHaMinutos !== null) {
            $posicao = $vendor->currentLocation()->save(new Location(['latitude' => 38.71, 'longitude' => -9.14]));
            Location::whereKey($posicao->getKey())->update(['updated_at' => now()->subMinutes($posicaoHaMinutos)]);
        }

        return $vendor;
    }

    // --------------------------------------------------------------- semanas

    public function test_por_omissao_sao_as_12_semanas_completas_mais_a_atual(): void
    {
        $vendor = Vendor::factory()->create();
        $this->servico(ServiceStatus::CLOSED, '2030-12-16 09:00:00', ['vendor_id' => $vendor->id]); // primeira segunda
        $this->servico(ServiceStatus::CLOSED, '2030-12-15 23:00:00', ['vendor_id' => $vendor->id]); // fora
        $this->servico(ServiceStatus::CLOSED, '2031-03-09 23:30:00', ['vendor_id' => $vendor->id]); // domingo
        $this->servico(ServiceStatus::MATCHING_FAILED, '2031-03-10 00:30:00');
        $this->servico(ServiceStatus::CLOSED, '2031-03-11 10:00:00', ['vendor_id' => $vendor->id]);
        $this->servico(ServiceStatus::CLOSED, '2031-03-11 10:00:00', ['vendor_id' => $vendor->id, 'is_test' => true]);

        $dados = $this->ler();
        $semanas = collect($dados['semanas'])->keyBy('inicio');

        $this->assertSame(['de' => '2030-12-16', 'ate' => '2031-03-12'], $dados['periodo']);
        $this->assertCount(13, $dados['semanas']);
        $this->assertSame('2030-12-16', $dados['semanas'][0]['inicio']);
        $this->assertSame(['inicio' => '2030-12-16', 'criados' => 1, 'servidos' => 1], $semanas['2030-12-16']);
        $this->assertSame(['inicio' => '2031-03-03', 'criados' => 1, 'servidos' => 1], $semanas['2031-03-03']);
        $this->assertSame(['inicio' => '2031-03-10', 'criados' => 2, 'servidos' => 1], $semanas['2031-03-10']);
        $this->assertSame(4, collect($dados['semanas'])->sum('criados'));
    }

    public function test_a_semana_e_a_de_lisboa_mesmo_na_mudanca_da_hora(): void
    {
        // A hora muda a 30/03/2031: a segunda 31/03 começa às 23:00 UTC de domingo.
        $this->travelTo(Carbon::parse('2031-04-08 11:00:00')->utc());
        $this->servico(ServiceStatus::MATCHING_FAILED, '2031-03-30 22:30:00'); // domingo 23:30 em Lisboa
        $this->servico(ServiceStatus::MATCHING_FAILED, '2031-03-30 23:30:00'); // segunda 00:30 em Lisboa

        $semanas = collect($this->ler('?de=2031-03-24&ate=2031-04-06')['semanas'])->pluck('criados', 'inicio')->all();

        $this->assertSame(['2031-03-24' => 1, '2031-03-31' => 1], $semanas);
    }

    // ------------------------------------------------------ tempo até aceitar

    public function test_tempo_ate_aceitar_conta_desde_a_entrada_em_selecao(): void
    {
        $a = $this->servico(ServiceStatus::CLOSED, '2031-03-11 10:00:00');
        $this->candidato($a, CandidateStatus::SELECTED, '2031-03-11 10:01:00'); // 60 s
        $this->candidato($a, CandidateStatus::LOST, '2031-03-11 10:05:00');
        $b = $this->servico(ServiceStatus::MATCHING_FAILED, '2031-03-11 10:00:00');
        $this->candidato($b, CandidateStatus::LOST, '2031-03-11 10:02:00'); // 120 s
        $c = $this->servico(ServiceStatus::MATCHING, '2031-03-11 10:00:00');
        $this->candidato($c, CandidateStatus::ACCEPTED, '2031-03-11 10:10:00'); // 600 s
        // Personalizado: duas horas em análise no backoffice não são demora
        // dos técnicos. Conta do envio (10:00) ao sim (10:05): 300 s.
        $d = $this->servico(ServiceStatus::MATCHING, '2031-03-11 08:00:00', [
            'is_custom' => true, 'services_type_id' => null, 'custom_dispatched_at' => '2031-03-11 10:00:00',
        ]);
        $this->candidato($d, CandidateStatus::ACCEPTED, '2031-03-11 10:05:00');
        // Sem nenhum sim: fora da amostra.
        $e = $this->servico(ServiceStatus::MATCHING_FAILED, '2031-03-11 10:00:00');
        $this->candidato($e, CandidateStatus::DECLINED, '2031-03-11 10:01:00');
        $this->candidato($e, CandidateStatus::EXPIRED);

        $tempo = $this->ler()['tempo_ate_aceitar'];

        // 60, 120, 300, 600 s: a mediana é 210 s, o p90 é 510 s.
        $this->assertSame(['mediana_min' => 3.5, 'p90_min' => 8.5, 'amostra' => 4], $tempo);
    }

    public function test_sem_nenhum_sim_o_tempo_e_nulo(): void
    {
        $this->assertSame(['mediana_min' => null, 'p90_min' => null, 'amostra' => 0], $this->ler()['tempo_ate_aceitar']);
    }

    // -------------------------------------------------------- como acabaram

    public function test_como_acabaram_separa_os_desfechos_e_soma_os_criados(): void
    {
        $vendor = Vendor::factory()->create();
        $this->servico(ServiceStatus::CLOSED, extra: ['vendor_id' => $vendor->id]);
        $this->servico(ServiceStatus::SCHEDULED, extra: ['vendor_id' => $vendor->id]);
        $this->servico(ServiceStatus::MATCHING);
        $this->servico(ServiceStatus::MATCHING_FAILED); // ninguém para convidar
        $semResposta = $this->servico(ServiceStatus::MATCHING_FAILED);
        $this->candidato($semResposta, CandidateStatus::EXPIRED);
        $recusado = $this->servico(ServiceStatus::MATCHING_FAILED);
        $this->candidato($recusado, CandidateStatus::DECLINED, '2031-03-11 10:01:00');
        $this->candidato($recusado, CandidateStatus::EXPIRED);
        $this->servico(ServiceStatus::REFUSED, extra: ['vendor_id' => $vendor->id]);
        $semEscolha = $this->servico(ServiceStatus::MATCHING_FAILED);
        $this->candidato($semEscolha, CandidateStatus::LOST, '2031-03-11 10:01:00');
        $this->servico(ServiceStatus::EXPIRED_MBWAY, extra: ['vendor_id' => $vendor->id]);
        $this->servico(ServiceStatus::CANCELED);
        $this->servico(ServiceStatus::CANCELED, extra: ['vendor_id' => $vendor->id, 'vendor_canceled_at' => '2031-03-11 12:00:00']);
        $this->servico(ServiceStatus::CANCELED, extra: ['vendor_id' => $vendor->id, 'vendor_no_show_at' => '2031-03-11 12:00:00']);
        $this->servico(ServiceStatus::ARCHIVED);

        $acabaram = $this->ler()['como_acabaram'];

        $this->assertSame([
            'servidos' => 2,
            'sem_resposta' => 1,
            'recusados' => 2,
            'cancelados_cliente' => 1,
            'cancelados_tecnico' => 2,
            'em_curso' => 1,
            'sem_oferta' => 1,
            'sem_escolha' => 1,
            'pagamento_falhou' => 1,
            'outros' => 1,
        ], $acabaram);
        $this->assertSame(collect($this->ler()['semanas'])->sum('criados'), array_sum($acabaram));
    }

    // ---------------------------------------------------------------- matriz

    private function cenarioDaMatriz(): array
    {
        $vendor = Vendor::factory()->create(['status' => StatusVendor::OFFLINE]);
        // A morada escreve "lisboa"; o catálogo, "Lisboa". É a mesma cidade.
        $servido = $this->servico(ServiceStatus::CLOSED, extra: ['cidade' => 'lisboa', 'vendor_id' => $vendor->id]);
        $falhado = $this->servico(ServiceStatus::MATCHING_FAILED, extra: ['cidade' => 'Lisboa']);
        $personalizado = $this->servico(ServiceStatus::MATCHING_FAILED, extra: ['cidade' => 'Lisboa', 'is_custom' => true, 'services_type_id' => null]);
        DB::table('service_operation_area')->insert([
            ['service_id' => $personalizado, 'operation_area_id' => $this->canalizacao->id],
            ['service_id' => $personalizado, 'operation_area_id' => $this->eletricidade->id],
        ]);
        $foraDoCatalogo = $this->servico(ServiceStatus::MATCHING_FAILED, extra: ['cidade' => 'Vila Inventada', 'services_type_id' => $this->tomada->id]);

        $this->tecnico(StatusVendor::ONLINE, 10, [$this->desentupir], ['Lisboa'], nome: 'Ana Pronta');
        $this->tecnico(StatusVendor::ONLINE, 9 * 24 * 60, [$this->tomada], ['Amadora'], nome: 'Bruno Parado');
        $this->tecnico(StatusVendor::OFFLINE, 5, [$this->desentupir], ['Lisboa'], nome: 'Carla Desligada');

        return compact('servido', 'falhado', 'personalizado', 'foraDoCatalogo');
    }

    public function test_a_matriz_poe_a_procura_do_periodo_ao_lado_da_oferta_de_agora(): void
    {
        $this->cenarioDaMatriz();

        $matriz = $this->ler()['matriz'];

        $this->assertSame([
            ['cidade' => 'Lisboa', 'categoria_id' => $this->canalizacao->id, 'categoria' => 'Canalização',
                'criados' => 3, 'servidos' => 1, 'tecnicos_online' => 1, 'tecnicos_podem_aceitar' => 1, 'tecnicos_prontos' => 1],
            ['cidade' => 'Lisboa', 'categoria_id' => $this->eletricidade->id, 'categoria' => 'Eletricidade',
                'criados' => 1, 'servidos' => 0, 'tecnicos_online' => 0, 'tecnicos_podem_aceitar' => 0, 'tecnicos_prontos' => 0],
            ['cidade' => 'Vila Inventada', 'categoria_id' => $this->eletricidade->id, 'categoria' => 'Eletricidade',
                'criados' => 1, 'servidos' => 0, 'tecnicos_online' => 0, 'tecnicos_podem_aceitar' => 0, 'tecnicos_prontos' => 0],
            ['cidade' => 'Amadora', 'categoria_id' => $this->eletricidade->id, 'categoria' => 'Eletricidade',
                'criados' => 0, 'servidos' => 0, 'tecnicos_online' => 1, 'tecnicos_podem_aceitar' => 1, 'tecnicos_prontos' => 0],
        ], $matriz);
    }

    public function test_os_filtros_de_cidade_e_categoria_valem_para_todos_os_blocos(): void
    {
        ['servido' => $servido, 'falhado' => $falhado, 'personalizado' => $personalizado] = $this->cenarioDaMatriz();
        $this->servico(ServiceStatus::CLOSED, extra: ['cidade' => 'Lisboa', 'services_type_id' => $this->tomada->id, 'vendor_id' => Vendor::factory()->create()->id]);

        // Em minúsculas de propósito: a cidade compara-se como no matching.
        $dados = $this->ler('?cidade=lisboa&categoria='.$this->canalizacao->id);

        $this->assertSame(['cidade' => 'Lisboa', 'categoria' => $this->canalizacao->id], $dados['filtros']);
        $this->assertSame(3, collect($dados['semanas'])->sum('criados'));
        $this->assertSame(1, $dados['como_acabaram']['servidos']);
        $this->assertSame(['Lisboa|'.$this->canalizacao->id], collect($dados['matriz'])->map(fn ($l) => $l['cidade'].'|'.$l['categoria_id'])->all());
        $this->assertEqualsCanonicalizing([(string) $falhado, (string) $personalizado], collect($dados['perdidos'])->pluck('id')->all());
        $this->assertSame([], $dados['online_sem_posicao']);
        $this->assertNotContains((string) $servido, collect($dados['perdidos'])->pluck('id')->all());

        $outra = $this->ler('?cidade=Amadora');
        $this->assertSame(0, collect($outra['semanas'])->sum('criados'));
        $this->assertSame(['Bruno Parado'], collect($outra['online_sem_posicao'])->pluck('nome')->all());
    }

    // -------------------------------------------------------------- perdidos

    public function test_os_perdidos_sao_os_ultimos_que_acabaram_sem_tecnico_com_o_motivo(): void
    {
        $vendor = Vendor::factory()->create();
        $semOferta = $this->servico(ServiceStatus::MATCHING_FAILED, '2031-03-11 09:00:00');
        $semResposta = $this->servico(ServiceStatus::MATCHING_FAILED, '2031-03-11 09:01:00');
        $this->candidato($semResposta, CandidateStatus::EXPIRED);
        $recusado = $this->servico(ServiceStatus::MATCHING_FAILED, '2031-03-11 09:02:00');
        $this->candidato($recusado, CandidateStatus::DECLINED, '2031-03-11 09:03:00');
        $semEscolha = $this->servico(ServiceStatus::MATCHING_FAILED, '2031-03-11 09:03:00', ['services_type_id' => $this->tomada->id, 'cidade' => 'Amadora']);
        $this->candidato($semEscolha, CandidateStatus::LOST, '2031-03-11 09:04:00');
        $pagamento = $this->servico(ServiceStatus::REFUSED_MBWAY, '2031-03-11 09:04:00', ['vendor_id' => $vendor->id]);
        $desistiu = $this->servico(ServiceStatus::CANCELED, '2031-03-11 09:05:00');
        // Não são perdas de procura: foi servido, ou o técnico largou (e o
        // pedido reabriu para outro).
        $this->servico(ServiceStatus::CLOSED, '2031-03-11 09:06:00', ['vendor_id' => $vendor->id]);
        $this->servico(ServiceStatus::CANCELED, '2031-03-11 09:07:00', ['vendor_id' => $vendor->id, 'vendor_canceled_at' => '2031-03-11 10:00:00']);

        $perdidos = $this->ler()['perdidos'];

        $this->assertSame(
            [(string) $desistiu, (string) $pagamento, (string) $semEscolha, (string) $recusado, (string) $semResposta, (string) $semOferta],
            array_column($perdidos, 'id'),
        );
        $this->assertSame(
            ['cancelado_cliente', 'pagamento_falhou', 'sem_escolha', 'recusado', 'sem_resposta', 'sem_oferta'],
            array_column($perdidos, 'motivo'),
        );
        $this->assertSame([
            'id' => (string) $semEscolha,
            'criado_em' => '2031-03-11T09:03:00+00:00',
            'cidade' => 'Amadora',
            'categoria' => 'Eletricidade',
            'tipo' => $this->tomada->getTranslation('name', 'pt-pt', false) ?: $this->tomada->name,
            'motivo' => 'sem_escolha',
        ], $perdidos[2]);
    }

    public function test_os_perdidos_ficam_pelos_ultimos_vinte(): void
    {
        for ($i = 0; $i < 22; $i++) {
            $this->servico(ServiceStatus::MATCHING_FAILED, Carbon::parse('2031-03-01 10:00:00')->addHours($i)->toDateTimeString());
        }

        $perdidos = $this->ler()['perdidos'];

        // Do mais recente (o 22.º, às 07:00 do dia seguinte) até ao 3.º (12:00).
        $this->assertCount(20, $perdidos);
        $this->assertSame('2031-03-02T07:00:00+00:00', $perdidos[0]['criado_em']);
        $this->assertSame('2031-03-01T12:00:00+00:00', $perdidos[19]['criado_em']);
    }

    // ---------------------------------------------------- online sem posição

    public function test_online_sem_posicao_ha_mais_de_uma_semana_ou_nunca(): void
    {
        $nunca = $this->tecnico(StatusVendor::ONLINE, null, [$this->desentupir], ['Lisboa', 'Amadora'], nome: 'Nuno Nunca');
        $this->tecnico(StatusVendor::ONLINE, 8 * 24 * 60, [$this->desentupir], [], nome: 'Olga Oito');
        $this->tecnico(StatusVendor::ONLINE, 2 * 24 * 60, [$this->desentupir], ['Lisboa'], nome: 'Duarte Dois');
        $this->tecnico(StatusVendor::OFFLINE, 30 * 24 * 60, [$this->desentupir], ['Lisboa'], nome: 'Filipa Offline');
        $this->tecnico(StatusVendor::ONLINE, null, [$this->desentupir], ['Lisboa'], teste: true, nome: 'Teste Teste');

        $lista = $this->ler()['online_sem_posicao'];

        $this->assertSame(['Nuno Nunca', 'Olga Oito'], array_column($lista, 'nome'));
        $this->assertSame([
            'vendor_id' => $nunca->id,
            'nome' => 'Nuno Nunca',
            'cidade' => 'Amadora, Lisboa',
            'cidades' => ['Amadora', 'Lisboa'],
            'ultima_posicao_em' => null,
            'pode_aceitar' => true,
        ], $lista[0]);
        $this->assertSame(now()->subDays(8)->toIso8601String(), $lista[1]['ultima_posicao_em']);
        $this->assertNull($lista[1]['cidade']);
    }

    // ---------------------------------------------------------------- opções

    public function test_as_listas_dos_filtros(): void
    {
        $this->servico(ServiceStatus::MATCHING_FAILED, extra: ['cidade' => 'lisboa']);
        $this->servico(ServiceStatus::MATCHING_FAILED, extra: ['cidade' => 'Vila Inventada']);
        $this->tecnico(StatusVendor::OFFLINE, null, [], ['Amadora']);

        $dados = $this->ler();

        $this->assertSame(['Amadora', 'Lisboa', 'Vila Inventada'], $dados['cidades']);
        $this->assertContains(['id' => $this->canalizacao->id, 'nome' => 'Canalização'], $dados['categorias']);
        $this->assertContains(['id' => $this->eletricidade->id, 'nome' => 'Eletricidade'], $dados['categorias']);
    }

    // -------------------------------------------------------- testes e erros

    public function test_as_contas_de_teste_ficam_de_fora(): void
    {
        $this->servico(ServiceStatus::MATCHING_FAILED);
        $this->servico(ServiceStatus::MATCHING_FAILED, extra: ['is_test' => true]);
        $this->servico(ServiceStatus::MATCHING_FAILED, extra: ['is_test' => true]);

        $this->assertSame(1, collect($this->ler()['semanas'])->sum('criados'));
        $this->assertSame(2, collect($this->ler('?incluir_testes=1')['semanas'])->sum('criados'));
    }

    public function test_parametros_invalidos_dao_422(): void
    {
        config(['services.admin_api.token' => 'a-valid-token']);
        $pedir = fn (string $q) => $this->withHeaders(['Authorization' => 'Bearer a-valid-token'])->getJson('/api/v1/admin/mercado'.$q);

        $pedir('?de=2031-13-01')->assertStatus(422)->assertJsonValidationErrors('de');
        $pedir('?de=2031-03-10&ate=2031-03-01')->assertStatus(422)->assertJsonValidationErrors('de');
        $pedir('?de=2029-01-01&ate=2031-03-01')->assertStatus(422)->assertJsonValidationErrors('de');
        $pedir('?categoria=999999')->assertStatus(422)->assertJsonValidationErrors('categoria');
    }

    public function test_sem_token_nao_entra(): void
    {
        config(['services.admin_api.token' => 'a-valid-token']);

        $this->getJson('/api/v1/admin/mercado')->assertUnauthorized();
    }
}
