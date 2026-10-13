<?php

namespace Tests\Feature\Vendors;

use App\Enums\Services\AddressType;
use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Enums\Vendors\StatusVendor;
use App\Jobs\Services\CreateInvoiceJob;
use App\Models\Address;
use App\Models\GeneralSettings\Document;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorScheduleSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O IBAN e a morada fiscal só são exigidos a partir do quarto serviço, como a AT.
 *
 * Decisão do André (13/10/2026): pedir os dados de faturação a quem ainda não
 * ganhou um euro era um portão à entrada. Passam a ser uma condição para
 * CONTINUAR, com a mesma fronteira que a AT já tinha (Vendor::SERVICOS_ANTES_DA_AT).
 *
 * A morada fiscal entra pela conta de faturação: a equipa só a consegue criar com
 * a morada (ver `Vendor::invoicingBlocker()`), e é a conta que os portões olham.
 *
 * O QUE NÃO MUDA, e estes testes prendem:
 *  - os documentos continuam obrigatórios desde o dia zero;
 *  - o dinheiro continua retido sem IBAN ou sem morada fiscal (DinheiroRetidoTest).
 */
class FaturacaoSoDepoisDeTresServicosTest extends TestCase
{
    use RefreshDatabase;

    /** Contactos verificados e documentos em dia; sem IBAN, sem conta de faturação, sem AT. */
    private function semFaturacao(): Vendor
    {
        $user = User::factory()->create([
            'is_test' => true,
            'email_verified_at' => now(),
            'phone_number_verified_at' => now(),
        ]);

        return Vendor::factory()->create([
            'user_id' => $user->id,
            'status' => StatusVendor::ONLINE,
            'iban' => null,
            // A coluna não aceita null: sem conta de faturação é texto vazio,
            // que é o que todos os técnicos novos têm.
            'invoice_workspace' => '',
            'at_user' => null,
            'at_valid' => false,
        ]);
    }

    /** O mesmo profissional com os três dados de faturação entregues. */
    private function comFaturacao(): Vendor
    {
        $vendor = $this->semFaturacao();
        $vendor->update([
            'iban' => 'PT50000000000000000000000',
            'invoice_workspace' => 'ws-'.$vendor->user_id,
            'at_user' => '999999999/1',
        ]);
        $vendor->forceFill(['at_valid' => true])->save();

        return $vendor->fresh();
    }

    private function concluidos(Vendor $vendor, int $quantos): void
    {
        for ($i = 0; $i < $quantos; $i++) {
            Service::factory()->create([
                'vendor_id' => $vendor->id,
                'status' => ServiceStatus::CLOSED,
                'payment_status' => PaymentStatus::PAID,
                'is_test' => true,
            ]);
        }
    }

    // ------------------------------------------------- antes dos três

    /** A razão de tudo isto: só com os documentos, ele TRABALHA. */
    public function test_so_com_os_documentos_e_sem_servicos_pode_aceitar(): void
    {
        $vendor = $this->semFaturacao();

        $this->assertTrue($vendor->faturacao_em_dia);
        $this->assertTrue($vendor->can_accept_service);
    }

    public function test_com_dois_servicos_feitos_ainda_pode(): void
    {
        $vendor = $this->semFaturacao();
        $this->concluidos($vendor, 2);

        $this->assertTrue($vendor->fresh()->can_accept_service);
    }

    public function test_pode_ficar_online_sem_iban_nem_conta_de_faturacao(): void
    {
        $vendor = $this->semFaturacao();
        $vendor->update(['status' => StatusVendor::OFFLINE]);

        $this->actingAs($vendor->user, 'api')
            ->putJson('/api/v1/vendor/status', ['status' => StatusVendor::ONLINE->value])
            ->assertOk();
    }

    /** Elegível e invisível ao mesmo tempo era pior do que nenhuma das duas. */
    public function test_aparece_na_pesquisa_antes_dos_tres(): void
    {
        $vendor = $this->semFaturacao();
        $this->assertTrue($vendor->shouldBeSearchable());

        $this->concluidos($vendor, 3);
        $this->assertFalse($vendor->fresh()->shouldBeSearchable());
    }

