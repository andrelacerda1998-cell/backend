<?php

namespace Tests\Feature\Vendors;

use App\Enums\Services\AddressType;
use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\Address;
use App\Models\GeneralSettings\Gender;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\Vendor\AtDeadlineForfeitedNotification;
use App\Notifications\Vendor\AtDeadlineWarningNotification;
use App\Services\Common\Services\CloseService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Cinco dias para dar o subutilizador da AT, ou o dinheiro passa para a Piquet.
 *
 * Isto RETIRA dinheiro que o técnico ganhou a trabalhar. É a única coisa nesta
 * base de código que o faz, e por isso é a que mais precisa de testes que
 * falhem quando estiver errada — não que passem quando está certa.
 *
 * O que estes testes guardam, por ordem de gravidade do que impedem:
 *  - o crédito promocional NUNCA se perde (não é trabalho, não está em causa);
 *  - quem deu a AT dentro do prazo não perde nada;
 *  - correr o comando duas vezes não tira o dinheiro duas vezes;
 *  - o relógio não se reinicia a cada serviço novo.
 */
class PrazoDeCincoDiasDaAtTest extends TestCase
{
    use DatabaseTruncation;

    protected array $tablesToTruncate = [
        'users', 'wallets', 'vendors', 'schedule_available',
        'services', 'services_types', 'operation_areas', 'addresses',
        'transactions', 'transfers',
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
        $user = User::factory()->create(['first_name' => 'Rui', 'last_name' => 'Tavares']);
        $vendor = Vendor::create([
            'user_id' => $user->id,
            'username' => 'rui_'.$user->id,
            'iban' => 'PT50000201231234567890154',
            'at_user' => null,
            'at_valid' => false,
        ]);

        Address::forceCreate([
            'user_id' => $user->id, 'address_type' => AddressType::FISCAL_ADDRESS,
            'name' => 'Rua de Teste 1', 'address_name' => 'Escritorio', 'street_name' => 'Rua de Teste',
            'street_number' => '1', 'additional_info' => '', 'postal_code' => '4000-001',
            'city' => 'Porto', 'municipality' => 'Porto', 'state' => 'Porto', 'country' => 'Portugal',
            'latitude' => 41.1579, 'longitude' => -8.6291, 'main_address' => true,
        ]);

        return $vendor;
    }

    private function concluiu(Vendor $vendor, int $quantos): void
    {
        for ($i = 0; $i < $quantos; $i++) {
            Service::factory()->create([
                'vendor_id' => $vendor->id,
                'status' => ServiceStatus::CLOSED,
                'payment_status' => PaymentStatus::PAID,
            ]);
        }
    }

    /** Fecha um serviço a sério, que é o que arranca o relógio. */
    private function fecharServico(Vendor $vendor, int $paraOTecnico = 7500): void
    {
        $servico = Service::factory()->create([
            'vendor_id' => $vendor->id,
            'status' => ServiceStatus::FINISHED,
            'payment_status' => PaymentStatus::PAID,
            'amount' => (int) round($paraOTecnico / 0.75),
            'amount_for_vendor' => $paraOTecnico,
        ]);

        (new CloseService($servico))->close();
    }

    // ------------------------------------------------ o relógio arranca e pára

    public function test_o_relogio_arranca_no_terceiro_servico_fechado(): void
    {
        $vendor = $this->tecnico();
        $this->concluiu($vendor, 2);

        $this->assertNull($vendor->fresh()->at_deadline_started_at, 'com dois, ainda não');

        $this->fecharServico($vendor);

        $vendor = $vendor->fresh();
        $this->assertNotNull($vendor->at_deadline_started_at);
        $this->assertSame(5, $vendor->dias_ate_ao_prazo_da_at);
    }

    /** Sem dinheiro em jogo não se ameaça ninguém com a perda de zero euros. */
    public function test_sem_ganhos_retidos_o_relogio_nao_arranca(): void
    {
        $vendor = $this->tecnico();
        $this->concluiu($vendor, 3);

        $vendor->fresh()->comecarPrazoDaAtSeNecessario();

        $this->assertNull($vendor->fresh()->at_deadline_started_at);
    }

    /**
     * O relógio NÃO se reinicia a cada serviço novo. Sem isto, quem continuasse
     * a trabalhar empurrava o prazo para sempre e ele nunca chegava ao fim.
     */
    public function test_um_servico_novo_nao_empurra_o_prazo(): void
    {
        $vendor = $this->tecnico();
        $this->concluiu($vendor, 2);
        $this->fecharServico($vendor);

        $inicio = $vendor->fresh()->at_deadline_started_at;

        $this->travel(2)->days();
        $this->fecharServico($vendor);

        $this->assertEquals($inicio, $vendor->fresh()->at_deadline_started_at);
        $this->assertSame(3, $vendor->fresh()->dias_ate_ao_prazo_da_at, 'passaram 2 dos 5');
    }

    /** Dar a AT pára o relógio, por qualquer caminho que a grave. */
    public function test_dar_a_at_limpa_o_prazo(): void
    {
        $vendor = $this->tecnico();
        $this->concluiu($vendor, 2);
        $this->fecharServico($vendor);

        $this->assertNotNull($vendor->fresh()->at_deadline_started_at);

        $vendor->update(['at_user' => '123456789/1', 'at_valid' => true]);

        $this->assertNull($vendor->fresh()->at_deadline_started_at);
    }

    // ------------------------------------------------------------- a execução

