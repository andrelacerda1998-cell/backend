<?php

namespace Tests\Feature\Vendors;

use App\Enums\Services\AddressType;
use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\Address;
use App\Models\GeneralSettings\Document;
use App\Models\GeneralSettings\Gender;
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

    /**
     * Credita como o `settle()` credita: com o `meta` de serviço.
     *
     * Um `deposit()` a seco conta como CRÉDITO PROMOCIONAL desde 30/09, e o
     * promocional não trava pagamentos. Estes testes falavam de salário e
     * depositavam boas-vindas -- passavam a dizer o contrário do que o nome
     * prometia.
     */
    private function creditaComoServico(Vendor $vendor, int $centimos): void
    {
        $vendor->user->wallet->deposit($centimos, [
            'class' => Service::class,
            'id' => 0,
            'type' => 'internal/services.transactions_type.service',
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
        $this->creditaComoServico($vendor, 5000);

        $vendor = $vendor->fresh();

        $this->assertFalse($vendor->payout_blocked);
        $this->assertSame(0, $vendor->payout_on_hold_amount);
    }

    public function test_ao_terceiro_o_saldo_fica_retido(): void
    {
        $vendor = $this->tecnicoSemAt();
        $this->jaConcluiu($vendor, 3);
        $this->creditaComoServico($vendor, 5000);

        $vendor = $vendor->fresh();

        $this->assertTrue($vendor->payout_blocked);
        $this->assertSame(5000, $vendor->payout_on_hold_amount, 'é o saldo todo que fica retido');
    }

    public function test_com_a_at_dada_nada_fica_retido(): void
    {
        $vendor = $this->tecnicoSemAt();
        $this->jaConcluiu($vendor, 3);
        $this->creditaComoServico($vendor, 5000);
        $vendor->update(['at_user' => '123456789/1', 'at_valid' => true]);

        $vendor = $vendor->fresh();

        $this->assertTrue($vendor->at_required, 'continua a ser exigida');
        $this->assertFalse($vendor->payout_blocked, 'mas já está dada');
        $this->assertSame(0, $vendor->payout_on_hold_amount);
    }

    /**
     * Carteira vazia não trava nada, mesmo com a AT em falta.
     *
     * Antes de 30/09 isto dava "retido" com 0 EUR -- tecnicamente verdade e
     * praticamente absurdo: marcava-se como travado quem não tinha nada a
     * receber. Sem ganhos por pagar não há o que reter.
     */
    public function test_carteira_vazia_nao_trava_nada(): void
    {
        $vendor = $this->tecnicoSemAt();
        $this->jaConcluiu($vendor, 3);

        $vendor = $vendor->fresh();

        $this->assertSame(0, $vendor->ganhos_por_pagar);
        $this->assertFalse($vendor->payout_blocked);
        $this->assertSame(0, $vendor->payout_on_hold_amount);
    }

    // -------------------------------------------------- o backoffice não paga

    public function test_o_backoffice_nao_consegue_transferir_a_um_tecnico_sem_at(): void
    {
        $vendor = $this->tecnicoSemAt();
        $this->jaConcluiu($vendor, 3);
        $this->creditaComoServico($vendor, 5000);

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
        $this->creditaComoServico($vendor, 5000);

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
        $this->creditaComoServico($vendor, 3000);

        $this->withAuth()
            ->putJson("/api/v1/admin/vendor-payments/{$vendor->id}/pay")
            ->assertOk();

        $this->assertSame(0, $vendor->user->refresh()->balanceInt);
    }

    public function test_a_listagem_marca_a_linha_retida(): void
    {
        $retido = $this->tecnicoSemAt();
        $this->jaConcluiu($retido, 3);
        $this->creditaComoServico($retido, 5000);

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
        $this->comMoradaFiscal($vendor);
        $this->creditaComoServico($vendor, 5000);

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
        $this->creditaComoServico($vendor, 4200);

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
        $this->creditaComoServico($vendor, 5000);

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
        $this->creditaComoServico($vendor, 5000);

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
        $this->jaConcluiu($vendor, 3);
        $this->creditaComoServico($vendor, 5000);
        $vendor->update(['iban' => null]);

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
        $this->creditaComoServico($vendor, 5000);

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

    // ------------------------------------- credito promocional nao e salario

    /**
     * O caso dos 8 tecnicos medidos em producao a 30/09.
     *
     * Saldo de 20 EUR, ZERO servicos concluidos: e o credito de boas-vindas, nao
     * e pagamento de trabalho. Travar isto era aplicar uma regra de faturacao a
     * uma coisa que nao se fatura -- e travava 8 pessoas por uma razao que nao
     * lhes dizia respeito.
     */
    public function test_credito_promocional_sozinho_nao_trava_o_pagamento(): void
    {
        $vendor = $this->tecnicoSemAt();
        Address::where('user_id', $vendor->user_id)->forceDelete(); // nem morada fiscal tem
        $vendor->user->wallet->deposit(2000);                        // 20 EUR de boas-vindas

        $vendor = $vendor->fresh();

        $this->assertSame(0, $vendor->completedServices()->count());
        $this->assertSame(0, $vendor->ganhos_por_pagar, 'nada disto foi ganho a trabalhar');
        $this->assertNull($vendor->payoutBlocker(), 'e por isso nao ha nada a reter');

        $this->withAuth()
            ->putJson("/api/v1/admin/vendor-payments/{$vendor->id}/pay")
            ->assertOk();

        $this->assertSame(0, $vendor->user->refresh()->balanceInt);
    }

    /** Mas basta um euro ganho a trabalhar para a regra voltar a valer. */
    public function test_com_dinheiro_de_servicos_a_regra_volta_a_travar(): void
    {
        $vendor = $this->tecnicoSemAt();
        $this->jaConcluiu($vendor, 3);
        $vendor->user->wallet->deposit(2000);                        // promocional

        $servico = Service::factory()->create([
            'vendor_id' => $vendor->id,
            'status' => ServiceStatus::FINISHED,
            'payment_status' => PaymentStatus::PAID,
            'amount' => 10000,
            'amount_for_vendor' => 7500,
        ]);
        (new CloseService($servico))->close();

        $vendor = $vendor->fresh();

        $this->assertSame(7500, $vendor->ganhos_por_pagar, 'so a parte do servico conta');
        $this->assertSame('at_user_missing', $vendor->payoutBlocker());

        $this->withAuth()
            ->putJson("/api/v1/admin/vendor-payments/{$vendor->id}/pay")
            ->assertStatus(409);
    }

    /**
     * Depois de pago, o credito promocional SEGUINTE nao fica preso.
     *
     * Os levantamentos zeram a carteira inteira. Sem subtrair o que ja saiu, os
     * depositos de servicos antigos mantinham "ganhos por pagar" para sempre e
     * o proximo credito ficava travado por dinheiro que ele ja recebeu.
     */
    public function test_depois_de_pago_o_credito_seguinte_nao_fica_preso(): void
    {
        $vendor = $this->tecnicoSemAt();
        $this->jaConcluiu($vendor, 3);
        $vendor->update(['at_user' => '123456789/1', 'at_valid' => true]);

        $servico = Service::factory()->create([
            'vendor_id' => $vendor->id,
            'status' => ServiceStatus::FINISHED,
            'payment_status' => PaymentStatus::PAID,
            'amount' => 10000,
            'amount_for_vendor' => 7500,
        ]);
        (new CloseService($servico))->close();

        // O backoffice paga-lhe tudo.
        $this->withAuth()->putJson("/api/v1/admin/vendor-payments/{$vendor->id}/pay")->assertOk();

        // E agora tira-se-lhe a AT e da-se-lhe credito novo.
        $vendor->update(['at_user' => null, 'at_valid' => false]);
        $vendor->user->wallet->deposit(2000);

        $vendor = $vendor->fresh();

        $this->assertSame(0, $vendor->ganhos_por_pagar, 'o que ganhou ja lhe foi transferido');
        $this->assertNull($vendor->payoutBlocker());
    }
}
