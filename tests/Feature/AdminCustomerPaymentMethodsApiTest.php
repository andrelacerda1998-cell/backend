<?php

namespace Tests\Feature;

use App\Models\GeneralSettings\Gender;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use RwInteractive\PayshopSdk\Enums\PaymentMethods\PaymentMethodType;
use Tests\TestCase;

/**
 * Métodos de pagamento guardados — equivalente ao Filament
 * PaymentMethodsRelationManager (dentro do CustomerResource).
 *
 * Ficheiro à parte de AdminCustomersApiTest (que usa RefreshDatabase, uma
 * exceção pré-existente à convenção do projeto): 'payshop_payment_methods'
 * tem FK para 'users', e já vimos duas vezes nesta suite (ChargeServiceExtraTest/
 * ServiceExtrasFlowTest, ver commits anteriores) o que acontece quando essa
 * tabela não é truncada em conjunto com 'users' -- uma linha órfã bloqueia
 * um forceDelete/soft-delete do próximo User que reaproveita o mesmo id.
 * DatabaseTruncation com a lista certa evita repetir esse problema aqui.
 */
class AdminCustomerPaymentMethodsApiTest extends TestCase
{
    use DatabaseTruncation;

    protected array $tablesToTruncate = ['users', 'wallets', 'payshop_payment_methods'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null']);
        Gender::firstOrCreate(['name' => 'Masculino']);
    }

    private function withAuth(): static
    {
        config(['services.admin_api.token' => 'a-valid-token']);

        return $this->withHeaders(['Authorization' => 'Bearer a-valid-token']);
    }

    public function test_it_lists_payment_methods_for_a_customer(): void
    {
        $customer = User::factory()->create();
        $card = $customer->paymentMethods()->create([
            'type' => 'card',
            'brand' => 'visa',
            'brand_description' => 'Visa Débito',
            'last4' => '4242',
            'holder' => 'Ana Silva',
            'expire_month' => '05',
            'expire_year' => '28',
        ]);
        $mbway = $customer->paymentMethods()->create([
            // Valor da própria enum, não uma string adivinhada -- 'type' é
            // um enum cast (RwInteractive\PayshopSdk\Enums\PaymentMethods\
            // PaymentMethodType), e um valor inválido rebenta na hidratação.
            'type' => PaymentMethodType::MBWAY->value,
            'phone_number' => '910000000',
        ]);

        $this->withAuth()
            ->getJson("/api/v1/admin/customers/{$customer->id}/payment-methods")
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.id', $mbway->id) // mais recente primeiro
            ->assertJsonPath('data.items.0.type', PaymentMethodType::MBWAY->value)
            ->assertJsonPath('data.items.0.phone_number', '910000000')
            ->assertJsonPath('data.items.1.id', $card->id)
            ->assertJsonPath('data.items.1.brand', 'visa')
            ->assertJsonPath('data.items.1.last4', '4242')
            ->assertJsonPath('data.items.1.holder', 'Ana Silva')
            ->assertJsonPath('data.items.1.expire_month', '05')
            ->assertJsonPath('data.items.1.expire_year', '28');
    }

    public function test_it_returns_an_empty_list_for_a_customer_without_saved_methods(): void
    {
        $customer = User::factory()->create();

        $this->withAuth()
            ->getJson("/api/v1/admin/customers/{$customer->id}/payment-methods")
            ->assertOk()
            ->assertJsonCount(0, 'data.items');
    }

    public function test_it_returns_404_for_an_unknown_customer(): void
    {
        $this->withAuth()
            ->getJson('/api/v1/admin/customers/999999/payment-methods')
            ->assertStatus(404);
    }

    public function test_it_deletes_a_payment_method(): void
    {
        $customer = User::factory()->create();
        $card = $customer->paymentMethods()->create(['type' => 'card', 'last4' => '4242']);

        $this->withAuth()
            ->deleteJson("/api/v1/admin/customers/{$customer->id}/payment-methods/{$card->id}")
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->assertSoftDeleted('payshop_payment_methods', ['id' => $card->id]);
    }

    public function test_it_returns_404_when_deleting_an_unknown_payment_method(): void
    {
        $customer = User::factory()->create();

        $this->withAuth()
            ->deleteJson("/api/v1/admin/customers/{$customer->id}/payment-methods/999999")
            ->assertStatus(404);
    }

    public function test_it_does_not_delete_another_customers_payment_method(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $card = $owner->paymentMethods()->create(['type' => 'card', 'last4' => '4242']);

        $this->withAuth()
            ->deleteJson("/api/v1/admin/customers/{$other->id}/payment-methods/{$card->id}")
            ->assertStatus(404);

        $this->assertDatabaseHas('payshop_payment_methods', ['id' => $card->id, 'deleted_at' => null]);
    }
}