    public function test_dentro_do_prazo_nao_se_perde_nada(): void
    {
        $vendor = $this->tecnico();
        $this->concluiu($vendor, 2);
        $this->fecharServico($vendor);

        $this->travel(4)->days();
        $this->artisan('vendors:executar-prazo-da-at')->assertSuccessful();

        $this->assertSame(7500, $vendor->user->refresh()->balanceInt);
        $this->assertNull($vendor->fresh()->at_forfeited_at);
    }

    public function test_passado_o_prazo_o_saldo_passa_para_a_plataforma(): void
    {
        $vendor = $this->tecnico();
        $this->concluiu($vendor, 2);
        $this->fecharServico($vendor);

        $this->travel(6)->days();
        $this->artisan('vendors:executar-prazo-da-at')->assertSuccessful();

        $vendor = $vendor->fresh();
        $this->assertSame(0, $vendor->user->refresh()->balanceInt);
        $this->assertNotNull($vendor->at_forfeited_at);
        $this->assertSame(7500, (int) $vendor->at_forfeited_amount);

        Notification::assertSentTo($vendor->user, AtDeadlineForfeitedNotification::class);
    }

    /**
     * O TESTE QUE MAIS IMPORTA.
     *
     * O crédito promocional não é pagamento de trabalho, não precisa de fatura
     * e não está em causa em nada disto. Perdê-lo era tirar a alguém um bónus
     * de boas-vindas por causa de papelada de faturação.
     */
    public function test_o_credito_promocional_nunca_se_perde(): void
    {
        $vendor = $this->tecnico();
        $this->concluiu($vendor, 2);
        $this->fecharServico($vendor, 7500);
        $vendor->user->wallet->deposit(2000); // boas-vindas

        $this->assertSame(9500, $vendor->user->refresh()->balanceInt);

        $this->travel(6)->days();
        $this->artisan('vendors:executar-prazo-da-at')->assertSuccessful();

        $this->assertSame(2000, $vendor->user->refresh()->balanceInt, 'os 20 € ficam');
        $this->assertSame(7500, (int) $vendor->fresh()->at_forfeited_amount);
    }

    /** Correr duas vezes não tira o dinheiro duas vezes. */
    public function test_correr_duas_vezes_nao_tira_duas_vezes(): void
    {
        $vendor = $this->tecnico();
        $this->concluiu($vendor, 2);
        $this->fecharServico($vendor);

        $this->travel(6)->days();
        $this->artisan('vendors:executar-prazo-da-at')->assertSuccessful();
        $this->artisan('vendors:executar-prazo-da-at')->assertSuccessful();

        $this->assertSame(0, $vendor->user->refresh()->balanceInt);
        $this->assertSame(7500, (int) $vendor->fresh()->at_forfeited_amount);
    }

    /** O ensaio mostra e não mexe. */
    public function test_o_ensaio_nao_mexe_no_dinheiro(): void
    {
        $vendor = $this->tecnico();
        $this->concluiu($vendor, 2);
        $this->fecharServico($vendor);

        $this->travel(6)->days();
        $this->artisan('vendors:executar-prazo-da-at --ensaio')->assertSuccessful();

        $this->assertSame(7500, $vendor->user->refresh()->balanceInt);
        $this->assertNull($vendor->fresh()->at_forfeited_at);
    }

    // ------------------------------------------------------------- os avisos

    public function test_avisa_a_tres_e_a_um_dia_e_no_ultimo(): void
    {
        foreach ([2 => 3, 4 => 1, 5 => 0] as $diasPassados => $diasEsperados) {
            Notification::fake();

            $vendor = $this->tecnico();
            $this->concluiu($vendor, 2);
            $this->fecharServico($vendor);

            $this->travel($diasPassados)->days();
            $this->artisan('vendors:executar-prazo-da-at')->assertSuccessful();

            Notification::assertSentTo(
                $vendor->user,
                AtDeadlineWarningNotification::class,
                function ($notificacao) use ($vendor, $diasEsperados) {
                    $corpo = $notificacao->toArray($vendor->user);

                    return $diasEsperados === 0
                        ? str_contains($corpo['title'], 'Último dia')
                        : str_contains($corpo['title'], (string) $diasEsperados);
                }
            );

            $this->travelBack();
        }
    }

    /** Nos dias sem marco não se avisa: um aviso diário deixa de se ler. */
    public function test_nao_avisa_todos_os_dias(): void
    {
        $vendor = $this->tecnico();
        $this->concluiu($vendor, 2);
        $this->fecharServico($vendor);

        $this->travel(3)->days(); // faltam 2 — não é marco
        $this->artisan('vendors:executar-prazo-da-at')->assertSuccessful();

        Notification::assertNotSentTo($vendor->user, AtDeadlineWarningNotification::class);
    }

    // ------------------------------------------------------- o que a app lê

    public function test_o_perfil_leva_a_data_de_fim_do_prazo(): void
    {
        $vendor = $this->tecnico();
        $vendor->user->update(['email_verified_at' => now(), 'phone_number_verified_at' => now()]);
        $this->concluiu($vendor, 2);
        $this->fecharServico($vendor);

        $dados = $this->actingAs($vendor->user, 'api')
            ->getJson('/api/v1/auth/me')
            ->assertSuccessful()
            ->json('data');

        $this->assertArrayHasKey('at_deadline_ends_at', $dados);
        $this->assertNotNull($dados['at_deadline_ends_at']);
    }
}
