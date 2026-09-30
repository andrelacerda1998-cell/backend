<?php

namespace Tests\Feature\Vendors;

use App\Enums\Services\AddressType;
use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Enums\Vendors\StatusVendor;
use App\Models\Address;
use App\Models\GeneralSettings\Document;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O acesso à AT só é exigido a partir do quarto serviço.
 *
 * Criar um subutilizador no Portal das Finanças obriga o profissional a sair da
 * app, entrar noutro sítio com outras credenciais e voltar. É o passo de maior
 * fricção do registo inteiro, e estava a travar gente ANTES de ela ter ganho um
 * único euro — um custo cobrado antes de haver benefício.
 *
 * Passa a ser uma condição para CONTINUAR: três serviços feitos sem ela, e ao
 * terceiro concluído fica exigida.
 *
 * A FRONTEIRA QUE ESTES TESTES PRENDEM, e que é a parte fácil de partir: os
 * outros documentos NÃO mudam. Cartão de Cidadão, Registo Criminal e Declaração
 * de Início de Atividade continuam obrigatórios desde o dia zero. São de
 * identidade e idoneidade; o acesso à AT é de faturação, e só é preciso quando
 * há mesmo o que faturar.
 */
class AtSoDepoisDeTresServicosTest extends TestCase
{
    use RefreshDatabase;

    /** Um profissional pronto para tudo MENOS o acesso à AT. */
    private function semAt(): Vendor
    {
        $user = User::factory()->create([
            'is_test' => true,
            'email_verified_at' => now(),
            'phone_number_verified_at' => now(),
        ]);

        return Vendor::factory()->create([
            'user_id' => $user->id,
            'status' => StatusVendor::ONLINE,
            'iban' => 'PT50000000000000000000000',
            'invoice_workspace' => 'ws-'.$user->id,
            'at_user' => null,
            'at_valid' => false,
        ]);
    }

    private function comAt(): Vendor
    {
        $vendor = $this->semAt();
        $vendor->update(['at_user' => '999999999/1']);
        $vendor->forceFill(['at_valid' => true])->save();

        return $vendor->fresh();
    }

    /** Serviços levados até ao fim por este profissional. */
    private function concluidos(Vendor $vendor, int $quantos, ServiceStatus $estado = ServiceStatus::CLOSED): void
    {
        for ($i = 0; $i < $quantos; $i++) {
            Service::factory()->create([
                'vendor_id' => $vendor->id,
                'status' => $estado,
                'payment_status' => PaymentStatus::PAID,
            ]);
        }
    }

    // ------------------------------------------------- antes dos três

    public function test_sem_servico_nenhum_a_at_nao_e_exigida(): void
    {
        $vendor = $this->semAt();

        $this->assertFalse($vendor->at_required);
        $this->assertSame(3, $vendor->services_until_at_required);
    }

    public function test_com_dois_servicos_feitos_ainda_nao_e_exigida(): void
    {
        $vendor = $this->semAt();
        $this->concluidos($vendor, 2);

        $this->assertFalse($vendor->fresh()->at_required);
        $this->assertSame(1, $vendor->fresh()->services_until_at_required);
    }

    // --------------------------------------------------- ao terceiro

    public function test_ao_terceiro_servico_concluido_passa_a_ser_exigida(): void
    {
        $vendor = $this->semAt();
        $this->concluidos($vendor, 3);

        $this->assertTrue($vendor->fresh()->at_required);
        $this->assertSame(0, $vendor->fresh()->services_until_at_required);
    }

    /**
     * Conta o trabalho FEITO, não o dinheiro recebido.
     *
     * `ClosedPendingPayment` é um serviço executado à espera de cobrança, e
     * `Archived` é um fechado que o backoffice arrumou depois. Não os contar
     * deixava o profissional a trabalhar de graça para lá dos três por uma razão
     * administrativa que não é dele.
     */
    public function test_conta_tambem_os_fechados_por_pagar_e_os_arquivados(): void
    {
        $vendor = $this->semAt();
        $this->concluidos($vendor, 1, ServiceStatus::CLOSED);
        $this->concluidos($vendor, 1, ServiceStatus::CLOSED_PENDING_PAYMENT);
        $this->concluidos($vendor, 1, ServiceStatus::ARCHIVED);

        $this->assertTrue($vendor->fresh()->at_required);
    }

