<?php

namespace Tests\Feature\Api\Vendor;

use App\Models\GeneralSettings\Document;
use App\Models\Vendor;
use App\Models\Vendor\VendorDocuments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Aviso previo de documento a expirar.
 *
 * A app tem o banner desde sempre, mas lia `expiring_documents`, um campo que
 * o servidor nunca enviava: o tecnico so dava pelo problema quando deixava de
 * receber trabalho. Este teste existe para o campo nao voltar a desaparecer
 * sem que alguem repare.
 */
class DocumentosAExpirarTest extends TestCase
{
    use RefreshDatabase;

    private function documentoComValidade(Vendor $vendor, string $nome, ?string $validade, string $estado = 'approved'): void
    {
        $tipo = Document::create(['name' => $nome, 'description' => $nome, 'required' => true]);

        VendorDocuments::create([
            'vendor_id' => $vendor->id,
            'document_id' => $tipo->id,
            'status' => $estado,
            'expiration_date' => $validade,
        ]);
    }

    public function test_documento_a_expirar_dentro_da_janela_aparece_com_os_dias_que_faltam(): void
    {
        $vendor = Vendor::factory()->create();
        $this->documentoComValidade($vendor, 'Registo Criminal', now()->addDays(15)->toDateString());

        $avisos = $vendor->expiring_documents;

        $this->assertCount(1, $avisos);
        $this->assertSame('Registo Criminal', $avisos->first()['name']);
        $this->assertSame(15, $avisos->first()['days_to_expire']);
        $this->assertFalse($avisos->first()['is_expired']);
    }

    public function test_documento_com_validade_para_la_da_janela_nao_incomoda_ninguem(): void
    {
        $vendor = Vendor::factory()->create();
        $this->documentoComValidade(
            $vendor,
            'Cartao de Cidadao',
            now()->addDays(Vendor::DOCUMENT_EXPIRY_WARNING_DAYS + 1)->toDateString(),
        );

        $this->assertCount(0, $vendor->expiring_documents);
    }

    public function test_ultimo_dia_de_validade_ainda_nao_conta_como_expirado(): void
    {
        // Espelho de allDocumentsVerified(): aceita-se ate ao fim do dia.
        $vendor = Vendor::factory()->create();
        $this->documentoComValidade($vendor, 'Declaracao de Inicio de Atividade', now()->toDateString());

        $aviso = $vendor->expiring_documents->first();

        $this->assertSame(0, $aviso['days_to_expire']);
        $this->assertFalse($aviso['is_expired']);
    }

    public function test_documento_ja_fora_de_validade_vem_marcado_como_expirado(): void
    {
        $vendor = Vendor::factory()->create();
        $this->documentoComValidade($vendor, 'Registo Criminal', now()->subDays(3)->toDateString());

        $aviso = $vendor->expiring_documents->first();

        $this->assertSame(-3, $aviso['days_to_expire']);
        $this->assertTrue($aviso['is_expired']);
    }

    public function test_documento_por_aprovar_nao_gera_aviso_de_validade(): void
    {
        // Ainda nao vale nada; o aviso a dar e o de "em validacao", nao este.
        $vendor = Vendor::factory()->create();
        $this->documentoComValidade($vendor, 'Registo Criminal', now()->addDays(5)->toDateString(), 'pending');

        $this->assertCount(0, $vendor->expiring_documents);
    }

    public function test_documento_sem_data_de_validade_nunca_expira(): void
    {
        $vendor = Vendor::factory()->create();
        $this->documentoComValidade($vendor, 'Cartao de Cidadao', null);

        $this->assertCount(0, $vendor->expiring_documents);
    }

    public function test_o_mais_urgente_vem_primeiro(): void
    {
        $vendor = Vendor::factory()->create();
        $this->documentoComValidade($vendor, 'Cartao de Cidadao', now()->addDays(20)->toDateString());
        $this->documentoComValidade($vendor, 'Registo Criminal', now()->addDays(2)->toDateString());

        $this->assertSame('Registo Criminal', $vendor->expiring_documents->first()['name']);
    }
}
