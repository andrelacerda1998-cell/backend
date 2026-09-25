<?php

namespace Tests\Feature\Endpoints;

use App\Exceptions\Api\Customer\CustomerCantRequestServices;
use App\Enums\Services\AddressType;
use App\Models\Address;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Quando o cliente é impedido de pedir, tem de saber porquê.
 *
 * O `canRequestService()` responde sim ou não. O motivo já estava calculado e
 * traduzido — mas só o backoffice o via, e a app recebia "O cliente não pode
 * solicitar um serviço.", que não diz o que fazer a seguir.
 */
class MotivosDeRecusaDoPedidoTest extends TestCase
{
    use RefreshDatabase;

    public function test_sem_telemovel_verificado_diz_o_que_falta(): void
    {
        $cliente = User::factory()->create(['phone_number_verified_at' => null]);

        $motivos = $cliente->cannotRequestServiceReasonsForApp();

        $this->assertNotEmpty($motivos);
        $this->assertStringContainsString(
            'Confirma o teu número de telemóvel',
            $motivos->implode(' '),
        );
    }

    public function test_os_motivos_sao_ditos_na_segunda_pessoa(): void
    {
        // As frases do backoffice falam DO cliente; as da app falam AO cliente.
        // Se alguém trocar as chaves, isto apanha.
        $cliente = User::factory()->create(['phone_number_verified_at' => null]);

        $this->assertStringContainsString('O cliente', $cliente->cannotRequestServiceReasons()->implode(' '));
        $this->assertStringNotContainsString('O cliente', $cliente->cannotRequestServiceReasonsForApp()->implode(' '));
    }

    public function test_sem_morada_principal_diz_o_que_falta(): void
    {
        $cliente = User::factory()->create(['phone_number_verified_at' => now()]);

        $motivos = $cliente->cannotRequestServiceReasonsForApp();

        $this->assertStringContainsString('morada', $motivos->implode(' '));
    }

    public function test_a_excecao_carrega_os_motivos(): void
    {
        $excecao = CustomerCantRequestServices::comMotivos(collect([
            'Confirma o teu número de telemóvel para pedires um serviço.',
            'Escolhe a morada onde queres o serviço.',
        ]));

        $this->assertStringContainsString('telemóvel', $excecao->getMessage());
        $this->assertStringContainsString('morada', $excecao->getMessage());
        $this->assertSame(403, $excecao->getStatus());
    }

    public function test_sem_motivos_apurados_mantem_a_frase_generica(): void
    {
        // Uma recusa sem explicação nenhuma é pior do que a frase antiga.
        $excecao = CustomerCantRequestServices::comMotivos(collect());

        $this->assertSame(
            'exceptions.services.customer_cannot_request_service',
            $excecao->getMessage(),
        );
    }

    public function test_as_duas_listas_saem_da_mesma_condicao(): void
    {
        // Antes as três condições estavam escritas duas vezes, com um comentário
        // a pedir que ficassem consistentes. Agora há uma só fonte: o número de
        // motivos tem de bater certo entre os dois públicos, aconteça o que
        // acontecer ao utilizador.
        $cliente = User::factory()->create(['phone_number_verified_at' => null]);

        $this->assertCount(
            $cliente->cannotRequestServiceReasons()->count(),
            $cliente->cannotRequestServiceReasonsForApp(),
        );

        $cliente->forceFill(['phone_number_verified_at' => now()])->save();
        Address::create([
            'user_id' => $cliente->id, 'name' => 'Casa', 'street_name' => 'Rua A', 'street_number' => '1',
            'postal_code' => '1000-001', 'city' => 'Lisboa', 'state' => 'Lisboa', 'country' => 'Portugal',
            'main_address' => true, 'address_type' => AddressType::HOUSE_ADDRESS,
        ]);
        $cliente->refresh();

        $this->assertCount(
            $cliente->cannotRequestServiceReasons()->count(),
            $cliente->cannotRequestServiceReasonsForApp(),
        );
    }
}
