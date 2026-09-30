<?php

namespace Tests\Feature\Vendors;

use App\Enums\Services\AddressType;
use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\GeneralSettings\Gender;
use App\Models\Address;
use App\Models\GeneralSettings\Document;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Common\Services\CloseService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * O trabalho conta, o dinheiro entra na carteira, e não sai até se poder faturar.
 *
 * Esta é a regra que o dono do produto pediu, por palavras dele: os três
 * primeiros serviços fazem-se sem o subutilizador da AT, o cliente é cobrado na
 * mesma, o dinheiro APARECE na carteira do técnico -- e só depois de ele dar o
 * acesso é que o recebe de facto.
 *
 * As três partes têm de ser testadas juntas porque é fácil acertar em duas e
 * falhar a terceira, e cada falha é de um tipo diferente de mau:
 *  - não creditar a carteira = o técnico trabalhou e não vê o dinheiro dele;
 *  - não travar a transferência = a Piquet paga trabalho que não pode faturar;
 *  - travar o serviço = o técnico nem chega a trabalhar.
 *
 * NÃO RefreshDatabase: o bavix/laravel-wallet só escreve `wallets.balance`
 * quando a transação chega ao nível 0 (ver a nota longa em
 * AdminVendorPaymentsApiTest). Com RefreshDatabase o saldo nunca aparece na
 * coluna e metade destes testes passaria a medir zero contra zero.
 */
class DinheiroRetidoTest extends TestCase
{
    use DatabaseTruncation;

    protected array $tablesToTruncate = [
        'users', 'wallets', 'vendors', 'schedule_available',
        'services', 'services_types', 'operation_areas', 'addresses', 'documents',
        'transactions', 'transfers',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Criar um Vendor dispara observers que tentam indexar no Meilisearch.
        config(['scout.driver' => 'null']);

        Gender::firstOrCreate(['name' => 'Masculino']);
        Notification::fake();
        Queue::fake(); // o fecho dispara o job de faturação (InvoiceXpress)
    }

    private function withAuth(): static
    {
        config(['services.admin_api.token' => 'a-valid-token']);

        return $this->withHeaders(['Authorization' => 'Bearer a-valid-token']);
    }

    private function tecnicoSemAt(): Vendor
    {
        $user = User::factory()->create(['first_name' => 'Rui', 'last_name' => 'Tavares']);

        $vendor = Vendor::create([
            'user_id' => $user->id,
            'username' => 'rui_'.$user->id,
            'iban' => 'PT50000201231234567890154',
            'at_user' => null,
            'at_valid' => false,
        ]);

        // IBAN e morada fiscal preenchidos DE PROPOSITO: sem eles o pagamento
        // ficaria retido por outra razao e estes testes passariam pelo motivo
        // errado -- verdes a medir uma coisa que nao e a que dizem medir.
        $this->comMoradaFiscal($vendor);

        return $vendor;
    }

    /** Nao ha AddressFactory; `state`/`municipality` sao NOT NULL sem default. */
    private function comMoradaFiscal(Vendor $vendor): void
    {
        Address::forceCreate([
            'user_id' => $vendor->user_id,
            'address_type' => AddressType::FISCAL_ADDRESS,
            'name' => 'Rua de Teste 1, Porto',
            'address_name' => 'Escritorio',
            'street_name' => 'Rua de Teste',
            'street_number' => '1',
            'additional_info' => '',
            'postal_code' => '4000-001',
            'city' => 'Porto',
            'municipality' => 'Porto',
            'state' => 'Porto',
            'country' => 'Portugal',
            'latitude' => 41.1579,
            'longitude' => -8.6291,
            'main_address' => true,
        ]);
    }

    /** Serviços já concluídos, para pôr o técnico numa determinada contagem. */
    private function jaConcluiu(Vendor $vendor, int $quantos): void
    {
        for ($i = 0; $i < $quantos; $i++) {
            Service::factory()->create([
                'vendor_id' => $vendor->id,
                'status' => ServiceStatus::CLOSED,
                'payment_status' => PaymentStatus::PAID,
            ]);
        }
    }

    // ---------------------------------------------------------------- o crédito

    /**
     * O TERCEIRO serviço — o que faz a AT passar a ser exigida — fecha
     * normalmente e o dinheiro entra na carteira. Se este teste falhar, o
     * técnico trabalhou de graça.
     */
    public function test_o_terceiro_servico_fecha_e_credita_a_carteira_mesmo_sem_at(): void
    {
        $vendor = $this->tecnicoSemAt();
        $this->jaConcluiu($vendor, 2);

        $servico = Service::factory()->create([
            'vendor_id' => $vendor->id,
            'status' => ServiceStatus::FINISHED,
            'payment_status' => PaymentStatus::PAID,
            'amount' => 10000,
            'amount_for_vendor' => 7500,
        ]);

        $antes = $vendor->user->balanceInt;

        (new CloseService($servico))->close();

        $servico->refresh();
        $this->assertSame(ServiceStatus::CLOSED, $servico->status, 'o serviço tem de fechar');
        $this->assertSame(PaymentStatus::PAID, $servico->payment_status, 'o cliente é cobrado na mesma');

        $this->assertSame(
            7500,
            $vendor->user->refresh()->balanceInt - $antes,
            'o dinheiro tem de aparecer na carteira do técnico'
        );
    }

