<?php

namespace Tests\Feature;

use App\Models\Service;
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

    public function test_it_reports_how_much_discount_a_voucher_has_given(): void
    {
        $voucher = Voucher::create([
            'name' => 'Natal 20',
            'discount_percentage' => 20,
            'valid_services' => ['scheduled'],
            'is_active' => true,
        ]);

        // Dois servicos com este voucher: 12,50 EUR e 7,50 EUR de desconto.
        Service::factory()->create(['voucher_id' => $voucher->id, 'discount_amount' => 1250]);
        Service::factory()->create(['voucher_id' => $voucher->id, 'discount_amount' => 750]);
        // Um servico sem voucher nenhum nao pode entrar na conta.
        Service::factory()->create(['voucher_id' => null, 'discount_amount' => 9999]);

        $this->withAuth()
            ->getJson('/api/v1/admin/vouchers')
            ->assertOk()
            ->assertJsonPath('data.items.0.services_count', 2)
            // EM CENTIMOS: 2000 = 20,00 EUR. Um 20 aqui seria euros e estaria errado.
            ->assertJsonPath('data.items.0.discount_total_cents', 2000);
    }

    public function test_a_voucher_never_used_reports_zero_and_not_null(): void
    {
        Voucher::create([
            'name' => 'Nunca usado',
            'discount_percentage' => 10,
            'valid_services' => ['immediate'],
            'is_active' => true,
        ]);

        $this->withAuth()
            ->getJson('/api/v1/admin/vouchers')
            ->assertOk()
            ->assertJsonPath('data.items.0.services_count', 0)
            ->assertJsonPath('data.items.0.discount_total_cents', 0);
    }

    public function test_the_totals_are_there_when_asking_for_one_voucher(): void
    {
        $voucher = Voucher::create([
            'name' => 'Um so',
            'discount_percentage' => 15,
            'valid_services' => ['scheduled'],
            'is_active' => true,
        ]);
        Service::factory()->create(['voucher_id' => $voucher->id, 'discount_amount' => 500]);

        // O `show` carregava so a contagem de usos; sem os mesmos agregados,
        // abrir um voucher mostrava 0,00 EUR de desconto para um que ja deu.
        $this->withAuth()
            ->getJson("/api/v1/admin/vouchers/{$voucher->id}")
            ->assertOk()
            ->assertJsonPath('data.services_count', 1)
            ->assertJsonPath('data.discount_total_cents', 500);
    }
}
