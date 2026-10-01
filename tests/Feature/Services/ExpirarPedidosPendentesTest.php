<?php

namespace Tests\Feature\Services;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\GeneralSettings\Gender;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Common\Services\RefuseService;
use App\Settings\MatchingSettings;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * O prazo que o ecrã promete passa a ser verdade.
 *
 * Até aqui a app do técnico mostrava um contador e dizia "tens 120 segundos", e
 * o servidor não fazia nada quando eles passavam: o pedido continuava aceitável
 * horas depois. Do lado do cliente era pior -- um pedido PAGO ficava pendurado
 * para sempre num profissional que nunca respondeu, com o dinheiro cativo.
 *
 * O que estes testes protegem, por ordem de gravidade se falhar:
 *  - o dinheiro do cliente não fica preso num pedido morto;
 *  - um pedido ACEITE no último segundo não é expirado a seguir;
 *  - a taxa de aceitação do técnico não cai por ele não ter atendido.
 */
class ExpirarPedidosPendentesTest extends TestCase
{
    use DatabaseTruncation;

    protected array $tablesToTruncate = [
        'users', 'wallets', 'vendors', 'services', 'services_types',
        'operation_areas', 'transactions', 'transfers',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null']);
        Gender::firstOrCreate(['name' => 'Masculino']);
        Notification::fake();
        Queue::fake();

        $settings = app(MatchingSettings::class);
        $settings->vendor_response_seconds_immediate = 120;
        $settings->save();
    }

    private function tecnico(): Vendor
    {
        $user = User::factory()->create();

        return Vendor::create([
            'user_id' => $user->id,
            'username' => 'tec_'.$user->id,
        ]);
    }

    private function pedido(Vendor $vendor, int $segundosAtras): Service
    {
        $cliente = User::factory()->create();

        $servico = new Service();
        $servico->forceFill([
            'customer_id' => $cliente->id,
            'vendor_id' => $vendor->id,
            'quantity' => 1,
            'status' => ServiceStatus::PENDING,
            'payment_status' => PaymentStatus::PAID,
            'distance' => 2,
            'amount' => 4500,
            'amount_for_vendor' => 3375,
            'credit_used' => 0,
            'price_rate' => 0,
            'is_custom' => 0,
            'is_test' => 0,
        ])->save();

        // `created_at` é gerido pelo Eloquent; forçar depois de gravar.
        Service::where('id', $servico->id)
            ->update(['created_at' => now()->subSeconds($segundosAtras)]);

        return $servico->fresh();
    }

    public function test_expira_um_pedido_que_passou_da_janela(): void
    {
        $servico = $this->pedido($this->tecnico(), 121);

        $this->artisan('services:expirar-pedidos-pendentes')->assertSuccessful();

        $servico->refresh();
        $this->assertSame(ServiceStatus::REFUSED, $servico->status);
        $this->assertSame(RefuseService::MOTIVO_EXPIRADO, $servico->status_justification);
    }

    public function test_nao_toca_num_pedido_ainda_dentro_da_janela(): void
    {
        $servico = $this->pedido($this->tecnico(), 60);

        $this->artisan('services:expirar-pedidos-pendentes')->assertSuccessful();

        $this->assertSame(ServiceStatus::PENDING, $servico->fresh()->status);
    }

    /**
     * O segundo exacto não expira.
     *
     * Com `>=` em vez de `>` o pedido morria no mesmo instante em que o ecrã do
     * técnico ainda mostrava "0:01" — e o deslizar dele falhava sem explicação.
     */
    public function test_o_limite_e_exclusivo(): void
    {
        $servico = $this->pedido($this->tecnico(), 119);

        $this->artisan('services:expirar-pedidos-pendentes')->assertSuccessful();

        $this->assertSame(ServiceStatus::PENDING, $servico->fresh()->status);
    }

    /**
     * Aceitar no último segundo ganha à expiração.
     *
     * O cron corre ao minuto; entre o técnico deslizar e o comando correr há
     * uma janela real. O `refuse()` volta a ler o serviço e só age se ainda
     * estiver PENDING -- sem isso, um serviço JÁ ACEITE era revertido e o
     * cliente ficava sem profissional depois de lhe terem dito que tinha um.
     */
    public function test_nao_reverte_um_pedido_entretanto_aceite(): void
    {
        $servico = $this->pedido($this->tecnico(), 300);
        $servico->forceFill(['status' => ServiceStatus::ACCEPTED])->save();

        $this->artisan('services:expirar-pedidos-pendentes')->assertSuccessful();

        $this->assertSame(ServiceStatus::ACCEPTED, $servico->fresh()->status);
    }

