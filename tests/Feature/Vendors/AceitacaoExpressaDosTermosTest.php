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
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Aceitação expressa dos Termos, e o que ela destranca.
 *
 * A razão de existir: a cláusula da perda do saldo retira dinheiro que o técnico
 * ganhou a trabalhar. Uma cláusula dessas só o vincula se ele a tiver aceitado —
 * e provar isso exige saber QUE VERSÃO aceitou e QUANDO, não apenas que carregou
 * num botão algures.
 *
 * O teste mais importante deste ficheiro é o que garante que, sem aceitação
 * registada, o relógio da perda nem sequer arranca.
 */
class AceitacaoExpressaDosTermosTest extends TestCase
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
        config(['scout.driver' => 'null', 'legal.provider_terms.version' => '1.0']);
        Gender::firstOrCreate(['name' => 'Masculino']);
        Notification::fake();
        Queue::fake();
    }

    private function tecnico(): Vendor
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'phone_number_verified_at' => now(),
        ]);
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

    private function aceitou(Vendor $vendor, string $versao = '1.0'): void
    {
        TermsAcceptance::create([
            'user_id' => $vendor->user_id,
            'document' => TermsAcceptance::DOCUMENTO_PRESTADORES,
            'version' => $versao,
            'accepted_at' => now(),
        ]);
    }

    private function fecharTerceiroServico(Vendor $vendor): void
    {
        for ($i = 0; $i < 2; $i++) {
            Service::factory()->create([
                'vendor_id' => $vendor->id,
                'status' => ServiceStatus::CLOSED,
                'payment_status' => PaymentStatus::PAID,
            ]);
        }

        $servico = Service::factory()->create([
            'vendor_id' => $vendor->id,
            'status' => ServiceStatus::FINISHED,
            'payment_status' => PaymentStatus::PAID,
            'amount' => 10000,
            'amount_for_vendor' => 7500,
        ]);

        (new CloseService($servico))->close();
    }

    // ------------------------------------------ o que a aceitação destranca

    /**
     * A RETENÇÃO continua a valer: sem AT não se consegue faturar, e não se paga
     * o que não se fatura. A perda do saldo saiu (ODinheiroNaoSePerdePelaAtTest);
     * esta parte não.
     */
    public function test_sem_aceitacao_o_dinheiro_fica_retido_na_mesma(): void
    {
        $vendor = $this->tecnico();

        $this->fecharTerceiroServico($vendor);

        $vendor = $vendor->fresh();
        $this->assertSame('at_user_missing', $vendor->payoutBlocker());
        $this->assertSame(7500, $vendor->payout_on_hold_amount);
    }

    /** Aceitou a 1.0; a versão em vigor passa a 2.0 — deixa de contar. */
    public function test_uma_versao_antiga_nao_serve(): void
    {
        $vendor = $this->tecnico();
        $this->aceitou($vendor, '1.0');

        config(['legal.provider_terms.version' => '2.0']);

        $this->assertFalse($vendor->fresh()->aceitou_os_termos_em_vigor);
    }

    // ----------------------------------------------------------- o endpoint

    public function test_aceitar_grava_versao_data_e_origem(): void
    {
        $vendor = $this->tecnico();

        $this->actingAs($vendor->user, 'api')
            ->postJson('/api/v1/vendor/terms/accept', ['version' => '1.0'])
            ->assertSuccessful()
            ->assertJsonPath('data.version', '1.0');

        $linha = TermsAcceptance::where('user_id', $vendor->user_id)->firstOrFail();

        $this->assertSame('provider_terms', $linha->document);
        $this->assertSame('1.0', $linha->version);
        $this->assertNotNull($linha->accepted_at);
        $this->assertNotNull($linha->ip, 'a origem faz parte da prova');
    }

    /**
     * Uma app antiga não pode aceitar a versão nova.
     *
     * Se a app mostrar a 1.0 e o servidor já estiver na 2.0, aceitar registaria
     * um consentimento para um texto que ele não viu — que é exactamente o vício
     * que isto existe para evitar.
     */
    public function test_recusa_aceitar_uma_versao_que_nao_e_a_em_vigor(): void
    {
        $vendor = $this->tecnico();
        config(['legal.provider_terms.version' => '2.0']);

        $this->actingAs($vendor->user, 'api')
            ->postJson('/api/v1/vendor/terms/accept', ['version' => '1.0'])
            ->assertStatus(409);

        $this->assertSame(0, TermsAcceptance::count());
    }

    /** Dois toques no botão não são dois factos. */
    public function test_aceitar_duas_vezes_grava_uma_linha_so(): void
    {
        $vendor = $this->tecnico();

        foreach ([1, 2] as $_) {
            $this->actingAs($vendor->user, 'api')
                ->postJson('/api/v1/vendor/terms/accept', ['version' => '1.0'])
                ->assertSuccessful();
        }

        $this->assertSame(1, TermsAcceptance::where('user_id', $vendor->user_id)->count());
    }

    /** A data que fica é a da PRIMEIRA aceitação, que é a que se prova. */
    public function test_a_data_nao_e_reescrita_por_uma_segunda_aceitacao(): void
    {
        $vendor = $this->tecnico();

        $this->actingAs($vendor->user, 'api')
            ->postJson('/api/v1/vendor/terms/accept', ['version' => '1.0'])->assertSuccessful();

        $primeira = TermsAcceptance::where('user_id', $vendor->user_id)->value('accepted_at');

        $this->travel(2)->days();

        $this->actingAs($vendor->user, 'api')
            ->postJson('/api/v1/vendor/terms/accept', ['version' => '1.0'])->assertSuccessful();

        $this->assertEquals(
            $primeira,
            TermsAcceptance::where('user_id', $vendor->user_id)->value('accepted_at')
        );
    }

    public function test_o_estado_diz_a_app_o_que_falta(): void
    {
        $vendor = $this->tecnico();

        $this->actingAs($vendor->user, 'api')
            ->getJson('/api/v1/vendor/terms')
            ->assertSuccessful()
            ->assertJsonPath('data.acceptance_required', true)
            ->assertJsonPath('data.version_required', '1.0')
            ->assertJsonPath('data.version_accepted', null);

        $this->aceitou($vendor);

        $this->actingAs($vendor->user->fresh(), 'api')
            ->getJson('/api/v1/vendor/terms')
            ->assertSuccessful()
            ->assertJsonPath('data.acceptance_required', false);
    }

    public function test_o_perfil_leva_o_estado_da_aceitacao(): void
    {
        $vendor = $this->tecnico();

        $dados = $this->actingAs($vendor->user, 'api')
            ->getJson('/api/v1/auth/me')->assertSuccessful()->json('data');

        $this->assertTrue($dados['terms_acceptance_required']);
        $this->assertSame('1.0', $dados['terms_version_required']);
        $this->assertNull($dados['terms_version_accepted']);
    }
}
