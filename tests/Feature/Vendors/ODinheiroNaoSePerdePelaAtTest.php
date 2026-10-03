<?php

namespace Tests\Feature\Vendors;

use App\Enums\Services\AddressType;
use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\Address;
use App\Models\GeneralSettings\Gender;
use App\Models\Service;
use App\Models\TermsAcceptance;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Common\Services\CloseService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * O DINHEIRO GANHO NUNCA SE PERDE POR FALTA DA SUBAT.
 *
 * Havia um prazo de 5 dias: se o técnico não desse o subutilizador da AT, um
 * cron às 9h retirava-lhe o saldo ganho a trabalhar e passava-o para a Piquet.
 * Decisão do André (03/10/2026): sai. A Piquet não fica com o dinheiro de quem
 * trabalhou.
 *
 * O que FICA é a RETENÇÃO, e são coisas diferentes. Sem a subAT o técnico não
 * consegue emitir fatura, e não se paga o que não se consegue faturar -- não é
 * uma penalização, é a ausência de um acto. O dinheiro continua a ser dele, e
 * sai assim que a AT chegar.
 *
 * Estes testes guardam as duas metades: o saldo nunca é retirado, E continua
 * retido até à AT.
 */
class ODinheiroNaoSePerdePelaAtTest extends TestCase
{
    use DatabaseTruncation;

    protected array $tablesToTruncate = [
        'users', 'wallets', 'vendors', 'schedule_available',
        'services', 'services_types', 'operation_areas', 'addresses',
        'transactions', 'transfers', 'terms_acceptances',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null']);
        Gender::firstOrCreate(['name' => 'Masculino']);
        Notification::fake();
        Queue::fake();
    }

    /**
     * Com os Termos ACEITES de propósito: era essa a condição para o relógio da
     * perda arrancar. Se a perda ainda existisse, é com este técnico que se via.
     */
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

        TermsAcceptance::create([
            'user_id' => $user->id,
            'document' => TermsAcceptance::DOCUMENTO_PRESTADORES,
            'version' => config('legal.provider_terms.version'),
            'accepted_at' => now(),
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

    /** Técnico no ponto exacto em que a perda arrancava: 3.º serviço fechado, sem AT. */
    private function tecnicoSemAtComDinheiro(): Vendor
    {
        $vendor = $this->tecnico();
        $this->concluiu($vendor, 2);
        $this->fecharServico($vendor);

        return $vendor->fresh();
    }

    // ---------------------------------------------------------------- nunca se perde

    /** O relógio da perda já não arranca -- nem com os Termos aceites. */
    public function test_o_relogio_nao_arranca_no_terceiro_servico(): void
    {
        $vendor = $this->tecnicoSemAtComDinheiro();

        $this->assertNull($vendor->at_deadline_started_at);
    }

    /** Um mês depois o saldo continua todo na carteira dele. */
    public function test_um_mes_depois_o_dinheiro_continua_na_carteira(): void
    {
        $vendor = $this->tecnicoSemAtComDinheiro();
        $saldo = $vendor->user->fresh()->balanceInt;
        $this->assertGreaterThan(0, $saldo);

        $this->travel(30)->days();
        $this->artisan('schedule:run')->assertSuccessful();

        $this->assertSame($saldo, $vendor->user->fresh()->balanceInt);
        $this->assertNull($vendor->fresh()->at_forfeited_at);
    }

    /**
     * UM RELÓGIO ANTIGO NÃO TIRA NADA.
     *
     * É o caso de produção: técnicos com `at_deadline_started_at` preenchido de
     * antes desta alteração. As colunas ficam (são dados, e o registo de
     * qualquer perda que já tenha acontecido), mas já nada as lê para retirar.
     */
    public function test_um_relogio_que_ja_estava_a_correr_nao_tira_nada(): void
    {
        $vendor = $this->tecnicoSemAtComDinheiro();
        $vendor->forceFill(['at_deadline_started_at' => now()->subDays(30)])->save();
        $saldo = $vendor->user->fresh()->balanceInt;

        $this->artisan('schedule:run')->assertSuccessful();

        $this->assertSame($saldo, $vendor->user->fresh()->balanceInt);
        $this->assertNull($vendor->fresh()->at_forfeited_at);
    }

    /** Não há nenhum comando que retire o saldo. */
    public function test_o_comando_da_perda_nao_existe(): void
    {
        $this->assertArrayNotHasKey('vendors:executar-prazo-da-at', Artisan::all());
    }

    /** E o agendador não corre nada parecido. */
    public function test_o_agendador_nao_tem_a_perda(): void
    {
        $comandos = collect(app(Schedule::class)->events())->map(fn ($e) => (string) $e->command)->implode("\n");

        $this->assertStringNotContainsString('prazo-da-at', $comandos);
    }

    /** O perfil deixa de mandar uma data de fim de prazo: a app não conta para nada. */
    public function test_o_perfil_nao_tem_data_de_fim_de_prazo(): void
    {
        $vendor = $this->tecnicoSemAtComDinheiro();

        $dados = $this->actingAs($vendor->user, 'api')->getJson('/api/v1/auth/me')
            ->assertOk()
            ->json('data');

        $this->assertArrayNotHasKey('at_deadline_ends_at', $dados);
    }

    // ---------------------------------------------------------------- mas fica retido

    /** Sem a AT não se paga: não se consegue faturar. */
    public function test_sem_at_o_dinheiro_fica_retido(): void
    {
        $vendor = $this->tecnicoSemAtComDinheiro();

        $this->assertTrue($vendor->payout_blocked);
        $this->assertSame('at_user_missing', $vendor->payoutBlocker());
    }

    /** E sai assim que ele der a AT. */
    public function test_dar_a_at_liberta_o_dinheiro(): void
    {
        $vendor = $this->tecnicoSemAtComDinheiro();

        $vendor->forceFill(['at_user' => '123456789/1', 'at_valid' => true])->save();

        $this->assertFalse($vendor->fresh()->payout_blocked);
    }
}
