<?php

namespace Tests\Feature;

use App\Models\GeneralSettings\Document;
use App\Models\GeneralSettings\OperationArea;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminAuditsApiTest extends TestCase
{
    // DatabaseTruncation por defeito (ver nota extensa em AdminVendorsApiTest).
    // 'wallets' entra porque criar Users dispara o UserObserver.
    use DatabaseTruncation;

    protected array $tablesToTruncate = ['audits', 'operation_areas', 'documents', 'users', 'wallets'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null']);
    }

    private function withAuth(): static
    {
        config(['services.admin_api.token' => 'a-valid-token']);

        return $this->withHeaders(['Authorization' => 'Bearer a-valid-token']);
    }

    private function makeAdmin(string $first, string $last): User
    {
        Role::firstOrCreate(['name' => 'admin']);
        $admin = User::factory()->create(['first_name' => $first, 'last_name' => $last]);
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_it_lists_only_activity_from_staff_users(): void
    {
        $admin = $this->makeAdmin('Rodrigo', 'Pacheco');
        $this->actingAs($admin);
        $area = new OperationArea();
        $area->setTranslations('name', ['en' => 'Plumbing', 'pt-pt' => 'Canalização']);
        $area->save();

        // Ação de um utilizador comum (sem role) -- não deve aparecer no feed.
        $customer = User::factory()->create(['first_name' => 'Cliente', 'last_name' => 'Comum']);
        $this->actingAs($customer);
        $area2 = new OperationArea();
        $area2->setTranslations('name', ['en' => 'Electrician', 'pt-pt' => 'Eletricista']);
        $area2->save();

        $this->withAuth()
            ->getJson('/api/v1/admin/audits')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.who', 'Rodrigo Pacheco')
            ->assertJsonPath('data.items.0.action', 'Criou Categoria')
            ->assertJsonPath('data.items.0.entity', 'Canalização');
    }

    public function test_it_summarizes_a_single_field_change(): void
    {
        $admin = $this->makeAdmin('Ana', 'Costa');
        $this->actingAs($admin);

        $document = new Document();
        $document->required = false;
        $document->setTranslations('name', ['en' => 'ID Card', 'pt-pt' => 'Cartão de Cidadão']);
        $document->save();

        // 'name' é JSON (traduzível); testamos o diff num campo simples
        // (booleano) para não depender do suporte a valores array do pacote.
        $document->required = true;
        $document->save();

        $this->withAuth()
            ->getJson('/api/v1/admin/audits')
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.action', 'Atualizou Documento')
            ->assertJsonPath('data.items.0.old_value', 'não')
            ->assertJsonPath('data.items.0.new_value', 'sim')
            ->assertJsonPath('data.items.1.action', 'Criou Documento');
    }
}
