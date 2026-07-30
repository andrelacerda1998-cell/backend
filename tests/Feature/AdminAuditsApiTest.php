<?php

namespace Tests\Feature;

use App\Models\GeneralSettings\Document;
use App\Models\GeneralSettings\OperationArea;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
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

        // owen-it/laravel-auditing só audita em consola (ex: seeders, testes
        // PHPUnit) se 'audit.console' for true -- por omissão é false
        // (Auditable::isAuditingEnabled(), vendor/owen-it/laravel-auditing/
        // src/Auditable.php:554). Sem isto, os saves feitos abaixo não geram
        // nenhuma linha em 'audits' e os testes ficam sempre a ver 0 registos.
        // Não se muda o valor por omissão do pacote/app: em produção as ações
        // reais (Filament, API admin) chegam sempre por HTTP, nunca por
        // consola, por isso este ajuste só precisa de existir aqui nos testes.
        config(['audit.console' => true]);
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
        // Criado ANTES de vestir a identidade do admin: com a auditoria já
        // ligada em consola, User também é Auditable -- se este customer
        // fosse criado depois do actingAs($admin), o "Criou Utilizador"
        // ficava (erradamente) atribuído ao admin só por ele ainda estar
        // "autenticado" no teste, poluindo o feed que queremos testar.
        $customer = User::factory()->create(['first_name' => 'Cliente', 'last_name' => 'Comum']);

        $this->actingAs($admin);
        $area = new OperationArea();
        $area->setTranslations('name', ['en' => 'Plumbing', 'pt-pt' => 'Canalização']);
        $area->save();

        // Ação de um utilizador comum (sem role) -- não deve aparecer no feed.
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

    /**
     * A tabela 'audits' em produção tem ~2 anos de histórico; modelos como os
     * de GeneralSettings já mudaram de namespace no passado. Uma linha antiga
     * a apontar para uma classe que já não existe (auditable_type) não pode
     * deitar todo o feed abaixo -- reproduz isso diretamente na BD (não dá
     * para gerar pelo Eloquent, a classe tem mesmo de não existir).
     */
    public function test_it_stays_resilient_to_a_row_with_a_stale_auditable_class(): void
    {
        $admin = $this->makeAdmin('Rita', 'Nunes');

        DB::table('audits')->insert([
            'user_type' => User::class,
            'user_id' => $admin->id,
            'event' => 'created',
            'auditable_type' => 'App\\Models\\GeneralSettings\\JaNaoExiste',
            'auditable_id' => 999999,
            'old_values' => null,
            'new_values' => json_encode(['name' => 'Antigo']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withAuth()
            ->getJson('/api/v1/admin/audits')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.entity', '#999999');
    }
}