    public function test_aparece_na_pesquisa_de_agendados_antes_dos_tres(): void
    {
        $vendor = $this->semFaturacao();
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
            'address_type' => AddressType::SCHEDULE_ADDRESS,
        ]);
        $vendor->scheduleAvailable()->update(['is_enabled' => true]);

        $this->assertTrue(VendorScheduleSearch::findOrFail($vendor->id)->shouldBeSearchable());

        $this->concluidos($vendor, 3);
        $this->assertFalse(VendorScheduleSearch::findOrFail($vendor->id)->shouldBeSearchable());
    }

    public function test_o_backoffice_nao_lista_o_iban_como_impedimento_antes_dos_tres(): void
    {
        $vendor = $this->semFaturacao();

        $this->assertNotContains(
            __('backoffice/vendor.infolist.eligibility.no_iban'),
            $vendor->cannotAcceptServiceReasons()->all(),
        );
        $this->assertNotContains(
            __('backoffice/vendor.infolist.eligibility.no_workspace'),
            $vendor->cannotAcceptServiceReasons()->all(),
        );

        $this->concluidos($vendor, 3);

        $this->assertContains(
            __('backoffice/vendor.infolist.eligibility.no_iban'),
            $vendor->fresh()->cannotAcceptServiceReasons()->all(),
        );
    }

    // ------------------------------------------------- a partir dos três

    public function test_ao_terceiro_servico_sem_iban_deixa_de_poder(): void
    {
        $vendor = $this->semFaturacao();
        $this->concluidos($vendor, 3);

        $vendor = $vendor->fresh();
        $this->assertFalse($vendor->faturacao_em_dia);
        $this->assertFalse($vendor->can_accept_service);
    }

    /** A AT sozinha já não chega: o IBAN passou a ter a mesma fronteira. */
    public function test_so_com_a_at_e_sem_iban_deixa_de_poder_ao_terceiro(): void
    {
        $vendor = $this->comFaturacao();
        $vendor->update(['iban' => null]);
        $this->concluidos($vendor, 3);

        $this->assertFalse($vendor->fresh()->can_accept_service);
    }

    public function test_com_tudo_entregue_continua_a_poder_depois_dos_tres(): void
    {
        $vendor = $this->comFaturacao();
        $this->concluidos($vendor, 3);

        $vendor = $vendor->fresh();
        $this->assertTrue($vendor->faturacao_em_dia);
        $this->assertTrue($vendor->can_accept_service);
    }

    public function test_deixa_de_poder_ficar_online_sem_conta_de_faturacao_ao_terceiro(): void
    {
        $vendor = $this->comFaturacao();
        $vendor->update(['status' => StatusVendor::OFFLINE, 'invoice_workspace' => '']);
        $this->concluidos($vendor, 3);

        $this->actingAs($vendor->user, 'api')
            ->putJson('/api/v1/vendor/status', ['status' => StatusVendor::ONLINE->value])
            ->assertStatus(422);
    }

    // ------------------------------------------------- o que não muda

    public function test_os_documentos_continuam_obrigatorios_desde_o_inicio(): void
    {
        $vendor = $this->semFaturacao();

        Document::create(['name' => 'Cartão de Cidadão', 'required' => true]);

        $this->assertFalse($vendor->fresh()->all_documents_verified);
        $this->assertFalse($vendor->fresh()->can_accept_service);
    }

    /**
     * O serviço fechado de quem ainda não tem conta de faturação não rebenta o
     * job: sem conta não há onde emitir a fatura, e o pedido ia para uma conta
     * vazia no InvoiceXpress. Fica por faturar, com o invoice_id a null.
     */
    public function test_a_fatura_de_um_tecnico_sem_conta_fica_por_emitir_sem_rebentar(): void
    {
        $vendor = $this->semFaturacao();
        $service = Service::factory()->create([
            'vendor_id' => $vendor->id,
            'status' => ServiceStatus::CLOSED,
            'payment_status' => PaymentStatus::PAID,
        ]);

        (new CreateInvoiceJob($service))->handle();

        $this->assertNull($service->fresh()->invoice_id);
    }
}
