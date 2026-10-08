<?php

namespace Tests\Feature;

use App\Enums\Services\CandidateStatus;
use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\GeneralSettings\ServicesType;
use App\Models\ServiceCandidate;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A lista de serviços do backoffice: filtrar, procurar, e contar o matching.
 *
 * Três coisas que o ecrã de Operações prometia e não fazia:
 *
 *  - os separadores são GRUPOS de estados ("Em curso" = técnico em casa + à
 *    espera de confirmação), e o filtro de um só estado não os exprimia — em
 *    produção cada separador mostrava a lista inteira;
 *  - procurar "282" (o número que o cliente diz ao telefone) não encontrava o
 *    serviço 282, só clientes com 282 no nome ou no telefone;
 *  - "aceitaram" contava só quem ainda estava em ACCEPTED, e quem aceitou passa
 *    a SELECTED ou LOST — num pedido que avançou, a coluna dizia zero.
 */
class AdminServicesFiltrosApiTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<int> Os serviços criados por ESTE teste. */
    private array $criados = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null']);
    }

    private function withAuth(): static
    {
        config(['services.admin_api.token' => 'a-valid-token']);

        return $this->withHeaders(['Authorization' => 'Bearer a-valid-token']);
    }

    /** INSERT direto: 'status' e 'payment_status' são os NOT NULL sem default. */
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

    /**
     * Os ids devolvidos, de entre os que este teste criou. Um serviço deixado
     * na base por outro teste não deve decidir se este passa.
     */
    private function ids(string $query = ''): array
    {
        return collect($this->withAuth()->getJson('/api/v1/admin/services'.$query.(str_contains($query, '?') ? '&' : '?').'per_page=100')->assertOk()->json('data.items'))
            ->pluck('id')->map(fn ($id) => (int) $id)
            ->intersect($this->criados)->sort()->values()->all();
    }

    /** Um serviço da listagem, pelo id — não pela posição. */
    private function item(int $id): array
    {
        $item = collect($this->withAuth()->getJson('/api/v1/admin/services?per_page=100')->assertOk()->json('data.items'))
            ->firstWhere('id', $id);
        $this->assertNotNull($item, "O serviço {$id} não veio na listagem.");

        return $item;
    }

    // ---------------------------------------------------------- estados

    public function test_filtra_varios_estados_de_uma_vez(): void
    {
        $emCasa = $this->servico(ServiceStatus::ARRIVED);
        $porConfirmar = $this->servico(ServiceStatus::FINISHED);
        $this->servico(ServiceStatus::CLOSED);

        $this->assertSame([$emCasa, $porConfirmar], $this->ids('?statuses=Arrived,Finished'));
    }

    public function test_o_filtro_de_um_so_estado_continua_a_funcionar(): void
    {
        $fechado = $this->servico(ServiceStatus::CLOSED);
        $this->servico(ServiceStatus::ARRIVED);

        $this->assertSame([$fechado], $this->ids('?status=Closed'));
    }

    public function test_um_estado_que_nao_existe_nao_devolve_nada_em_vez_de_tudo(): void
    {
        $this->servico(ServiceStatus::CLOSED);

        $this->assertSame([], $this->ids('?statuses=EstadoQueNaoExiste'));
    }

    // ---------------------------------------------------- cidade e tipo

    public function test_filtra_pela_cidade_da_morada_nos_dois_nomes_possiveis(): void
    {
        $lisboa = $this->servico(ServiceStatus::CLOSED, ['address' => json_encode(['city' => 'Lisboa'])]);
        $lisboaLocality = $this->servico(ServiceStatus::CLOSED, ['address' => json_encode(['locality' => 'Lisboa'])]);
        $this->servico(ServiceStatus::CLOSED, ['address' => json_encode(['city' => 'Porto'])]);

        $this->assertSame([$lisboa, $lisboaLocality], $this->ids('?city=Lisboa'));
    }

    public function test_filtra_pelo_tipo_de_servico(): void
    {
        $canos = ServicesType::factory()->create();
        $luz = ServicesType::factory()->create();
        $deCanos = $this->servico(ServiceStatus::CLOSED, ['services_type_id' => $canos->id]);
        $this->servico(ServiceStatus::CLOSED, ['services_type_id' => $luz->id]);

        $this->assertSame([$deCanos], $this->ids("?category_id={$canos->id}"));
    }

    /** A categoria que a equipa escolhe na lista é a área do tipo de serviço. */
    public function test_filtra_pela_categoria_do_tipo_de_servico(): void
    {
        $canalizacao = \App\Models\GeneralSettings\OperationArea::factory()->create();
        $eletricidade = \App\Models\GeneralSettings\OperationArea::factory()->create();
        $desentupir = ServicesType::factory()->create(['operation_area_id' => $canalizacao->id]);
        $tomada = ServicesType::factory()->create(['operation_area_id' => $eletricidade->id]);
        $deCanalizacao = $this->servico(ServiceStatus::CLOSED, ['services_type_id' => $desentupir->id]);
        $this->servico(ServiceStatus::CLOSED, ['services_type_id' => $tomada->id]);

        $this->assertSame([$deCanalizacao], $this->ids("?operation_area_id={$canalizacao->id}"));
        $this->assertSame($canalizacao->id, $this->item($deCanalizacao)['operation_area_id']);
    }

    public function test_filtra_os_personalizados(): void
    {
        $personalizado = $this->servico(ServiceStatus::PENDING_REVIEW, ['is_custom' => true]);
        $catalogo = $this->servico(ServiceStatus::CLOSED, ['is_custom' => false]);

        $this->assertSame([$personalizado], $this->ids('?is_custom=1'));
        $this->assertSame([$catalogo], $this->ids('?is_custom=0'));
    }

    // ---------------------------------------------------------- pesquisa

    public function test_procura_pelo_numero_do_servico_com_e_sem_cardinal(): void
    {
        $alvo = $this->servico(ServiceStatus::CLOSED);
        $this->servico(ServiceStatus::CLOSED);

        $this->assertSame([$alvo], $this->ids("?search={$alvo}"));
        $this->assertSame([$alvo], $this->ids('?search=%23'.$alvo));
    }

    public function test_procura_pelo_nome_do_tecnico(): void
    {
        $vendor = Vendor::factory()->create();
        $vendor->user->update(['name' => 'Rui Canalizador']);
        $doRui = $this->servico(ServiceStatus::CLOSED, ['vendor_id' => $vendor->id]);
        $this->servico(ServiceStatus::CLOSED);

        $this->assertSame([$doRui], $this->ids('?search=Canalizador'));
    }

    public function test_a_procura_pelo_cliente_continua_a_funcionar(): void
    {
        $ana = User::factory()->create(['name' => 'Ana Marques']);
        $daAna = $this->servico(ServiceStatus::CLOSED, ['customer_id' => $ana->id]);
        $this->servico(ServiceStatus::CLOSED);

        $this->assertSame([$daAna], $this->ids('?search=Marques'));
    }

    /** A pesquisa agrupa-se: não pode desfazer os outros filtros com um OR solto. */
    public function test_a_pesquisa_respeita_os_outros_filtros(): void
    {
        $ana = User::factory()->create(['name' => 'Ana Marques']);
        $this->servico(ServiceStatus::CLOSED, ['customer_id' => $ana->id]);
        $emCasa = $this->servico(ServiceStatus::ARRIVED, ['customer_id' => $ana->id]);

        $this->assertSame([$emCasa], $this->ids('?search=Marques&statuses=Arrived'));
    }

    // ---------------------------------------------------------- matching

    private function candidato(int $servico, CandidateStatus $status, bool $respondeu = true): void
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
            'responded_at' => $respondeu ? now()->subMinutes(2) : null,
            'expires_at' => now()->addMinutes(2),
        ]);
    }

    private function pedidoComCincoConvites(): int
    {
        $id = $this->servico(ServiceStatus::AWAITING_PAYMENT);
        $this->candidato($id, CandidateStatus::SELECTED);
        $this->candidato($id, CandidateStatus::LOST);
        $this->candidato($id, CandidateStatus::DECLINED);
        $this->candidato($id, CandidateStatus::EXPIRED, respondeu: false);
        $this->candidato($id, CandidateStatus::NOTIFIED, respondeu: false);

        return $id;
    }

    /** Quem aceitou e depois foi escolhido (ou perdeu) continua a ter aceitado. */
    public function test_aceitaram_conta_quem_foi_escolhido_e_quem_perdeu(): void
    {
        $id = $this->pedidoComCincoConvites();

        $this->assertSame([
            'invited' => 5,
            'notified' => 1,
            'accepted' => 2,
            'declined' => 1,
            'expired' => 1,
        ], $this->item($id)['candidates']);
    }

    /**
     * O detalhe nunca teve as contagens: só a listagem as pedia, e no detalhe
     * a chave `candidates` é a lista de candidatos, que lhes passava por cima.
     */
    public function test_o_detalhe_tem_a_lista_e_as_mesmas_contagens_que_a_listagem(): void
    {
        $id = $this->pedidoComCincoConvites();

        $this->withAuth()->getJson("/api/v1/admin/services/{$id}")->assertOk()
            ->assertJsonCount(5, 'data.candidates')
            ->assertJsonPath('data.candidate_counts', [
                'invited' => 5,
                'notified' => 1,
                'accepted' => 2,
                'declined' => 1,
                'expired' => 1,
            ]);
    }

    /** Quem ainda não respondeu não tem hora de resposta. */
    public function test_quem_nao_respondeu_nao_tem_hora_de_resposta(): void
    {
        $id = $this->pedidoComCincoConvites();

        $lista = collect($this->withAuth()->getJson("/api/v1/admin/services/{$id}")->json('data.candidates'));

        $this->assertNull($lista->firstWhere('status', 'notified')['responded_at']);
        $this->assertNotNull($lista->firstWhere('status', 'selected')['responded_at']);
    }
}
