<?php

namespace Tests\Feature\Services;

use App\Enums\Services\CandidateStatus;
use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\GeneralSettings\Gender;
use App\Models\Schedule\Schedule;
use App\Models\Service;
use App\Models\ServiceCandidate;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * O que o profissional está à espera que o cliente decida.
 *
 * Entre dizer "tenho disponibilidade" e o cliente escolher havia um vazio: a
 * Home não dizia nada, a Agenda dizia "livre" até no dia do serviço, e ele só
 * percebia que não tinha sido escolhido por nunca mais receber notícias.
 *
 * O que estes testes protegem é sobretudo o que NÃO deve aparecer: mostrar uma
 * candidatura que já se perdeu é pior do que não mostrar nada -- faz-lhe
 * guardar uma tarde para um trabalho que já é de outro.
 */
class CandidaturasAEsperaTest extends TestCase
{
    use DatabaseTruncation;

    protected array $tablesToTruncate = [
        'users', 'wallets', 'vendors', 'services', 'services_types',
        'operation_areas', 'service_candidates', 'schedule',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null']);
        Gender::firstOrCreate(['name' => 'Masculino']);
        Notification::fake();
        Queue::fake();
    }

    private function tecnico(): Vendor
    {
        $user = User::factory()->create();

        return Vendor::create(['user_id' => $user->id, 'username' => 'tec_'.$user->id]);
    }

    private function servicoEmSelecao(ServiceStatus $estado = ServiceStatus::MATCHING): Service
    {
        $cliente = User::factory()->create();

        $s = new Service();
        $s->forceFill([
            'customer_id' => $cliente->id,
            'vendor_id' => null,
            'quantity' => 1,
            'status' => $estado,
            'payment_status' => PaymentStatus::PENDING,
            'distance' => 2,
            'amount' => 7000,
            'amount_for_vendor' => 5250,
            'credit_used' => 0,
            'price_rate' => 0,
            'is_custom' => 0,
            'is_test' => 0,
        ])->save();

        return $s->fresh();
    }

    private function candidatura(Vendor $v, Service $s, CandidateStatus $estado): ServiceCandidate
    {
        return ServiceCandidate::forceCreate([
            'service_id' => $s->id,
            'vendor_id' => $v->id,
            'rank' => 1,
            'wave' => 1,
            'status' => $estado,
            'quoted_amount' => 7000,
            'quoted_amount_for_vendor' => 5250,
            'quoted_distance' => 2,
            'notified_at' => now()->subMinute(),
            'responded_at' => now(),
        ]);
    }

    private function pedir(Vendor $v)
    {
        return $this->actingAs($v->user, 'api')->getJson('/api/v1/vendor/services/matching/awaiting');
    }

    public function test_lista_a_candidatura_aceite_a_espera(): void
    {
        $v = $this->tecnico();
        $s = $this->servicoEmSelecao();
        $this->candidatura($v, $s, CandidateStatus::ACCEPTED);

        $resposta = $this->pedir($v);

        $resposta->assertOk();
        $this->assertCount(1, $resposta->json('data'));
    }

    /** Um convite por responder não é uma candidatura: vive no outro ecrã. */
    public function test_nao_lista_convites_por_responder(): void
    {
        $v = $this->tecnico();
        $s = $this->servicoEmSelecao();
        $this->candidatura($v, $s, CandidateStatus::NOTIFIED);

        $this->assertCount(0, $this->pedir($v)->json('data'));
    }

    /**
     * PERDEU: o cliente escolheu outro. Deixa de aparecer.
     *
     * É o caso que importa. Mostrar isto fá-lo-ia guardar a tarde para um
     * trabalho que já é de outra pessoa.
     */
    public function test_nao_lista_uma_candidatura_perdida(): void
    {
        $v = $this->tecnico();
        $s = $this->servicoEmSelecao();
        $this->candidatura($v, $s, CandidateStatus::LOST);

        $this->assertCount(0, $this->pedir($v)->json('data'));
    }

    /**
     * GANHOU: já não está à espera. O serviço sai de seleção e passa a ser um
     * trabalho a sério, que a Agenda mostra pelo caminho normal.
     */
    public function test_nao_lista_uma_candidatura_escolhida(): void
    {
        $v = $this->tecnico();
        $s = $this->servicoEmSelecao(ServiceStatus::SCHEDULED);
        $this->candidatura($v, $s, CandidateStatus::SELECTED);

        $this->assertCount(0, $this->pedir($v)->json('data'));
    }

