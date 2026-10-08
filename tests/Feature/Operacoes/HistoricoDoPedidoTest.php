<?php

namespace Tests\Feature\Operacoes;

use App\Enums\Services\CandidateStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Service;
use App\Models\ServiceCandidate;
use App\Models\ServiceEvent;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Operacoes\RegistoDeEventos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * O caminho de cada pedido fica gravado: criado, cada estado, cada convite e
 * cada resposta — por qualquer caminho que o mude.
 */
class HistoricoDoPedidoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null']);
    }

    private function pedido(ServiceStatus $estado = ServiceStatus::MATCHING): Service
    {
        return Service::factory()->create([
            'customer_id' => User::factory()->create()->id,
            'services_type_id' => ServicesType::factory()->create()->id,
            'status' => $estado,
        ]);
    }

    private function tipos(Service $s): array
    {
        return ServiceEvent::where('service_id', $s->id)->orderBy('id')->pluck('tipo')->all();
    }

    public function test_criar_um_pedido_e_o_primeiro_passo(): void
    {
        $s = $this->pedido();

        $e = ServiceEvent::where('service_id', $s->id)->sole();
        $this->assertSame('criado', $e->tipo);
        $this->assertSame('Matching', $e->estado_para);
    }

    public function test_cada_mudanca_de_estado_fica_com_o_de_e_o_para(): void
    {
        $s = $this->pedido();
        $vendor = Vendor::factory()->create();

        $s->update(['status' => ServiceStatus::ACCEPTED, 'vendor_id' => $vendor->id]);
        $s->update(['status' => ServiceStatus::ARRIVED]);

        $estados = ServiceEvent::where('service_id', $s->id)->where('tipo', 'estado')->orderBy('id')->get();
        $this->assertSame([['Matching', 'Accepted'], ['Accepted', 'Arrived']], $estados->map(fn ($e) => [$e->estado_de, $e->estado_para])->all());
        $this->assertSame($vendor->id, $estados[0]->vendor_id);
    }

    public function test_mexer_noutra_coisa_nao_cria_passo(): void
    {
        $s = $this->pedido();

        $s->update(['rating_by_customer' => 4]);

        $this->assertSame(['criado'], $this->tipos($s));
    }

    public function test_a_caminho_fica_registado(): void
    {
        $s = $this->pedido(ServiceStatus::ACCEPTED);

        // Como o OnTheWayController faz: o campo não está no `fillable`.
        $s->on_the_way_at = now();
        $s->save();

        $this->assertContains('a_caminho', $this->tipos($s));
    }

    public function test_convites_e_respostas_dos_tecnicos(): void
    {
        $s = $this->pedido();
        $c = ServiceCandidate::create([
            'service_id' => $s->id, 'vendor_id' => Vendor::factory()->create()->id,
            'rank' => 1, 'wave' => 2, 'status' => CandidateStatus::SHORTLISTED,
            'quoted_amount' => 5000, 'quoted_amount_for_vendor' => 3750, 'quoted_distance' => 4.2,
        ]);

        $c->update(['status' => CandidateStatus::NOTIFIED]);
        $c->update(['status' => CandidateStatus::ACCEPTED, 'responded_at' => now()]);

        $eventos = ServiceEvent::where('service_id', $s->id)->whereIn('tipo', ['convidado', 'resposta'])->orderBy('id')->get();
        $this->assertSame(['convidado', 'resposta'], $eventos->pluck('tipo')->all());
        $this->assertSame(2, $eventos[0]->dados['onda']);
        $this->assertSame(4.2, $eventos[0]->dados['distancia_km']);
        $this->assertSame(['notified', 'accepted'], [$eventos[1]->estado_de, $eventos[1]->estado_para]);
    }

    public function test_um_registo_que_falha_nao_parte_o_pedido(): void
    {
        $s = $this->pedido();
        Schema::drop('service_events');

        $s->update(['status' => ServiceStatus::CANCELED]);

        $this->assertSame(ServiceStatus::CANCELED, $s->fresh()->status);
    }

    public function test_o_detalhe_de_admin_traz_o_historico(): void
    {
        $s = $this->pedido();
        $s->update(['status' => ServiceStatus::MATCHING_FAILED]);
        config(['services.admin_api.token' => 'a-valid-token']);

        $this->withHeaders(['Authorization' => 'Bearer a-valid-token'])
            ->getJson("/api/v1/admin/services/{$s->id}")
            ->assertOk()
            ->assertJsonPath('data.events.0.tipo', 'criado')
            ->assertJsonPath('data.events.1.de', 'Matching')
            ->assertJsonPath('data.events.1.para', 'MatchingFailed');
    }

    public function test_o_registo_direto_tambem_serve_para_outros_passos(): void
    {
        $s = $this->pedido();

        RegistoDeEventos::registar($s->id, 'nota', dados: ['texto' => 'olá']);

        $this->assertSame(['texto' => 'olá'], ServiceEvent::where('tipo', 'nota')->sole()->dados);
    }
}
