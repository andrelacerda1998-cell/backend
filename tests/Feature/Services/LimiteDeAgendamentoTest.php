<?php

namespace Tests\Feature\Services;

use App\Models\GeneralSettings\Gender;
use App\Models\GeneralSettings\ServicesType;
use App\Models\User;
use App\Rules\AgendamentoDentroDaJanelaDePagamento;
use App\Services\Payments\JanelaDeCativacao;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * NÃO SE AGENDA PARA DEPOIS DE A CATIVAÇÃO EXPIRAR.
 *
 * O dinheiro fica cativo 15 dias e o agendamento não tinha limite nenhum:
 * `scheduled_day` era validado só como `date` e o DatePicker da app não passava
 * `maximumDate`. Marcar para dentro de dois meses dava uma cativação expirada à
 * hora do serviço, captura falhada no fecho, e o técnico a trabalhar de graça
 * até alguém reparar.
 */
class LimiteDeAgendamentoTest extends TestCase
{
    use DatabaseTruncation;

    protected array $tablesToTruncate = ['users', 'wallets', 'vendors', 'services_types', 'operation_areas'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null']);
        config(['app.locale' => 'pt-pt']);
        Gender::firstOrCreate(['name' => 'Masculino']);
        Notification::fake();
    }

    private function validaDia(string $dia): \Illuminate\Validation\Validator
    {
        return Validator::make(
            ['scheduled_day' => $dia],
            ['scheduled_day' => [new AgendamentoDentroDaJanelaDePagamento]],
        );
    }

    // ---------------------------------------------------------------- a janela

    /** A janela é a mesma que vai para o Payshop: um número, um sítio. */
    public function test_a_janela_tem_quinze_dias(): void
    {
        $this->assertSame(15, JanelaDeCativacao::DIAS);
        // O limite do agendamento é 14 dias -- escrito aqui à letra de propósito,
        // para que mexer nele seja uma decisão e não um efeito lateral.
        $this->assertSame(14, JanelaDeCativacao::DIAS_AGENDAVEIS);
        $this->assertSame(
            now()->addDays(15)->format('Y-m-d H:i'),
            JanelaDeCativacao::expiraEm()->format('Y-m-d H:i'),
        );
    }

    /**
     * O ÚLTIMO DIA AGENDÁVEL É O 14.º, NÃO O 15.º.
     *
     * A captura acontece no FECHO, depois do trabalho feito. Um slot às 18:00
     * do 15.º dia fecha-se para lá das 360 horas e a cativação já expirou.
     * Cortar no dia anterior cobre qualquer hora de qualquer slot.
     */
    public function test_o_ultimo_dia_agendavel_e_o_dia_anterior_ao_fim_da_janela(): void
    {
        $this->freezeTime();

        $limite = JanelaDeCativacao::ultimoDiaAgendavel();

        $this->assertSame(now()->addDays(14)->format('Y-m-d'), $limite->format('Y-m-d'));
        $this->assertSame('23:59:59', $limite->format('H:i:s'));

        // O pior caso cabe: fechar à última hora do 14.º dia ainda está coberto.
        $this->assertTrue(JanelaDeCativacao::cobre($limite));
    }

    /** E o 15.º dia à noite NÃO cabe -- é exactamente o caso que isto evita. */
    public function test_a_noite_do_decimo_quinto_dia_ja_nao_cabe(): void
    {
        $this->freezeTime();

        $this->assertFalse(JanelaDeCativacao::cobre(now()->addDays(15)->setTime(18, 0)));
    }

    // ---------------------------------------------------------------- a regra

    public function test_amanha_e_aceite(): void
    {
        $this->assertTrue($this->validaDia(now()->addDay()->toDateString())->passes());
    }

    public function test_o_ultimo_dia_da_janela_e_aceite(): void
    {
        $this->assertTrue($this->validaDia(now()->addDays(14)->toDateString())->passes());
    }

    public function test_o_dia_seguinte_ao_limite_e_recusado(): void
    {
        $this->assertFalse($this->validaDia(now()->addDays(15)->toDateString())->passes());
    }

    public function test_dois_meses_a_frente_e_recusado(): void
    {
        $this->assertFalse($this->validaDia(now()->addMonths(2)->toDateString())->passes());
    }