    /** Sem profissional atribuído o pedido ainda está em seleção: não é connosco. */
    public function test_ignora_pedidos_sem_profissional(): void
    {
        $cliente = User::factory()->create();
        $servico = new Service();
        $servico->forceFill([
            'customer_id' => $cliente->id,
            'vendor_id' => null,
            'quantity' => 1,
            'status' => ServiceStatus::PENDING,
            'payment_status' => PaymentStatus::PAID,
            'distance' => 2,
            'amount' => 4500,
            'credit_used' => 0,
            'price_rate' => 0,
            'is_custom' => 0,
            'is_test' => 0,
        ])->save();
        Service::where('id', $servico->id)->update(['created_at' => now()->subSeconds(999)]);

        $this->artisan('services:expirar-pedidos-pendentes')->assertSuccessful();

        $this->assertSame(ServiceStatus::PENDING, $servico->fresh()->status);
    }

    /** O ensaio mostra e não mexe. */
    public function test_ensaio_nao_altera_nada(): void
    {
        $servico = $this->pedido($this->tecnico(), 300);

        $this->artisan('services:expirar-pedidos-pendentes', ['--ensaio' => true])->assertSuccessful();

        $this->assertSame(ServiceStatus::PENDING, $servico->fresh()->status);
    }

    /**
     * A janela sai das DEFINIÇÕES, não de um 120 escrito no comando.
     *
     * Se alguém mudar a definição e o comando continuar nos 120, o servidor
     * fecha o pedido numa altura e a app conta até outra.
     */
    public function test_respeita_a_janela_das_definicoes(): void
    {
        $settings = app(MatchingSettings::class);
        $settings->vendor_response_seconds_immediate = 600;
        $settings->save();

        $servico = $this->pedido($this->tecnico(), 300);

        $this->artisan('services:expirar-pedidos-pendentes')->assertSuccessful();

        $this->assertSame(ServiceStatus::PENDING, $servico->fresh()->status);
    }

    /**
     * Um pedido expirado NÃO conta como recusa na taxa de aceitação.
     *
     * Sem isto, ligar este comando fazia a percentagem de toda a gente cair
     * sozinha, da noite para o dia, por não terem atendido o telemóvel.
     */
    public function test_expirado_nao_estraga_a_taxa_de_aceitacao(): void
    {
        $vendor = $this->tecnico();

        // Um serviço fechado (aceite e concluído) e um expirado.
        $fechado = $this->pedido($vendor, 10);
        $fechado->forceFill(['status' => ServiceStatus::CLOSED])->save();

        $this->pedido($vendor, 300);
        $this->artisan('services:expirar-pedidos-pendentes')->assertSuccessful();

        $resposta = $this->actingAs($vendor->user, 'api')->getJson('/api/v1/vendor/stats');
        $resposta->assertOk();

        $this->assertSame(100, $resposta->json('data.acceptance_rate'));
    }

    /**
     * O DINHEIRO DO CLIENTE É DEVOLVIDO.
     *
     * É o que justifica o comando existir. Antes dele, um pedido pago ficava
     * pendurado para sempre num profissional que nunca respondeu, e o dinheiro
     * com ele. O caminho é o mesmo da recusa (`RefuseService`), e este teste
     * usa o crédito promocional porque é a parte que se verifica sem falar com
     * o Payshop -- a autorização e o reembolsso do cartão vivem no mesmo
     * método, logo acima.
     */
    public function test_devolve_o_credito_do_cliente_ao_expirar(): void
    {
        $vendor = $this->tecnico();
        $cliente = User::factory()->create();

        $servico = new Service();
        $servico->forceFill([
            'customer_id' => $cliente->id,
            'vendor_id' => $vendor->id,
            'quantity' => 1,
            'status' => ServiceStatus::PENDING,
            'payment_status' => PaymentStatus::PAID,
            'distance' => 2,
            'amount' => 4500,
            'amount_for_vendor' => 3375,
            'credit_used' => 1500,   // 15,00 € de crédito gastos no pedido
            'price_rate' => 0,
            'is_custom' => 0,
            'is_test' => 0,
        ])->save();
        Service::where('id', $servico->id)->update(['created_at' => now()->subSeconds(300)]);

        $this->assertSame(0, (int) $cliente->balance);

        $this->artisan('services:expirar-pedidos-pendentes')->assertSuccessful();

        $this->assertSame(1500, (int) $cliente->fresh()->balance);
    }

    /** Uma recusa a sério continua a contar. */
    public function test_recusa_a_serio_continua_a_contar(): void
    {
        $vendor = $this->tecnico();

        $fechado = $this->pedido($vendor, 10);
        $fechado->forceFill(['status' => ServiceStatus::CLOSED])->save();

        $recusado = $this->pedido($vendor, 10);
        (new RefuseService($recusado))->refuse();

        $resposta = $this->actingAs($vendor->user, 'api')->getJson('/api/v1/vendor/stats');
        $resposta->assertOk();

        $this->assertSame(50, $resposta->json('data.acceptance_rate'));
    }
}