    /**
     * O serviço saiu de seleção mas a candidatura ficou `accepted` por limpar.
     * Mandar o estado da CANDIDATURA sozinho não chega -- é preciso olhar
     * também para o serviço, senão fica pendurado no ecrã para sempre.
     */
    public function test_nao_lista_quando_o_servico_ja_saiu_de_selecao(): void
    {
        $v = $this->tecnico();
        $s = $this->servicoEmSelecao(ServiceStatus::CANCELED);
        $this->candidatura($v, $s, CandidateStatus::ACCEPTED);

        $this->assertCount(0, $this->pedir($v)->json('data'));
    }

    /**
     * PASSADO O PRAZO DO CLIENTE, desaparece.
     *
     * O `matching:advance` corre ao minuto; nesse intervalo o pedido continua
     * em `Matching` com a candidatura em `accepted`. Sem este filtro a app
     * mostrava-a na Agenda a dizer "o prazo do cliente terminou" e na Home a
     * dizer "à espera da decisão" -- duas mensagens contrárias sobre a mesma
     * coisa, e já não há decisão nenhuma para esperar.
     */
    public function test_nao_lista_depois_de_o_prazo_do_cliente_passar(): void
    {
        $v = $this->tecnico();
        $s = $this->servicoEmSelecao();

        $janela = app(\App\Settings\MatchingSettings::class)->customer_choice_seconds_scheduled;
        $s->forceFill(['candidates_ready_at' => now()->subSeconds($janela + 60)])->save();
        $this->candidatura($v, $s, CandidateStatus::ACCEPTED);

        $this->assertCount(0, $this->pedir($v)->json('data'));
    }

    /** Dentro do prazo continua a aparecer -- o filtro não pode comer tudo. */
    public function test_lista_enquanto_o_prazo_do_cliente_corre(): void
    {
        $v = $this->tecnico();
        $s = $this->servicoEmSelecao();
        $s->forceFill(['candidates_ready_at' => now()])->save();
        $this->candidatura($v, $s, CandidateStatus::ACCEPTED);

        $this->assertCount(1, $this->pedir($v)->json('data'));
    }

    /** As candidaturas de outro profissional não são dele. */
    public function test_nao_mostra_as_candidaturas_de_outro(): void
    {
        $meu = $this->tecnico();
        $outro = $this->tecnico();
        $s = $this->servicoEmSelecao();
        $this->candidatura($outro, $s, CandidateStatus::ACCEPTED);

        $this->assertCount(0, $this->pedir($meu)->json('data'));
    }

    /**
     * Leva o prazo do CLIENTE, que é o que a Agenda mostra a contar.
     *
     * Tem de ser o mesmo instante que o ecrã do cliente usa: dois relógios a
     * contar a mesma coisa com números diferentes é a forma mais rápida de
     * ninguém acreditar em nenhum deles. Daí vir do mesmo
     * `MatchingService::customerDeadline()`.
     */
    public function test_leva_o_prazo_do_cliente(): void
    {
        $v = $this->tecnico();
        $s = $this->servicoEmSelecao();
        $s->forceFill(['candidates_ready_at' => now()])->save();
        $this->candidatura($v, $s, CandidateStatus::ACCEPTED);

        $prazoNaResposta = $this->pedir($v)->json('data.0.customer_deadline');
        $prazoReal = app(\App\Services\Matching\MatchingService::class)
            ->customerDeadline($s->fresh());

        $this->assertNotNull($prazoNaResposta);
        $this->assertSame($prazoReal->toIso8601String(), $prazoNaResposta);
    }

    /** Leva o dia e a hora, que é o que a Agenda precisa para marcar. */
    public function test_leva_a_data_do_agendamento(): void
    {
        $v = $this->tecnico();
        $s = $this->servicoEmSelecao();
        Schedule::forceCreate([
            'service_id' => $s->id,
            'vendor_id' => $v->id,
            'customer_id' => $s->customer_id,
            'scheduled_day' => now()->addDays(2)->toDateString(),
            'scheduled_time_start' => '15:00:00',
            'scheduled_time_end' => '16:00:00',
            'is_pending' => true,
        ]);
        $this->candidatura($v, $s, CandidateStatus::ACCEPTED);

        $dados = $this->pedir($v)->json('data.0');

        $this->assertSame(now()->addDays(2)->toDateString(), $dados['schedule']['scheduled_day']);
    }
}
