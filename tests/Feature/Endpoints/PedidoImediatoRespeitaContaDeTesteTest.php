<?php

namespace Tests\Feature\Endpoints;

use App\DTO\Services\AddressCoordinatesDTO;
use App\Enums\Services\AddressType;
use App\Http\Controllers\Api\Customer\Services\RequestServiceController;
use App\Models\Address;
use App\Http\Requests\Api\Customer\Services\RequestServiceRequest;
use App\Models\GeneralSettings\ServicesType;
use App\Models\User;
use App\Services\Customer\Services\VendorSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contas de teste procuram contas de teste, também no pedido imediato.
 *
 * O agendamento já passava a flag do cliente à procura; o imediato passava
 * `false` fixo. A diferença não dava erro nenhum — dava duas coisas erradas em
 * silêncio:
 *
 *  - os técnicos marcados como teste nunca apareciam no imediato, a ninguém, o
 *    que torna inútil criá-los para testar precisamente este fluxo;
 *  - um cliente de teste via os técnicos REAIS e, se o pedido avançasse, o
 *    trabalho caía a um profissional a sério.
 *
 * Este teste não passa pelo Meilisearch de propósito: o que está em causa é o
 * argumento que o controlador entrega à procura, e é isso que se prende aqui.
 */
class PedidoImediatoRespeitaContaDeTesteTest extends TestCase
{
    use RefreshDatabase;

    private function cliente(bool $deTeste): User
    {
        $user = User::factory()->create([
            'phone_number_verified_at' => now(),
        ]);
        $user->is_test = $deTeste;
        $user->save();

        Address::create([
            'user_id' => $user->id,
            'name' => 'Casa',
            'street_name' => 'Rua de Exemplo',
            'street_number' => '1',
            'postal_code' => '4000-000',
            'city' => 'Porto',
            'municipality' => 'Porto',
            'state' => 'Porto',
            'country' => 'Portugal',
            'latitude' => 41.1478,
            'longitude' => -8.6110,
            'main_address' => true,
            'address_type' => AddressType::HOUSE_ADDRESS,
        ]);

        return $user->fresh();
    }

    /** Procura que não procura: só regista a flag com que foi chamada. */
    private function espiaDaProcura(): VendorSearchService
    {
        return new class extends VendorSearchService
        {
            public ?bool $flagRecebida = null;

            public function search(AddressCoordinatesDTO $address, ServicesType $servicesType, bool $isTestCustomer = false)
            {
                $this->flagRecebida = $isTestCustomer;

                return collect();
            }
        };
    }

    private function flagEntregueAProcura(User $cliente): ?bool
    {
        $tipo = ServicesType::factory()->create();
        $espia = $this->espiaDaProcura();

        $this->actingAs($cliente);

        $request = RequestServiceRequest::create('/', 'POST', ['service_type' => $tipo->id]);
        $request->setContainer(app());
        $request->setUserResolver(fn () => $cliente);

        (new RequestServiceController)($request, $espia);

        return $espia->flagRecebida;
    }

    public function test_cliente_de_teste_procura_tecnicos_de_teste(): void
    {
        $this->assertTrue(
            $this->flagEntregueAProcura($this->cliente(deTeste: true)),
            'Um cliente marcado como teste tem de ver os técnicos de teste — e só esses.',
        );
    }

    public function test_cliente_normal_procura_tecnicos_normais(): void
    {
        $this->assertFalse(
            $this->flagEntregueAProcura($this->cliente(deTeste: false)),
            'Um cliente a sério não pode passar a ver contas de teste.',
        );
    }
}
