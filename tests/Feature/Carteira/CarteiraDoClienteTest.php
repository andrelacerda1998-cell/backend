<?php

namespace Tests\Feature\Carteira;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\Service;
use App\Models\User;
use App\Models\Wallet\WalletCredit;
use App\Models\Wallet\WalletCreditUsage;
use App\Services\Carteira\CarteiraDoCliente;
use App\Trait\Services\CalculateServicePriceForCustomer;
use App\Trait\Services\ProcessesServicePayment;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A Carteira do cliente: Saldo (reembolsos, carteira `default`) + Crédito de
 * convites (carteira `convites`, paga pela Piquet, com prazo).
 *
 * O que não pode falhar: as contas da Piquet batem certo (o crédito sai da
 * carteira do sistema e o que expira volta), cada parte volta à carteira de
 * onde saiu, e quem só tem Saldo não vê nada mudar.
 */
class CarteiraDoClienteTest extends TestCase
{
    use RefreshDatabase;

    private CarteiraDoCliente $carteira;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GenderSeeder::class);
        Notification::fake();
        config(['scout.driver' => 'null']);

        // Nenhum teste fala com o Payshop: os serviços daqui não têm ordem de
        // pagamento, e um endpoint morto garante que um engano falha depressa.
        config([
            'payshop-sdk.environment' => 'sandbox',
            'payshop-sdk.api_endpoint.sandbox' => 'http://127.0.0.1:9/',
            'payshop-sdk.connect_timeout' => 0.25,
            'payshop-sdk.timeout' => 0.25,
        ]);

        $this->carteira = app(CarteiraDoCliente::class);
    }

    private function totais(User $cliente, int $preco): array
    {
        $calculadora = new class
        {
            use CalculateServicePriceForCustomer {
                buildTransactionTotals as public;
            }
        };

        // Comissão de 25 %: o técnico recebe 75 % do preço.
        return $calculadora->buildTransactionTotals($cliente, $preco, (int) round($preco * 0.75), 5.0);
    }

    private function cobrador(): object
    {
        return new class
        {
            use ProcessesServicePayment {
                debitarCarteira as public;
            }
        };
    }

    private function servicoPendente(User $cliente, array $extra = []): Service
    {
        return Service::factory()->create([
            'customer_id' => $cliente->id,
            'status' => ServiceStatus::PENDING,
            'payment_status' => PaymentStatus::PAID,
            'payment_order_id' => null,
            ...$extra,
        ]);
    }

    // ------------------------------------------------------------- crédito

    public function test_o_credito_de_convites_sai_da_carteira_da_piquet(): void
    {
        $cliente = User::factory()->create();
        $sistemaAntes = (int) system_wallet()->balanceInt;

        $credito = $this->carteira->creditarConvites($cliente, 500, now()->addMonths(6), 'convite_convidante');

        $this->assertSame($sistemaAntes - 500, (int) system_wallet()->fresh()->balanceInt);
        $this->assertSame(500, (int) $this->carteira->carteiraConvites($cliente)->balanceInt);
        $this->assertSame(500, $this->carteira->convitesDisponivel($cliente));
        $this->assertSame(500, $credito->remaining);
        $this->assertSame(0, (int) $cliente->fresh()->balanceInt, 'o Saldo (dinheiro do cliente) não mexe');
    }

    // --------------------------------------------------------------- preço

    public function test_paga_primeiro_com_os_convites_depois_com_o_saldo(): void
    {
        $cliente = User::factory()->create();
        $cliente->deposit(300);
        $this->carteira->creditarConvites($cliente, 500, now()->addMonths(6), 'convite_convidante');

        $t = $this->totais($cliente, 6000);

        $this->assertSame(800, $t['balance'], 'a app continua a ver o total da Carteira');
        $this->assertSame(800, $t['balance_total_used']);
        $this->assertSame(500, $t['balance_convites_used']);
        $this->assertSame(300, $t['balance_saldo_used']);
        $this->assertSame(5200, $t['value_for_payment']);
        $this->assertSame(0, $t['balance_after_payment']);
    }

    public function test_num_servico_mais_barato_que_a_carteira_sobra_o_saldo(): void
    {
        $cliente = User::factory()->create();
        $cliente->deposit(3000);
        $this->carteira->creditarConvites($cliente, 500, now()->addMonths(6), 'convite_convidante');

        $t = $this->totais($cliente, 2000);

        $this->assertSame(500, $t['balance_convites_used']);
        $this->assertSame(1500, $t['balance_saldo_used']);
        $this->assertSame(0, $t['value_for_payment']);
        $this->assertSame(1500, $t['balance_after_payment']); // 3000 + 500 − 2000
    }

    public function test_quem_so_tem_saldo_ve_o_mesmo_que_antes(): void
    {
        $cliente = User::factory()->create();
        $cliente->deposit(1200);

        $t = $this->totais($cliente, 5000);

        $this->assertSame(1200, $t['balance']);
        $this->assertSame(1200, $t['balance_total_used']);
        $this->assertSame(3800, $t['value_for_payment']);
        $this->assertSame(0, $t['balance_convites_used']);
        $this->assertNull($this->carteira->carteiraConvites($cliente), 'não se cria carteira de convites a ninguém');
    }

    public function test_credito_fora_de_prazo_nao_se_gasta_mesmo_antes_da_tarefa_correr(): void
    {
        $cliente = User::factory()->create();
        $credito = $this->carteira->creditarConvites($cliente, 500, now()->addDay(), 'convite_convidante');
        $credito->update(['expires_at' => now()->subMinute()]);

        $this->assertSame(0, $this->carteira->convitesDisponivel($cliente));
        $this->assertSame(0, $this->totais($cliente, 4000)['balance_convites_used']);
    }

    // ------------------------------------------------------------ pagamento

    public function test_o_pagamento_tira_cada_parte_da_sua_carteira_pelos_creditos_que_expiram_primeiro(): void
    {
        $cliente = User::factory()->create();
        $cliente->deposit(300);
        $tarde = $this->carteira->creditarConvites($cliente, 500, now()->addMonths(6), 'convite_convidante');
        $cedo = $this->carteira->creditarConvites($cliente, 300, now()->addMonth(), 'convite_convidante');
        $servico = $this->servicoPendente($cliente);

        $t = $this->totais($cliente, 1000); // 800 de convites + 200 de saldo
        $this->cobrador()->debitarCarteira($cliente, $servico, $t);

        $this->assertSame(0, $cedo->fresh()->remaining, 'o que expira primeiro gasta-se primeiro');
        $this->assertSame(0, $tarde->fresh()->remaining);
        $this->assertSame(0, (int) $this->carteira->carteiraConvites($cliente)->fresh()->balanceInt);
        $this->assertSame(100, (int) $cliente->fresh()->balanceInt);
        $this->assertSame(800, (int) WalletCreditUsage::where('service_id', $servico->id)->sum('amount'));
    }

    public function test_sem_credito_suficiente_rebenta_e_nao_tira_nada(): void
    {
        $cliente = User::factory()->create();
        $credito = $this->carteira->creditarConvites($cliente, 300, now()->addMonth(), 'convite_convidante');
        $servico = $this->servicoPendente($cliente);

        try {
            $this->carteira->debitarConvites($cliente, $servico, 500);
            $this->fail('devia ter rebentado');
        } catch (\RuntimeException) {
        }

        $this->assertSame(300, $credito->fresh()->remaining);
        $this->assertSame(300, (int) $this->carteira->carteiraConvites($cliente)->fresh()->balanceInt);
        $this->assertSame(0, WalletCreditUsage::count());
    }

    // ----------------------------------------------------------- devolução

    public function test_cancelar_devolve_cada_parte_a_carteira_de_onde_saiu(): void
    {
        $cliente = User::factory()->create();
        $cliente->deposit(300);
        $credito = $this->carteira->creditarConvites($cliente, 500, now()->addMonths(6), 'convite_convidante');
        $servico = $this->servicoPendente($cliente);

        $t = $this->totais($cliente, 4000);
        $this->cobrador()->debitarCarteira($cliente, $servico, $t);
        $servico->update(['credit_used' => $t['balance_saldo_used'], 'referral_credit_used' => $t['balance_convites_used']]);

        $servico->status = ServiceStatus::CANCELED; // ServiceObserver::updating
        $servico->save();

        $this->assertSame(300, (int) $cliente->fresh()->balanceInt, 'o Saldo volta ao Saldo');
        $this->assertSame(500, (int) $this->carteira->carteiraConvites($cliente)->fresh()->balanceInt, 'os convites voltam aos convites');
        $this->assertSame(500, $credito->fresh()->remaining, 'e à linha de onde saíram, com o prazo que tinham');
        $this->assertSame(PaymentStatus::REFUNDED, $servico->fresh()->payment_status);
    }

    public function test_devolver_duas_vezes_nao_devolve_o_dobro(): void
    {
        $cliente = User::factory()->create();
        $this->carteira->creditarConvites($cliente, 500, now()->addMonths(6), 'convite_convidante');
        $servico = $this->servicoPendente($cliente, ['credit_used' => 0]);
        $this->carteira->debitarConvites($cliente, $servico, 500);

        $this->carteira->devolverConvites($servico, $cliente);
        $this->carteira->devolverConvites($servico, $cliente);

        $this->assertSame(500, (int) $this->carteira->carteiraConvites($cliente)->fresh()->balanceInt);
    }

    public function test_credito_que_expirou_com_o_servico_pendente_volta_a_piquet_e_nao_ao_cliente(): void
    {
        $cliente = User::factory()->create();
        $credito = $this->carteira->creditarConvites($cliente, 500, now()->addDay(), 'convite_convidante');
        $servico = $this->servicoPendente($cliente);
        $this->carteira->debitarConvites($cliente, $servico, 500);
        $credito->update(['expires_at' => now()->subMinute()]);
        $this->carteira->expirar();
        $sistemaAntes = (int) system_wallet()->fresh()->balanceInt;

        $this->carteira->devolverConvites($servico, $cliente);

        $this->assertSame(0, (int) $this->carteira->carteiraConvites($cliente)->fresh()->balanceInt);
        $this->assertSame($sistemaAntes + 500, (int) system_wallet()->fresh()->balanceInt);
    }

    // ----------------------------------------------------------- expiração

    public function test_a_tarefa_devolve_a_piquet_o_que_passou_o_prazo(): void
    {
        $cliente = User::factory()->create();
        $velho = $this->carteira->creditarConvites($cliente, 500, now()->addDay(), 'convite_convidante');
        $novo = $this->carteira->creditarConvites($cliente, 500, now()->addMonths(6), 'convite_convidante');
        $velho->update(['expires_at' => now()->subMinute()]);
        $sistemaAntes = (int) system_wallet()->fresh()->balanceInt;

        $this->artisan('carteira:expirar')->assertSuccessful();

        $this->assertSame($sistemaAntes + 500, (int) system_wallet()->fresh()->balanceInt);
        $this->assertSame(500, (int) $this->carteira->carteiraConvites($cliente)->fresh()->balanceInt);
        $this->assertNotNull($velho->fresh()->expired_at);
        $this->assertSame(0, $velho->fresh()->remaining);
        $this->assertSame(500, $novo->fresh()->remaining);

        // Correr outra vez não recolhe nada a mais.
        $this->carteira->expirar();
        $this->assertSame($sistemaAntes + 500, (int) system_wallet()->fresh()->balanceInt);
    }

    // ----------------------------------------------------------- endpoint

    public function test_a_app_recebe_a_carteira_pronta_a_mostrar(): void
    {
        $cliente = User::factory()->create();
        $cliente->deposit(750, ['type' => 'internal/services.transactions_type.refund']);
        $this->carteira->creditarConvites($cliente, 500, now()->addMonths(6), 'convite_convidante');
        $servico = $this->servicoPendente($cliente);
        $this->carteira->debitarConvites($cliente, $servico, 200);

        $r = $this->actingAs($cliente, 'api')
            ->withHeader('Accept-Language', 'pt-PT')
            ->getJson('/api/v1/customer/wallet')
            ->assertOk();

        $r->assertJsonPath('data.total', 1050)
            ->assertJsonPath('data.saldo', 750)
            ->assertJsonPath('data.convites', 300)
            ->assertJsonPath('data.convites_a_expirar.0.amount', 300)
            ->assertJsonCount(3, 'data.movimentos.items');

        $descricoes = collect($r->json('data.movimentos.items'))->pluck('descricao');
        $this->assertContains('Devolvido: pedido cancelado', $descricoes);
        $this->assertContains('Convite: um amigo fez o primeiro serviço', $descricoes);
        $this->assertTrue($descricoes->contains(fn ($d) => str_starts_with($d, 'Usado')));
    }

    public function test_sem_sessao_nao_ha_carteira(): void
    {
        $this->getJson('/api/v1/customer/wallet')->assertUnauthorized();
    }
}
