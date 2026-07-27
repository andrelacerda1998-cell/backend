<?php

namespace Tests\Feature;

use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminVoucherApiTest extends TestCase
{
    use RefreshDatabase;

    private function withAuth(): static
    {
        config(['services.admin_api.token' => 'a-valid-token']);

        return $this->withHeaders(['Authorization' => 'Bearer a-valid-token']);
    }

    public function test_it_lists_vouchers(): void
    {
        Voucher::create([
            'name' => 'BlackFriday 25',
            'discount_percentage' => 25,
            'valid_services' => ['scheduled', 'immediate'],
            'is_active' => true,
        ]);

        $this->withAuth()
            ->getJson('/api/v1/admin/vouchers')
            ->assertOk()
            ->assertJsonPath('data.items.0.name', 'BlackFriday 25')
            ->assertJsonPath('data.meta.total', 1);
    }

    public function test_it_creates_a_voucher(): void
    {
        $payload = [
            'name' => 'Verão 2026',
            'discount_percentage' => 15,
            'valid_services' => ['immediate'],
            'is_active' => true,
            'max_uses' => 3,
        ];

        $this->withAuth()
            ->postJson('/api/v1/admin/vouchers', $payload)
            ->assertCreated()
            ->assertJsonPath('data.name', 'Verão 2026')
            ->assertJsonPath('data.discount_percentage', 15);

        $this->assertDatabaseHas('vouchers', ['name' => 'Verão 2026', 'max_uses' => 3]);
    }

    public function test_it_rejects_creating_a_voucher_with_invalid_data(): void
    {
        $this->withAuth()
            ->postJson('/api/v1/admin/vouchers', [
                'name' => '',
                'discount_percentage' => 250,
                'valid_services' => ['not-a-real-type'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'discount_percentage', 'valid_services.0']);
    }

    public function test_it_rejects_a_name_longer_than_the_database_column(): void
    {
        // Coluna 'name' é VARCHAR(30) -- ver migration create_vouchers_table.
        $this->withAuth()
            ->postJson('/api/v1/admin/vouchers', [
                'name' => str_repeat('a', 31),
                'discount_percentage' => 10,
                'valid_services' => ['scheduled'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_it_updates_a_voucher(): void
    {
        $voucher = Voucher::create([
            'name' => 'Antigo',
            'discount_percentage' => 10,
            'valid_services' => ['scheduled'],
            'is_active' => true,
        ]);

        $this->withAuth()
            ->putJson("/api/v1/admin/vouchers/{$voucher->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.name', 'Antigo');

        $this->assertDatabaseHas('vouchers', ['id' => $voucher->id, 'is_active' => false]);
    }

    public function test_it_deletes_a_voucher(): void
    {
        $voucher = Voucher::create([
            'name' => 'Para apagar',
            'discount_percentage' => 5,
            'valid_services' => ['scheduled'],
            'is_active' => true,
        ]);

        $this->withAuth()
            ->deleteJson("/api/v1/admin/vouchers/{$voucher->id}")
            ->assertOk();

        $this->assertSoftDeleted('vouchers', ['id' => $voucher->id]);
    }
}
