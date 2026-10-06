<?php

namespace Tests\Feature\Services;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Exceptions\Api\Customer\AgendamentoForaDaJanelaDePagamento;
use App\Models\GeneralSettings\Gender;
use App\Models\Schedule\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Payments\JanelaDeCativacao;
use App\Trait\Services\ProcessesServicePayment;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * NENHUM MEIO DE PAGAMENTO PAGA UM AGENDADO FORA DA JANELA.
 *
 * O limite de 7 dias é validado no `scheduled_day`, na criação. Mas um serviço
 * pode CHEGAR ao pagamento com uma data distante sem passar por essa validação:
 * criado antes de o limite existir, vindo de uma marcação pendente paga mais
 * tarde, ou por um caminho que alguém acrescente sem se lembrar da regra.
 *
 * Pagar nesse caso é cativar dinheiro que expira antes do serviço -- o técnico
 * faz o trabalho e a captura falha no fecho. A guarda vive no
 * `ProcessesServicePayment`, por onde os quatro meios passam
 * obrigatoriamente: cartão, Apple Pay, Google Pay e MBWay.
 */
class PagamentoDeAgendadoForaDaJanelaTest extends TestCase
{
    use DatabaseTruncation;

    protected array $tablesToTruncate = [
        'users', 'wallets', 'vendors', 'services', 'services_types',
        'operation_areas', 'schedule', 'payshop_payments_orders',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null']);
        Gender::firstOrCreate(['name' => 'Masculino']);
        Notification::fake();
        Queue::fake();
    }

    private function agendadoPara(?\DateTimeInterface $quando, ?int $duracaoMinutos = null): Service
    {
        $s = Service::factory()->create([
            'status' => ServiceStatus::PENDING,
            'payment_status' => PaymentStatus::PENDING,
            'amount' => 6000,
            'amount_for_vendor' => 4500,
        ]);

        if ($quando !== null) {
            Schedule::create([
                'service_id' => $s->id,
                'vendor_id' => $s->vendor_id,
                'customer_id' => $s->customer_id,
                'service_type_id' => $s->services_type_id,
                'scheduled_day' => $quando->format('Y-m-d'),
                'scheduled_time_start' => $quando->format('H:i'),
                'scheduled_time_end' => $quando->format('H:i'),
            ]);
        }

        if ($duracaoMinutos !== null) {
            $s->forceFill(['is_custom' => 1, 'custom_duration_minutes' => $duracaoMinutos])->save();
        }

        return $s->fresh();
    }

    /** Expõe a guarda protected do trait, sem tocar na fronteira Payshop. */
    private function guarda(): object
    {
        return new class
        {
            use ProcessesServicePayment;

            public function verifica(Service $s): void
            {
                $this->garanteQueOAgendadoEPagavel($s);
            }
        };
    }

    // ---------------------------------------------------------------- a guarda

    /** Um pedido imediato não tem agendamento: passa sem verificação. */
    public function test_um_pedido_imediato_passa(): void
    {
        $this->guarda()->verifica($this->agendadoPara(null));

        $this->assertTrue(true); // chegar aqui sem excepção é o teste
    }

    /** Dentro do limite de produto (7 dias) paga-se normalmente. */
    public function test_um_agendado_dentro_do_limite_paga(): void
    {
        $this->guarda()->verifica($this->agendadoPara(now()->addDays(5)->setTime(10, 0)));

        $this->assertTrue(true);
    }

    /**
     * Fora da CATIVAÇÃO é recusado.
     *
     * 20 dias: muito além dos 7 do produto e dos 15 do dinheiro. É o serviço
     * que já existe na base de dados de antes do limite.
     */
    public function test_um_agendado_fora_da_cativacao_e_recusado(): void
    {
        $this->expectException(AgendamentoForaDaJanelaDePagamento::class);

        $this->guarda()->verifica($this->agendadoPara(now()->addDays(20)->setTime(10, 0)));
    }

    /**
     * A GUARDA MEDE O FIM DO SERVIÇO, NÃO O INÍCIO.
     *
     * É o fecho que captura, e o fecho vem depois do trabalho. Um serviço que
     * começa dentro da janela por duas horas, mas dura oito, acaba fora dela.
     */
    public function test_a_duracao_conta_para_o_limite(): void
    {
        // Começa 90 minutos antes de a cativação expirar...
        $inicio = JanelaDeCativacao::expiraEm()->copy()->subMinutes(90);

        // ...e com 30 minutos de trabalho ainda cabe.
        $this->guarda()->verifica($this->agendadoPara($inicio, duracaoMinutos: 30));

        // Com 4 horas, não.
        $this->expectException(AgendamentoForaDaJanelaDePagamento::class);
        $this->guarda()->verifica($this->agendadoPara($inicio, duracaoMinutos: 240));
    }

    /** A recusa é 422 e traz uma frase para o cliente, não um erro cru. */
    public function test_a_recusa_diz_ao_cliente_o_que_se_passa(): void
    {
        $erro = new AgendamentoForaDaJanelaDePagamento;

        $this->assertSame(422, $erro->getStatus());

        app()->setLocale('pt-pt');
        $frase = __($erro->getMessage());

        $this->assertNotSame($erro->getMessage(), $frase, 'A chave de tradução não existe em pt-pt.');
        $this->assertStringContainsString('agendamento', mb_strtolower($frase));
    }

    // ---------------------------------------------------------------- cobertura

    /**
     * TODOS OS MEIOS DE PAGAMENTO CHAMAM A GUARDA.
     *
     * Lê o código do trait: cada método `process*Payment` tem de a invocar. É o
     * teste que impede alguém de acrescentar um quinto meio de pagamento -- ou
     * de reescrever um destes -- e deixar o buraco outra vez aberto. Sem isto, a
     * cobertura dependia de alguém se lembrar.
     */
    public function test_os_tres_caminhos_de_pagamento_invocam_a_guarda(): void
    {
        $codigo = file_get_contents(app_path('Trait/Services/ProcessesServicePayment.php'));

        $metodos = ['processCreditCardPayment', 'processWalletPayment', 'processMbwayPayment'];

        foreach ($metodos as $metodo) {
            $inicio = strpos($codigo, 'function '.$metodo);
            $this->assertNotFalse($inicio, $metodo.' desapareceu do trait.');

            // O corpo até ao método seguinte (ou até ao fim) tem de chamar a guarda.
            $seguinte = strpos($codigo, 'protected function', $inicio + 10);
            $corpo = substr($codigo, $inicio, $seguinte !== false ? $seguinte - $inicio : null);

            $this->assertStringContainsString(
                'garanteQueOAgendadoEPagavel',
                $corpo,
                $metodo.' paga sem verificar se o agendamento cabe na cativação.',
            );
        }

        // E a contagem: um `process*Payment` novo sem guarda faz isto falhar.
        $this->assertSame(
            preg_match_all('/protected function process\w*Payment/', $codigo),
            substr_count($codigo, '$this->garanteQueOAgendadoEPagavel('),
            'Há um método de pagamento sem a guarda, ou uma chamada a mais.',
        );
    }
}