    public function test_um_servico_cancelado_nao_conta(): void
    {
        $vendor = $this->semAt();
        $this->concluidos($vendor, 2);
        $this->concluidos($vendor, 5, ServiceStatus::CANCELED);

        $this->assertFalse($vendor->fresh()->at_required);
    }

    // ------------------------------------------------ o que o portão faz

    /** A razão de tudo isto: sem AT e sem serviços feitos, ele TRABALHA. */
    public function test_sem_at_e_sem_servicos_pode_aceitar(): void
    {
        $vendor = $this->semAt();

        $this->assertTrue($vendor->can_accept_service);
    }

    public function test_sem_at_e_com_tres_servicos_deixa_de_poder(): void
    {
        $vendor = $this->semAt();
        $this->concluidos($vendor, 3);

        $this->assertFalse($vendor->fresh()->can_accept_service);
    }

    public function test_com_at_continua_a_poder_depois_dos_tres(): void
    {
        $vendor = $this->comAt();
        $this->concluidos($vendor, 3);

        $this->assertTrue($vendor->fresh()->can_accept_service);
    }

    /** A mesma regra na pesquisa: elegível e invisível ao mesmo tempo era pior. */
    public function test_aparece_na_pesquisa_antes_dos_tres_sem_at(): void
    {
        $vendor = $this->semAt();
        $this->assertTrue($vendor->shouldBeSearchable());

        $this->concluidos($vendor, 3);
        $this->assertFalse($vendor->fresh()->shouldBeSearchable());
    }

    // --------------------------------------- o que a app e o backoffice veem

    public function test_o_bloqueio_da_conta_nomeia_a_at_so_quando_ela_trava(): void
    {
        $vendor = $this->semAt();

        // Com morada fiscal: sem ela, o `invoicingBlocker` para mais cedo em
        // `fiscal_address_missing` e nunca chega a olhar para a AT. A ordem dos
        // bloqueios e deliberada — a AT e a ultima, por ser a unica que aparece
        // DEPOIS de o tecnico ja ter trabalhado.
        Address::create([
            'user_id' => $vendor->user_id,
            'name' => 'Rua de Exemplo 1',
            'street_name' => 'Rua de Exemplo',
            'street_number' => '1',
            'postal_code' => '4000-000',
            'city' => 'Porto',
            'municipality' => 'Porto',
            'state' => 'Porto',
            'country' => 'Portugal',
            'latitude' => 41.1478,
            'longitude' => -8.6110,
            'address_type' => AddressType::FISCAL_ADDRESS,
        ]);

        $this->assertNull($vendor->fresh()->invoicingBlocker(), 'nada o trava antes dos tres');

        $this->concluidos($vendor, 3);
        $this->assertSame('at_user_missing', $vendor->fresh()->invoicingBlocker());
    }

    // ------------------------------------------ a fronteira que não muda

    /**
     * O resto dos documentos continua a valer desde o dia zero.
     *
     * Este é o teste que impede alguém de "simplificar" a regra e deixar passar
     * um profissional sem Cartão de Cidadão nos primeiros serviços.
     */
    public function test_os_outros_documentos_continuam_obrigatorios_desde_o_inicio(): void
    {
        $vendor = $this->semAt();

        Document::create(['name' => 'Cartão de Cidadão', 'required' => true]);

        // Exigido e não entregue: não passa, mesmo sem serviço nenhum feito e
        // mesmo com a AT fora do caminho.
        $this->assertFalse($vendor->fresh()->all_documents_verified);
        $this->assertFalse($vendor->fresh()->can_accept_service);
    }
}