    /** E o QUARTO, se por alguma razão chegar a ser executado, credita igual. */
    public function test_um_quarto_servico_tambem_credita(): void
    {
        $vendor = $this->tecnicoSemAt();
        $this->jaConcluiu($vendor, 3);

        $servico = Service::factory()->create([
            'vendor_id' => $vendor->id,
            'status' => ServiceStatus::FINISHED,
            'payment_status' => PaymentStatus::PAID,
            'amount' => 8000,
            'amount_for_vendor' => 6000,
        ]);

        $antes = $vendor->user->balanceInt;

        (new CloseService($servico))->close();

        $this->assertSame(6000, $vendor->user->refresh()->balanceInt - $antes);
    }

    // ----------------------------------------------------------- a retenção

    public function test_antes_do_terceiro_nao_ha_nada_retido(): void
    {
        $vendor = $this->tecnicoSemAt();
        $this->jaConcluiu($vendor, 2);
        $vendor->user->wallet->deposit(5000);

        $vendor = $vendor->fresh();

        $this->assertFalse($vendor->payout_blocked);
        $this->assertSame(0, $vendor->payout_on_hold_amount);
    }

    public function test_ao_terceiro_o_saldo_fica_retido(): void
    {
        $vendor = $this->tecnicoSemAt();
        $this->jaConcluiu($vendor, 3);
        $vendor->user->wallet->deposit(5000);

        $vendor = $vendor->fresh();

        $this->assertTrue($vendor->payout_blocked);
        $this->assertSame(5000, $vendor->payout_on_hold_amount, 'é o saldo todo que fica retido');
    }

    public function test_com_a_at_dada_nada_fica_retido(): void
    {
        $vendor = $this->tecnicoSemAt();
        $this->jaConcluiu($vendor, 3);
        $vendor->user->wallet->deposit(5000);
        $vendor->update(['at_user' => '123456789/1', 'at_valid' => true]);

        $vendor = $vendor->fresh();

        $this->assertTrue($vendor->at_required, 'continua a ser exigida');
        $this->assertFalse($vendor->payout_blocked, 'mas já está dada');
        $this->assertSame(0, $vendor->payout_on_hold_amount);
    }

    /** Retido com saldo a zero não é 0 por acaso — é 0 porque não há nada. */
    public function test_retido_com_carteira_vazia_e_zero(): void
    {
        $vendor = $this->tecnicoSemAt();
        $this->jaConcluiu($vendor, 3);

        $vendor = $vendor->fresh();

        $this->assertTrue($vendor->payout_blocked);
        $this->assertSame(0, $vendor->payout_on_hold_amount);
    }

    // -------------------------------------------------- o backoffice não paga

    public function test_o_backoffice_nao_consegue_transferir_a_um_tecnico_sem_at(): void
    {
        $vendor = $this->tecnicoSemAt();
        $this->jaConcluiu($vendor, 3);
        $vendor->user->wallet->deposit(5000);

        $this->withAuth()
            ->putJson("/api/v1/admin/vendor-payments/{$vendor->id}/pay")
            ->assertStatus(409);

        $this->assertSame(
            5000,
            $vendor->user->refresh()->balanceInt,
            'o saldo NÃO pode sair da carteira'
        );
    }

    public function test_depois_de_dar_a_at_a_transferencia_passa(): void
    {
        $vendor = $this->tecnicoSemAt();
        $this->jaConcluiu($vendor, 3);
        $vendor->user->wallet->deposit(5000);

        $vendor->update(['at_user' => '123456789/1', 'at_valid' => true]);

        $this->withAuth()
            ->putJson("/api/v1/admin/vendor-payments/{$vendor->id}/pay")
            ->assertOk()
            ->assertJsonPath('data.amount_paid', 50);

        $this->assertSame(0, $vendor->user->refresh()->balanceInt);
    }

    /** Quem nunca chegou ao terceiro serviço recebe normalmente. */
    public function test_um_tecnico_com_dois_servicos_recebe_sem_at(): void
    {
        $vendor = $this->tecnicoSemAt();
        $this->jaConcluiu($vendor, 2);
        $vendor->user->wallet->deposit(3000);

        $this->withAuth()
            ->putJson("/api/v1/admin/vendor-payments/{$vendor->id}/pay")
            ->assertOk();

        $this->assertSame(0, $vendor->user->refresh()->balanceInt);
    }

