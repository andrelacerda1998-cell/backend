<?php

namespace Tests\Feature\Carteira;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Jobs\Services\CreateInvoiceJob;
use App\Models\Referral\Referral;
use App\Models\Service;
use App\Models\User;
use App\Models\Wallet\WalletCredit;
use App\Notifications\Customer\ConviteRecompensaNotification;
use App\Services\Carteira\CarteiraDoCliente;
use App\Services\Carteira\ConviteRecusado;
use App\Services\Carteira\Convites;
use App\Trait\Services\CalculateServicePriceForCustomer;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Programa de convites: 5 € para o amigo, 5 € para quem convidou quando o
 * amigo paga um serviço de 30 € ou mais. Decisões de 08/10/2026.
 */
class ConvitesTest extends TestCase
{
    use RefreshDatabase;

    private Convites $convites;

    private CarteiraDoCliente $carteira;

    private int $telefone = 912000000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GenderSeeder::class);
        Notification::fake();
        Bus::fake([CreateInvoiceJob::class]);
        config(['scout.driver' => 'null']);
        config([
            'payshop-sdk.environment' => 'sandbox',
            'payshop-sdk.api_endpoint.sandbox' => 'http://127.0.0.1:9/',
            'payshop-sdk.connect_timeout' => 0.25,
            'payshop-sdk.timeout' => 0.25,
        ]);

        $this->convites = app(Convites::class);
        $this->carteira = app(CarteiraDoCliente::class);
    }

    private function cliente(string $nome = 'Ana', ?string $telefone = null): User
    {
        return User::factory()->create([
            'first_name' => $nome,
            'phone_number' => $telefone ?? '+351'.($this->telefone++),
        ]);
    }

    /** Um cliente que já pagou um serviço — pode convidar. */
    private function clienteComServico(string $nome = 'Ana'): User
    {
        $u = $this->cliente($nome);
        Service::factory()->create(['customer_id' => $u->id, 'status' => ServiceStatus::CLOSED]);

        return $u;
    }

    private function servicoAFechar(User $cliente, int $valor): Service
    {
        return Service::factory()->create([
            'customer_id' => $cliente->id,
            'status' => ServiceStatus::FINISHED,
            'payment_status' => PaymentStatus::PAID,
            'payment_order_id' => null,
            'original_amount' => $valor,
            'amount' => $valor,
            'amount_for_vendor' => (int) round($valor * 0.75),
        ]);
    }

    private function fechar(Service $s): void
    {
        $s->status = ServiceStatus::CLOSED; // ServiceObserver::updated
        $s->save();
    }

    private function recusa(callable $f): string
    {
        try {
            $f();
        } catch (ConviteRecusado $e) {
            return $e->razao;
        }
        $this->fail('o convite devia ter sido recusado');
    }

    // --------------------------------------------------------------- código

    public function test_o_codigo_comeca_pelo_nome_e_e_sempre_o_mesmo(): void
    {
        $ana = $this->cliente('Ana');

        $codigo = $this->convites->codigoDe($ana)->code;

        $this->assertMatchesRegularExpression('/^ANA[A-HJ-NP-Z2-9]{3}$/', $codigo);
        $this->assertSame($codigo, $this->convites->codigoDe($ana)->code);

        // O nome não se altera, mesmo com letras que se evitam na parte ao acaso.
        $this->assertStringStartsWith('RUI', $this->convites->codigoDe($this->cliente('Rui'))->code);
        // Nomes curtos ou sem letras completam-se ao acaso até 6.
        $this->assertSame(6, strlen($this->convites->codigoDe($this->cliente('Zé'))->code));
    }

    // -------------------------------------------------------------- aplicar

    public function test_o_amigo_recebe_5_euros_validos_60_dias_pagos_pela_piquet(): void
    {
        $ana = $this->clienteComServico();
        $bruno = $this->cliente('Bruno');
        $sistemaAntes = (int) system_wallet()->balanceInt;

        $convite = $this->convites->aplicar($bruno, strtolower($this->convites->codigoDe($ana)->code));

        $this->assertSame(Referral::PENDENTE, $convite->status);
        $credito = WalletCredit::where('user_id', $bruno->id)->sole();
        $this->assertSame(500, $credito->amount);
        $this->assertSame(Convites::MOTIVO_AMIGO, $credito->reason);
        $this->assertTrue($credito->expires_at->between(now()->addDays(59), now()->addDays(61)));
        $this->assertSame($sistemaAntes - 500, (int) system_wallet()->fresh()->balanceInt);
    }

    public function test_recusas(): void
    {
        $ana = $this->clienteComServico();
        $codigo = $this->convites->codigoDe($ana)->code;

        $this->assertSame('nao_existe', $this->recusa(fn () => $this->convites->aplicar($this->cliente(), 'XXXXXX')));
        $this->assertSame('proprio', $this->recusa(fn () => $this->convites->aplicar($ana, $codigo)));

        // Quem já pagou um serviço não é amigo novo.
        $veterano = $this->clienteComServico('Rui');
        $this->assertSame('nao_e_novo', $this->recusa(fn () => $this->convites->aplicar($veterano, $codigo)));

        // Nem uma conta nova com o telemóvel de quem já pagou.
        $antiga = $this->clienteComServico('Rita');
        $nova = $this->cliente('Rita', $antiga->phone_number);
        $this->assertSame('nao_e_novo', $this->recusa(fn () => $this->convites->aplicar($nova, $codigo)));

        // Um código por pessoa.
        $bruno = $this->cliente('Bruno');
        $this->convites->aplicar($bruno, $codigo);
        $outro = $this->convites->codigoDe($this->clienteComServico('Eva'))->code;
        $this->assertSame('ja_usou', $this->recusa(fn () => $this->convites->aplicar($bruno, $outro)));

        // Quem nunca pagou um serviço ainda não convida.
        $novato = $this->cliente('Zé');
        $this->assertSame('dono_sem_servicos', $this->recusa(fn () => $this->convites->aplicar($this->cliente(), $this->convites->codigoDe($novato)->code)));
    }

    public function test_quem_ja_ganhou_10_no_ano_nao_convida_mais(): void
    {
        $ana = $this->clienteComServico();
        for ($i = 0; $i < Convites::LIMITE_POR_ANO; $i++) {
            Referral::create([
                'referrer_user_id' => $ana->id, 'referred_user_id' => $this->cliente()->id,
                'code' => 'X', 'status' => Referral::CONCLUIDO, 'completed_at' => now()->subMonths(2),
            ]);
        }

        $this->assertSame('limite', $this->recusa(fn () => $this->convites->aplicar($this->cliente(), $this->convites->codigoDe($ana)->code)));
    }

    // ------------------------------------------------- crédito do amigo

    public function test_o_credito_do_amigo_so_serve_em_servicos_de_30_euros_ou_mais(): void
    {
        $ana = $this->clienteComServico();
        $bruno = $this->cliente('Bruno');
        $this->convites->aplicar($bruno, $this->convites->codigoDe($ana)->code);

        $calc = new class
        {
            use CalculateServicePriceForCustomer {
                buildTransactionTotals as public;
            }
        };

        $this->assertSame(0, $calc->buildTransactionTotals($bruno, 2500, 1875, 5.0)['balance_convites_used']);
        $this->assertSame(500, $calc->buildTransactionTotals($bruno, 4000, 3000, 5.0)['balance_convites_used']);
        $this->assertSame(500, $this->carteira->convitesDisponivel($bruno), 'no ecrã da Carteira aparece');
    }

    // ------------------------------------------------------------ recompensa

    public function test_quem_convida_ganha_5_euros_quando_o_amigo_fecha_um_servico_de_30_ou_mais(): void
    {
        $ana = $this->clienteComServico();
        $bruno = $this->cliente('Bruno');
        $this->convites->aplicar($bruno, $this->convites->codigoDe($ana)->code);

        $this->fechar($this->servicoAFechar($bruno, 2500));
        $this->assertSame(0, WalletCredit::where('user_id', $ana->id)->count(), 'abaixo de 30 € não conta');

        $servico = $this->servicoAFechar($bruno, 4500);
        $this->fechar($servico);

        $convite = Referral::where('referred_user_id', $bruno->id)->sole();
        $this->assertSame(Referral::CONCLUIDO, $convite->status);
        $this->assertSame($servico->id, $convite->first_service_id);
        $credito = WalletCredit::where('user_id', $ana->id)->sole();
        $this->assertSame(Convites::MOTIVO_QUEM_CONVIDA, $credito->reason);
        $this->assertTrue($credito->expires_at->between(now()->addMonths(6)->subDay(), now()->addMonths(6)->addDay()));
        $this->assertSame(500, $this->carteira->convitesDisponivel($ana));
        Notification::assertSentTo($ana, ConviteRecompensaNotification::class);

        // Um segundo serviço do amigo já não dá nada.
        $this->fechar($this->servicoAFechar($bruno, 6000));
        $this->assertSame(1, WalletCredit::where('user_id', $ana->id)->count());
    }

    public function test_reembolsado_o_primeiro_servico_quem_convidou_perde_o_que_nao_gastou(): void
    {
        $ana = $this->clienteComServico();
        $bruno = $this->cliente('Bruno');
        $this->convites->aplicar($bruno, $this->convites->codigoDe($ana)->code);
        $servico = $this->servicoAFechar($bruno, 4500);
        $this->fechar($servico);
        $sistemaAntes = (int) system_wallet()->fresh()->balanceInt;

        $servico->refresh();
        $servico->status = ServiceStatus::CANCELED; // cancelamento de um serviço fechado (super-admin)
        $servico->save();

        $this->assertSame(Referral::ANULADO, Referral::where('referred_user_id', $bruno->id)->value('status'));
        $this->assertSame(0, $this->carteira->convitesDisponivel($ana));
        $this->assertSame($sistemaAntes + 500, (int) system_wallet()->fresh()->balanceInt);
    }

    public function test_anular_no_backoffice_devolve_a_piquet_o_credito_por_gastar(): void
    {
        $ana = $this->clienteComServico();
        $bruno = $this->cliente('Bruno');
        $convite = $this->convites->aplicar($bruno, $this->convites->codigoDe($ana)->code);
        $sistemaAntes = (int) system_wallet()->fresh()->balanceInt;

        $this->convites->anular($convite, 'contas falsas');

        $this->assertSame(Referral::ANULADO, $convite->fresh()->status);
        $this->assertSame(0, $this->carteira->convitesDisponivel($bruno));
        $this->assertSame($sistemaAntes + 500, (int) system_wallet()->fresh()->balanceInt);
    }

    // ------------------------------------------------------------- endpoints

    public function test_o_ecra_convida_um_amigo(): void
    {
        $ana = $this->clienteComServico();

        $r = $this->actingAs($ana, 'api')->getJson('/api/v1/customer/referral')->assertOk();

        $r->assertJsonPath('data.can_invite', true)
            ->assertJsonPath('data.reward_amount', 500)
            ->assertJsonPath('data.friends_joined', 0)
            ->assertJsonPath('data.rewards_left_this_year', 10);
        $this->assertStringStartsWith('ANA', $r->json('data.code'));
    }

    public function test_o_amigo_poe_o_codigo_no_ecra_da_carteira(): void
    {
        $ana = $this->clienteComServico();
        $bruno = $this->cliente('Bruno');
        $codigo = $this->convites->codigoDe($ana)->code;

        $this->actingAs($bruno, 'api')->withHeader('Accept-Language', 'pt-PT')
            ->postJson('/api/v1/customer/referral/apply', ['code' => $codigo])
            ->assertOk()
            ->assertJsonPath('data.credit', 500);

        $this->actingAs($bruno, 'api')->withHeader('Accept-Language', 'pt-PT')
            ->postJson('/api/v1/customer/referral/apply', ['code' => $codigo])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Já usaste um código de convite.');
    }

    public function test_o_amigo_tambem_pode_por_o_codigo_no_campo_do_cupao_no_checkout(): void
    {
        $ana = $this->clienteComServico();
        $bruno = $this->cliente('Bruno');
        $servico = $this->servicoAFechar($ana, 4000);

        $this->actingAs($bruno, 'api')
            ->postJson('/api/v1/customer/vouchers/validate', [
                'voucher_name' => $this->convites->codigoDe($ana)->code,
                'service_type' => $servico->services_type_id,
            ])
            ->assertOk()
            ->assertJsonPath('data.voucher', null)
            ->assertJsonPath('data.referral.credit', 500);

        $this->assertSame(500, $this->carteira->convitesDisponivel($bruno));
    }

    public function test_a_lista_de_convites_abre_no_backoffice(): void
    {
        $ana = $this->clienteComServico();
        $this->convites->aplicar($this->cliente('Bruno'), $this->convites->codigoDe($ana)->code);

        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('backoffice'));
        \Spatie\Permission\Models\Role::findOrCreate('admin');
        \Spatie\Permission\Models\Role::findOrCreate('super-admin');
        $admin = User::factory()->create();
        $admin->assignRole(['admin', 'super-admin']);
        $this->actingAs($admin);

        \Livewire\Livewire::test(\App\Filament\Resources\ReferralResource\Pages\ListReferrals::class)
            ->assertOk()
            ->assertSee($this->convites->codigoDe($ana)->code);
    }
}
