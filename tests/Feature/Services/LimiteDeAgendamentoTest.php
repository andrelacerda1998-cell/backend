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
        // O limite do agendamento é 7 dias -- escrito aqui à letra de propósito,
        // para que mexer nele seja uma decisão e não um efeito lateral.
        $this->assertSame(7, JanelaDeCativacao::DIAS_AGENDAVEIS);
        $this->assertSame(
            now()->addDays(15)->format('Y-m-d H:i'),
            JanelaDeCativacao::expiraEm()->format('Y-m-d H:i'),
        );
    }

    /** O último dia agendável é o 7.º, até ao fim desse dia. */
    public function test_o_ultimo_dia_agendavel_e_o_setimo(): void
    {
        $this->freezeTime();

        $limite = JanelaDeCativacao::ultimoDiaAgendavel();

        $this->assertSame(now()->addDays(7)->format('Y-m-d'), $limite->format('Y-m-d'));
        $this->assertSame('23:59:59', $limite->format('H:i:s'));

        // O pior caso cabe com folga: fechar à última hora do 7.º dia ainda está
        // a uma semana do fim da autorização.
        $this->assertTrue(JanelaDeCativacao::cobre($limite));
    }

    /**
     * A GUARDA QUE IMPORTA: o limite nunca pode passar a cativação.
     *
     * Os 7 dias são uma decisão de produto e não uma conta derivada dos 15, por
     * isso nada no código impede alguém de os pôr em 30. Se isso acontecer, o
     * cliente marca para um dia em que a autorização já expirou, a captura falha
     * no fecho e o técnico trabalha de graça. É aqui que isso é apanhado.
     */
    public function test_o_limite_nunca_pode_passar_a_cativacao(): void
    {
        $this->assertLessThan(
            JanelaDeCativacao::DIAS,
            JanelaDeCativacao::DIAS_AGENDAVEIS,
            'O limite do agendamento passou da janela de cativação: um serviço marcado '
            .'para essa data não é cobrável no fecho.',
        );

        // E o fim do último dia agendável tem de caber dentro da autorização.
        $this->freezeTime();
        $this->assertTrue(JanelaDeCativacao::cobre(JanelaDeCativacao::ultimoDiaAgendavel()));
    }

    /**
     * A noite do 15.º dia não cabe NA CATIVAÇÃO -- o limite da máquina.
     *
     * Com o agendamento em 7 dias isto já não é alcançável pelo cliente, mas é o
     * número que justifica a guarda acima: se alguém subir `DIAS_AGENDAVEIS` até
     * aqui, é esta a parede.
     */
    public function test_a_noite_do_decimo_quinto_dia_nao_cabe_na_cativacao(): void
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
        $this->assertTrue($this->validaDia(now()->addDays(7)->toDateString())->passes());
    }

    public function test_o_dia_seguinte_ao_limite_e_recusado(): void
    {
        $this->assertFalse($this->validaDia(now()->addDays(8)->toDateString())->passes());
    }

    /**
     * Uma data DENTRO da cativação mas FORA do limite é recusada.
     *
     * O 10.º dia é cobrável -- a autorização só expira ao 15.º -- e mesmo assim
     * não se agenda. É o que distingue o limite de produto do limite técnico, e
     * garante que o 7 está mesmo a ser aplicado em vez de o teste passar por
     * acidente da aritmética da cativação.
     */
    public function test_o_decimo_dia_e_recusado_mesmo_sendo_cobravel(): void
    {
        $this->freezeTime();

        $decimoDia = now()->addDays(10);

        $this->assertTrue(JanelaDeCativacao::cobre($decimoDia), 'O 10.º dia devia ser cobrável.');
        $this->assertFalse($this->validaDia($decimoDia->toDateString())->passes());
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

        $this->assertStringContainsString(now()->addDays(7)->format('d/m/Y'), $erro);
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
            'scheduled_day' => now()->addDays(5)->toDateString(),
            'scheduled_time_start' => '10:00',
        ]);

        // Exigir que NÃO seja 405/404: senão este teste passava com a rota errada
        // e dava a ilusão de estar a provar algo.
        $this->assertNotSame(405, $resposta->status());
        $this->assertNotSame(404, $resposta->status());
        $resposta->assertJsonMissingValidationErrors('scheduled_day');
    }
}