    public function test_a_listagem_marca_a_linha_retida(): void
    {
        $retido = $this->tecnicoSemAt();
        $this->jaConcluiu($retido, 3);
        $retido->user->wallet->deposit(5000);

        $this->withAuth()
            ->getJson('/api/v1/admin/vendor-payments')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $retido->id)
            ->assertJsonPath('data.items.0.payout_blocked', true);
    }

    // ------------------------------------------------------- o que a app lê

    public function test_o_perfil_diz_a_app_que_o_dinheiro_esta_retido(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'phone_number_verified_at' => now(),
        ]);
        $vendor = Vendor::create([
            'user_id' => $user->id,
            'username' => 'ana_'.$user->id,
            'iban' => 'PT50000201231234567890154',
            'at_user' => null,
            'at_valid' => false,
        ]);
        $this->jaConcluiu($vendor, 3);

        $this->actingAs($user, 'api')
            ->getJson('/api/v1/auth/me')
            ->assertSuccessful()
            ->assertJsonPath('data.payout_blocked', true)
            ->assertJsonPath('data.at_required', true);
    }

    public function test_os_ganhos_dizem_quanto_esta_retido(): void
    {
        $vendor = $this->tecnicoSemAt();
        $this->jaConcluiu($vendor, 3);
        $vendor->user->wallet->deposit(4200);

        $this->actingAs($vendor->user, 'api')
            ->getJson('/api/v1/vendor/stats')
            ->assertSuccessful()
            ->assertJsonPath('data.payout_blocked', true)
            ->assertJsonPath('data.payout_on_hold_amount', 4200);
    }

    // ------------------------------------------- as outras razoes de retencao

    /**
     * A brecha que este ficheiro nao apanhava: AT dada e validada, morada fiscal
     * em falta. O dinheiro nao esta retido PELA AT -- e tem de ficar retido na
     * mesma, porque sem morada fiscal nao se emite fatura. Antes disto o
     * backoffice transferia 225 EUR por trabalho que a Piquet nao podia faturar.
     */
    public function test_sem_morada_fiscal_o_pagamento_fica_retido_mesmo_com_a_at_dada(): void
    {
        $vendor = $this->tecnicoSemAt();
        Address::where('user_id', $vendor->user_id)->forceDelete();
        $this->jaConcluiu($vendor, 3);
        $vendor->update(['at_user' => '123456789/1', 'at_valid' => true]);
        $vendor->user->wallet->deposit(5000);

        $vendor = $vendor->fresh();

        $this->assertTrue($vendor->at_ready, 'a AT esta dada');
        $this->assertSame('fiscal_address_missing', $vendor->payoutBlocker());
        $this->assertTrue($vendor->payout_blocked);

        $this->withAuth()
            ->putJson("/api/v1/admin/vendor-payments/{$vendor->id}/pay")
            ->assertStatus(409);

        $this->assertSame(5000, $vendor->user->refresh()->balanceInt);
    }

    /** Sem IBAN nao ha para onde transferir. Literal. */
    public function test_sem_iban_o_pagamento_fica_retido(): void
    {
        $vendor = $this->tecnicoSemAt();
        $vendor->update(['iban' => null, 'at_user' => '123456789/1', 'at_valid' => true]);
        $vendor->user->wallet->deposit(5000);

        $vendor = $vendor->fresh();

        $this->assertSame('iban_missing', $vendor->payoutBlocker());

        $this->withAuth()
            ->putJson("/api/v1/admin/vendor-payments/{$vendor->id}/pay")
            ->assertStatus(409);

        $this->assertSame(5000, $vendor->user->refresh()->balanceInt);
    }

    /** A ordem importa: o IBAN e o primeiro a resolver-se, por isso e o que se diz. */
    public function test_com_tudo_em_falta_diz_a_razao_mais_a_montante(): void
    {
        $vendor = $this->tecnicoSemAt();
        Address::where('user_id', $vendor->user_id)->forceDelete();
        $vendor->update(['iban' => null]);
        $this->jaConcluiu($vendor, 3);

        $this->assertSame('iban_missing', $vendor->fresh()->payoutBlocker());
    }

    /**
     * Documentos por validar NAO retem o dinheiro.
     *
     * Estao no `invoicingBlocker()` porque travam o tecnico de TRABALHAR, mas nao
     * travam a fatura de trabalho ja feito. Reter o dinheiro de alguem porque o
     * cartao de cidadao esta a ser revalidado e castiga-lo por uma coisa que nao
     * impede pagar-lhe.
     */
    public function test_documentos_por_validar_nao_retem_o_dinheiro(): void
    {
        $vendor = $this->tecnicoSemAt();
        $vendor->update(['at_user' => '123456789/1', 'at_valid' => true]);
        $vendor->user->wallet->deposit(5000);

        // Sem nenhum documento OBRIGATORIO definido, `all_documents_verified` e
        // true e o teste passaria sem provar nada. E preciso existir um por
        // entregar para o cenario ser o que o nome diz.
        Document::create(['name' => 'Cartao de Cidadao', 'required' => true]);

        $vendor = $vendor->fresh();

        $this->assertFalse($vendor->all_documents_verified, 'ha um documento obrigatorio por validar');
        $this->assertNull($vendor->payoutBlocker(), 'e mesmo assim o dinheiro sai');

        $this->withAuth()
            ->putJson("/api/v1/admin/vendor-payments/{$vendor->id}/pay")
            ->assertOk();

        $this->assertSame(0, $vendor->user->refresh()->balanceInt);
    }
}