    /** A mensagem diz ao cliente qual é a última data, não só que está errado. */
    public function test_a_mensagem_diz_a_data_limite(): void
    {
        $validador = $this->validaDia(now()->addMonths(2)->toDateString());

        $erro = $validador->errors()->first('scheduled_day');

        $this->assertStringContainsString(now()->addDays(14)->format('d/m/Y'), $erro);
        $this->assertStringContainsString('agendar', $erro);
    }

    /** Sem data não há nada a validar: o pedido imediato passa por aqui. */
    public function test_sem_data_a_regra_nao_se_mete(): void
    {
        $this->assertTrue($this->validaDia('')->passes());
        $this->assertTrue(
            Validator::make(['scheduled_day' => null], ['scheduled_day' => [new AgendamentoDentroDaJanelaDePagamento]])->passes(),
        );
    }

    /** Formato inválido é da regra `date`, não desta: não se duplica o erro. */
    public function test_uma_data_impossivel_nao_e_problema_desta_regra(): void
    {
        $this->assertTrue($this->validaDia('nao-sou-uma-data')->passes());
    }

    // ---------------------------------------------------------------- os endpoints

    /**
     * O limite tem de estar em TODAS as portas de entrada que pagam na criação.
     *
     * Validar só numa dava a ilusão de limite: a app usa caminhos diferentes
     * para o pedido direto, para o matching e para o pedido personalizado.
     */
    public function test_todas_as_portas_de_entrada_levam_a_regra(): void
    {
        $portas = [
            \App\Http\Requests\Api\Customer\Services\OpenServiceRequest::class => 'schedule.scheduled_day',
            \App\Http\Requests\Api\Customer\Services\RequestServiceRequest::class => 'scheduled_day',
            \App\Http\Requests\Api\Customer\Services\CalculateValueRequest::class => 'scheduled_day',
            \App\Http\Requests\Customer\StartMatchingRequest::class => 'schedule.scheduled_day',
            \App\Http\Requests\Customer\StartCustomMatchingRequest::class => 'schedule.scheduled_day',
        ];

        foreach ($portas as $classe => $campo) {
            $regras = (new $classe)->rules();

            $this->assertArrayHasKey($campo, $regras, $classe.' não valida '.$campo);

            $tem = collect((array) $regras[$campo])
                ->contains(fn ($r) => $r instanceof AgendamentoDentroDaJanelaDePagamento);

            $this->assertTrue($tem, $classe.' não leva o limite de agendamento em '.$campo);
        }
    }

    /**
     * A cotação respeita o mesmo limite.
     *
     * Sem isto a app mostrava um preço para uma data que o pedido depois
     * recusava -- o cliente escolhia, via o valor, e só no fim é que levava com
     * o erro.
     */
    public function test_a_cotacao_recusa_uma_data_fora_da_janela(): void
    {
        $cliente = User::factory()->create();
        ServicesType::factory()->create();

        $resposta = $this->actingAs($cliente)->postJson('/api/v1/customer/services/calculate', [
            'services_type_id' => ServicesType::first()->id,
            'scheduled_day' => now()->addMonths(2)->toDateString(),
            'scheduled_time_start' => '10:00',
        ]);

        $resposta->assertStatus(422);
        $resposta->assertJsonValidationErrors('scheduled_day');
    }

    /** E aceita uma dentro da janela -- senão o teste acima não prova nada. */
    public function test_a_cotacao_aceita_uma_data_dentro_da_janela(): void
    {
        $cliente = User::factory()->create();
        ServicesType::factory()->create();

        $resposta = $this->actingAs($cliente)->postJson('/api/v1/customer/services/calculate', [
            'services_type_id' => ServicesType::first()->id,
            'scheduled_day' => now()->addDays(10)->toDateString(),
            'scheduled_time_start' => '10:00',
        ]);

        // Exigir que NÃO seja 405/404: senão este teste passava com a rota errada
        // e dava a ilusão de estar a provar algo.
        $this->assertNotSame(405, $resposta->status());
        $this->assertNotSame(404, $resposta->status());
        $resposta->assertJsonMissingValidationErrors('scheduled_day');
    }
}
