<?php

namespace Tests\Feature;

use App\Enums\Services\AddressType;
use App\Models\Address;
use App\Models\GeneralSettings\ServicesType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O cliente não abre um pedido sem o telemóvel verificado.
 *
 * É por esse número que o profissional o contacta quando chega à porta e
 * ninguém atende. Um número inventado significa uma deslocação perdida e uma
 * discussão sobre quem a paga — e a política de cancelamento tardio, que cobra
 * 10% ao cliente, assume que há forma de falar com ele.
 */
class CustomerPhoneVerificationGateTest extends TestCase
{
    use RefreshDatabase;

    private function customer(bool $phoneVerified, bool $withAddress = true): User
    {
        $user = User::factory()->create([
            'phone_number_verified_at' => $phoneVerified ? now() : null,
        ]);

        if ($withAddress) {
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
        }

        return $user->fresh();
    }

    public function test_unverified_phone_blocks_the_request(): void
    {
        $this->assertFalse($this->customer(phoneVerified: false)->can_request_service);
    }

    public function test_verified_phone_with_address_can_request(): void
    {
        $this->assertTrue($this->customer(phoneVerified: true)->can_request_service);
    }

    public function test_verifying_the_phone_is_not_enough_without_an_address(): void
    {
        // As duas condições continuam a valer: isto não substituiu nenhuma.
        $this->assertFalse($this->customer(phoneVerified: true, withAddress: false)->can_request_service);
    }

    public function test_the_reason_says_what_is_missing(): void
    {
        // Sem isto o backoffice não conseguiria explicar ao cliente porque é
        // que ele está travado.
        $reasons = $this->customer(phoneVerified: false)->cannotRequestServiceReasons();

        $this->assertCount(1, $reasons);
        $this->assertSame(__('backoffice/customer.infolist.eligibility.unverified_phone'), $reasons->first());
    }

    public function test_the_customer_gets_a_message_that_says_what_to_do(): void
    {
        // A genérica ("O cliente não pode solicitar um serviço") é escrita na
        // terceira pessoa, para o backoffice, e deixa quem está a pagar sem
        // saber o que falta. Quem é travado pelo telemóvel recebe um passo.
        $customer = $this->customer(phoneVerified: false);
        // Um tipo real, senão a validação do pedido rejeita com 422 e o teste
        // nunca chega ao gate que quer exercitar.
        $type = ServicesType::factory()->create();

        $response = $this->actingAs($customer, 'api')
            ->postJson('/api/v1/customer/services/matching', ['service_type' => $type->id])
            ->assertStatus(403);

        // Comparação pela chave e não pelo texto: fixar a frase faria o teste
        // partir-se com uma revisão de copy ou uma mudança de língua do ambiente.
        $this->assertSame(__('exceptions.services.verify_phone_to_request'), $response->json('message'));
        $this->assertNotSame(
            __('exceptions.services.customer_cannot_request_service'),
            $response->json('message'),
            'quem é travado pelo telemóvel não pode receber a mensagem genérica'
        );
    }

    public function test_reasons_stay_consistent_with_the_gate(): void
    {
        // O contrato que o comentário do método promete: se pode pedir, não há
        // razões; se não pode, há pelo menos uma.
        $ok = $this->customer(phoneVerified: true);
        $this->assertTrue($ok->can_request_service);
        $this->assertCount(0, $ok->cannotRequestServiceReasons());

        $blocked = $this->customer(phoneVerified: false, withAddress: false);
        $this->assertFalse($blocked->can_request_service);
        $this->assertCount(2, $blocked->cannotRequestServiceReasons());
    }
}
