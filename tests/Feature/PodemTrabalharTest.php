<?php

namespace Tests\Feature;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\GeneralSettings\Document;
use App\Models\GeneralSettings\OperationArea;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Vendor\VendorDocuments;
use App\Services\Operacoes\PodemTrabalhar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * "Podem trabalhar" é o `can_accept_service`, técnico a técnico. O
 * PodemTrabalhar filtra antes em SQL (contactos verificados, documentos em
 * dia) para não avaliar o atributo nos 460 técnicos -- e este teste existe
 * para esse filtro nunca esconder ninguém: em cada caso, a resposta tem de
 * ser a do atributo.
 */
class PodemTrabalharTest extends TestCase
{
    use RefreshDatabase;

    private Document $cartaoDeCidadao;

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null']);

        // Os documentos obrigatórios são os DESTE teste (dentro da transação).
        DB::table('documents')->delete();
        $this->cartaoDeCidadao = Document::create(['name' => 'Cartão de Cidadão', 'required' => true]);
    }

    private function tecnico(array $pessoa = [], array $dados = []): Vendor
    {
        return Vendor::factory()->create(array_merge(['user_id' => User::factory()->create($pessoa)->id], $dados));
    }

    private function entregar(Vendor $tecnico, Document $documento, string $estado = 'approved', ?string $validade = null): void
    {
        VendorDocuments::create(['vendor_id' => $tecnico->id, 'document_id' => $documento->id, 'status' => $estado, 'expiration_date' => $validade]);
    }

    private function servico(Vendor $tecnico, ServiceStatus $estado): void
    {
        DB::table('services')->insert([
            'vendor_id' => $tecnico->id,
            'status' => $estado->value,
            'payment_status' => PaymentStatus::PAID->value,
            'is_test' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_diz_o_mesmo_que_o_atributo_caso_a_caso(): void
    {
        $certificado = Document::create(['name' => 'Certificado DGEG', 'required' => false]);
        $luz = OperationArea::factory()->create(['documents' => [(string) $certificado->id]]);

        $casos = [];

        $casos['tudo certo'] = $t = $this->tecnico();
        $this->entregar($t, $this->cartaoDeCidadao);

        $casos['telemóvel por confirmar'] = $t = $this->tecnico(['phone_number_verified_at' => null]);
        $this->entregar($t, $this->cartaoDeCidadao);

        $casos['email por confirmar'] = $t = $this->tecnico(['email_verified_at' => null]);
        $this->entregar($t, $this->cartaoDeCidadao);

        $casos['documento por rever'] = $t = $this->tecnico();
        $this->entregar($t, $this->cartaoDeCidadao, 'pending');

        $casos['documento caducado ontem'] = $t = $this->tecnico();
        $this->entregar($t, $this->cartaoDeCidadao, 'approved', now()->subDay()->toDateString());

        $casos['documento a caducar hoje'] = $t = $this->tecnico();
        $this->entregar($t, $this->cartaoDeCidadao, 'approved', now()->toDateString());

        $casos['sem a certificação da categoria'] = $t = $this->tecnico();
        $this->entregar($t, $this->cartaoDeCidadao);
        $t->operationAreas()->attach($luz->id);

        $casos['com a certificação da categoria'] = $t = $this->tecnico();
        $this->entregar($t, $this->cartaoDeCidadao);
        $this->entregar($t, $certificado);
        $t->operationAreas()->attach($luz->id);

        $casos['com um imediato aberto'] = $t = $this->tecnico();
        $this->entregar($t, $this->cartaoDeCidadao);
        $this->servico($t, ServiceStatus::ACCEPTED);

        $casos['três feitos, sem a AT'] = $t = $this->tecnico();
        $this->entregar($t, $this->cartaoDeCidadao);
        foreach (range(1, 3) as $i) {
            $this->servico($t, ServiceStatus::CLOSED);
        }

        $casos['três feitos, com a AT, IBAN e conta'] = $t = $this->tecnico([], ['iban' => 'PT50000201231234567890154', 'invoice_workspace' => 'ws-teste']);
        $t->forceFill(['at_valid' => true, 'at_user' => '123456789/1'])->save();
        $this->entregar($t, $this->cartaoDeCidadao);
        foreach (range(1, 3) as $i) {
            $this->servico($t, ServiceStatus::CLOSED);
        }

        $podem = PodemTrabalhar::ids()->all();

        foreach ($casos as $caso => $tecnico) {
            $doAtributo = (bool) Vendor::query()->findOrFail($tecnico->id)->can_accept_service;

            $this->assertSame($doAtributo, in_array($tecnico->id, $podem, true), "Caso «{$caso}»: o PodemTrabalhar e o atributo não dizem o mesmo.");
        }

        // E o que o atributo diz, para o caso de ambos mudarem juntos por engano.
        $esperados = ['tudo certo', 'documento a caducar hoje', 'com a certificação da categoria', 'três feitos, com a AT, IBAN e conta'];
        $this->assertEqualsCanonicalizing(
            array_map(fn (string $caso) => $casos[$caso]->id, $esperados),
            array_values(array_intersect($podem, array_map(fn (Vendor $v) => $v->id, $casos))),
        );
    }

    public function test_contas_de_teste_ficam_de_fora(): void
    {
        $t = $this->tecnico(['is_test' => true]);
        $this->entregar($t, $this->cartaoDeCidadao);

        $this->assertNotContains($t->id, PodemTrabalhar::ids()->all());
    }
}
