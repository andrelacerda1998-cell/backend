<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSystemProfitApiTest extends TestCase
{
    use RefreshDatabase;

    private function withAuth(): static
    {
        config(['services.admin_api.token' => 'a-valid-token']);

        return $this->withHeaders(['Authorization' => 'Bearer a-valid-token']);
    }

    public function test_it_returns_the_wallet_balance_and_transactions(): void
    {
        $admin = User::factory()->create();

        system_wallet()->deposit(1500, [
            'type' => 'system_fee',
            'admin_description' => 'system_profit.deposits.service_fee',
            'admin_id' => $admin->id,
        ]);

        $response = $this->withAuth()
            ->getJson('/api/v1/admin/system-profit')
            ->assertOk();

        // Não usar $admin->name -- User::setNameAttribute() nunca grava essa coluna
        // (só first_name/last_name), fica sempre null. Ver nota no controller.
        $expectedName = trim($admin->first_name.' '.$admin->last_name);
        $response->assertJsonPath('data.items.0.admin_name', $expectedName);
        $response->assertJsonPath('data.items.0.type', 'system_fee');
        $this->assertGreaterThan(0, $response->json('data.wallet_balance'));
    }

    public function test_it_filters_transactions_by_date_range(): void
    {
        system_wallet()->deposit(500, ['type' => 'system_fee']);

        $this->withAuth()
            ->getJson('/api/v1/admin/system-profit?from=2999-01-01')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 0);
    }
}
