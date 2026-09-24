<?php

namespace Tests\Feature\Api\Vendor;

use App\Models\GeneralSettings\Document;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O que falta ao técnico para poder trabalhar, dito pela ordem certa.
 *
 * As únicas duas mensagens de suporte que a Piquet recebeu de técnicos (10/09
 * e 18/09) eram a mesma pergunta: "em que ponto está o meu processo?". A
 * resposta já estava na base de dados e não chegava a quem precisava dela --
 * uma delas ficou seis dias sem resposta.
 *
 * A ORDEM é o que este teste protege. Não vale a pena pedir o IBAN a quem
 * ainda nem confirmou o telemóvel: quem receber "falta o IBAN" antes disso vai
 * preenchê-lo e continuar bloqueado, sem perceber porquê.
 */
class EstadoDaContaTest extends TestCase
{
    /*
      RefreshDatabase e não DatabaseTruncation.

      Truncar CONFIRMA o que o teste escreve, e o que fica confirmado atravessa
      a fronteira da classe. Foi o que aconteceu: o AvaliacoesVoltamNoDeployTest
      corre em transação, afirma `Vendor::count() === 0`, e encontrou o técnico
      que este ficheiro tinha deixado para trás. A CI ficou vermelha e o deploy
      parou.

      Com transação, o que este teste escreve desaparece no fim de cada caso.
    */
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null']);
    }

    private function makeVendor(array $userAttrs = []): Vendor
    {
        $user = User::factory()->create($userAttrs);
        $vendor = new Vendor();
        $vendor->user_id = $user->id;
        $vendor->username = 'tec_'.$user->id;
        $vendor->save();

        return $vendor->fresh('user');
    }

    public function test_contacto_por_verificar_vem_primeiro(): void
    {
        $vendor = $this->makeVendor(['email_verified_at' => null, 'phone_number_verified_at' => null]);

        $this->assertSame('contact_unverified', $vendor->invoicingBlocker());
    }

    /**
     * Verificado o contacto, o que falta a seguir são os documentos -- e NÃO o
     * IBAN, mesmo estando ele também por preencher.
     *
     * O documento obrigatório tem de ser criado no teste: sem nenhum definido,
     * `all_documents_verified` é verdadeiro POR VAZIO e a regra saltava para o
     * IBAN. Em produção há três obrigatórios, por isso o cenário sem eles não
     * é o real -- foi este teste que mo mostrou.
     */
    public function test_com_contacto_verificado_pede_os_documentos_e_nao_o_iban(): void
    {
        $doc = new Document();
        $doc->setTranslations('name', ['en' => 'Citizen card', 'pt-pt' => 'Cartão de Cidadão']);
        $doc->required = true;
        $doc->save();

        $vendor = $this->makeVendor(['email_verified_at' => now()]);

        $this->assertSame('documents_pending', $vendor->fresh('user')->invoicingBlocker());
    }

    /** Com tudo tratado, não há bloqueio nenhum -- e a app pode dizê-lo. */
    public function test_sem_nada_em_falta_nao_ha_bloqueio(): void
    {
        $vendor = $this->makeVendor(['email_verified_at' => now()]);
        $vendor->iban = 'PT50000201231234567890154';
        $vendor->save();
        // `addresses` liga ao UTILIZADOR, não ao vendor -- a relação do vendor
        // passa por ele. Sem user_id o insert falha por NOT NULL.
        \App\Models\Address::create([
            'user_id' => $vendor->user_id,
            'name' => 'Rua de Teste 1, Lisboa',
            'address_type' => \App\Enums\Services\AddressType::FISCAL_ADDRESS,
            'street_name' => 'Rua de Teste',
            'street_number' => '1',
            'postal_code' => '1000-001',
            'city' => 'Lisboa',
            // NOT NULL sem default em `addresses`: user_id, postal_code, state
            // e country. Confirmado no information_schema depois de três
            // tentativas às cegas.
            'state' => 'Lisboa',
            'country' => 'PT',
        ]);

        $this->assertNull($vendor->fresh('user')->invoicingBlocker());
    }
}
