<?php

namespace Tests\Feature;

use App\Models\GeneralSettings\Document;
use App\Models\GeneralSettings\OperationArea;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use OwenIt\Auditing\Models\Audit;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminAuditsApiTest extends TestCase
{
    // DatabaseTruncation por defeito (ver nota extensa em AdminVendorsApiTest).
    // 'wallets' entra porque criar Users dispara o UserObserver.
    use DatabaseTruncation;

    // 'model_has_roles' também: o TRUNCATE de 'users' recomeça os ids, e um
    // papel de admin que ficou de outro teste passava para o User novo com o
    // mesmo id — um cliente comum a contar como staff.
    protected array $tablesToTruncate = ['audits', 'operation_areas', 'documents', 'users', 'wallets', 'model_has_roles'];

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

    // ------------------------------------------- quem do staff, pelo backoffice

    /** O que o backoffice manda: o token e quem tem a sessão aberta. */
    private function comoStaff(string $email, ?string $nome = null): static
    {
        return $this->withAuth()->withHeaders(array_filter([
            'X-Staff-Email' => $email,
            'X-Staff-Nome' => $nome,
        ], fn ($v) => $v !== null));
    }

    public function test_a_alteracao_feita_pelo_backoffice_grava_quem_a_fez(): void
    {
        $this->comoStaff('  Andre@Piquet.PT ', rawurlencode('André Lacerda'))
            ->postJson('/api/v1/admin/operation-areas', ['name' => 'Jardinagem'])
            ->assertCreated();

        $audit = Audit::query()->where('auditable_type', OperationArea::class)->sole();
        $this->assertSame('created', $audit->event);
        $this->assertNull($audit->user_id);
        $this->assertSame('andre@piquet.pt', $audit->staff_email);
        $this->assertSame('André Lacerda', $audit->staff_nome);

        // E aparece no feed, que antes só mostrava contas de admin do Laravel.
        $this->withAuth()
            ->getJson('/api/v1/admin/audits')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.who', 'André Lacerda')
            ->assertJsonPath('data.items.0.action', 'Criou Categoria')
            ->assertJsonPath('data.items.0.entity', 'Jardinagem')
            ->assertJsonPath('data.items.0.event', 'created')
            ->assertJsonPath('data.items.0.auditable_type', 'operation_area')
            ->assertJsonPath('data.items.0.auditable_id', $audit->auditable_id)
            ->assertJsonPath('data.items.0.staff_email', 'andre@piquet.pt')
            ->assertJsonPath('data.items.0.staff_nome', 'André Lacerda');
    }

    public function test_sem_nome_fica_o_email_e_um_nome_em_latin1_chega_inteiro(): void
    {
        $this->comoStaff('rita@piquet.pt')
            ->postJson('/api/v1/admin/operation-areas', ['name' => 'Pintura'])
            ->assertCreated();
        // O fetch do Node manda um "é" sem codificar como um byte só (Latin-1).
        $this->comoStaff('jose@piquet.pt', "Jos\xE9 Silva")
            ->postJson('/api/v1/admin/operation-areas', ['name' => 'Limpeza'])
            ->assertCreated();

        $porEmail = Audit::query()->pluck('staff_nome', 'staff_email')->all();
        $this->assertSame(['rita@piquet.pt' => null, 'jose@piquet.pt' => 'José Silva'], $porEmail);

        $this->withAuth()->getJson('/api/v1/admin/audits')
            ->assertJsonPath('data.items.0.who', 'José Silva')
            ->assertJsonPath('data.items.1.who', 'rita@piquet.pt');
    }

    public function test_um_email_invalido_nao_grava_nada_e_um_nome_comprido_e_cortado(): void
    {
        $this->comoStaff('isto-nao-e-um-email', 'Alguém')
            ->postJson('/api/v1/admin/operation-areas', ['name' => 'Mudanças'])
            ->assertCreated();
        $this->comoStaff('ana@piquet.pt', str_repeat('a', 300))
            ->postJson('/api/v1/admin/operation-areas', ['name' => 'Estores'])
            ->assertCreated();

        $audits = Audit::query()->orderBy('id')->get();
        $this->assertNull($audits[0]->staff_email);
        $this->assertNull($audits[0]->staff_nome);
        $this->assertSame(190, mb_strlen($audits[1]->staff_nome));
    }

    public function test_sem_token_valido_os_cabecalhos_nao_valem_nada(): void
    {
        config(['services.admin_api.token' => 'a-valid-token']);

        $this->withHeaders([
            'Authorization' => 'Bearer token-errado',
            'X-Staff-Email' => 'intruso@exemplo.pt',
            'X-Staff-Nome' => 'Intruso',
        ])->postJson('/api/v1/admin/operation-areas', ['name' => 'Não passa'])->assertUnauthorized();

        $this->assertSame(0, Audit::query()->count());

        // Os cabeçalhos estão no pedido, mas o token não chegou a ser aceite:
        // uma alteração feita fora da API de admin não fica com o nome dele.
        $area = new OperationArea;
        $area->setTranslations('name', ['en' => 'Locksmith', 'pt-pt' => 'Serralharia']);
        $area->save();

        $audit = Audit::query()->sole();
        $this->assertNull($audit->staff_email);
        $this->assertNull($audit->staff_nome);
    }

    // --------------------------------------------------------------- filtros

    /** Uma linha de auditoria direta na tabela, como as que já lá estão. */
    private function auditoria(array $dados): int
    {
        return DB::table('audits')->insertGetId([
            'user_type' => null,
            'user_id' => null,
            'event' => 'updated',
            'auditable_type' => Vendor::class,
            'auditable_id' => 5,
            'old_values' => json_encode(['status' => 'Offline']),
            'new_values' => json_encode(['status' => 'Online']),
            'created_at' => now(),
            'updated_at' => now(),
            ...$dados,
        ]);
    }

    public function test_os_filtros_por_pessoa_tipo_registo_e_evento(): void
    {
        $andreNoFilament = $this->makeAdmin('André', 'Lacerda');
        $andreNoFilament->forceFill(['email' => 'andre@piquet.pt'])->save();
        $outroAdmin = $this->makeAdmin('Rodrigo', 'Pacheco');
        DB::table('audits')->delete(); // os Users acabados de criar também foram auditados

        $doBackoffice = $this->auditoria(['staff_email' => 'andre@piquet.pt', 'staff_nome' => 'André Lacerda']);
        $doFilament = $this->auditoria(['user_type' => User::class, 'user_id' => $andreNoFilament->id, 'auditable_id' => 6]);
        $doOutro = $this->auditoria(['user_type' => User::class, 'user_id' => $outroAdmin->id, 'event' => 'deleted']);
        $deServico = $this->auditoria(['staff_email' => 'rita@piquet.pt', 'auditable_type' => Service::class, 'auditable_id' => 5]);
        // De um cliente na app: nunca aparece, nem filtrado.
        $this->auditoria(['user_type' => User::class, 'user_id' => 999]);

        $ids = fn (string $q) => array_column($this->withAuth()->getJson('/api/v1/admin/audits'.$q)->assertOk()->json('data.items'), 'id');

        $this->assertEqualsCanonicalizing([$doBackoffice, $doFilament, $doOutro, $deServico], $ids(''));
        // A mesma pessoa pelos dois caminhos: o backoffice novo e o Filament.
        $this->assertEqualsCanonicalizing([$doBackoffice, $doFilament], $ids('?staff=Andre@Piquet.pt'));
        $this->assertEqualsCanonicalizing([$doBackoffice, $doFilament, $doOutro], $ids('?auditable_type=vendor'));
        $this->assertSame([$deServico], $ids('?auditable_type=service&auditable_id=5'));
        $this->assertEqualsCanonicalizing([$doBackoffice, $doOutro], $ids('?auditable_type=vendor&auditable_id=5'));
        $this->assertSame([$doOutro], $ids('?event=deleted'));
        $this->assertSame([], $ids('?auditable_type=payment_order'));

        $this->withAuth()->getJson('/api/v1/admin/audits?auditable_type=coisa')->assertStatus(422)->assertJsonValidationErrors('auditable_type');
        $this->withAuth()->getJson('/api/v1/admin/audits?event=apagou')->assertStatus(422)->assertJsonValidationErrors('event');
    }

    public function test_as_auditorias_antigas_continuam_no_feed_sem_staff(): void
    {
        $admin = $this->makeAdmin('Ana', 'Costa');
        $this->actingAs($admin);

        $area = new OperationArea;
        $area->setTranslations('name', ['en' => 'Plumbing', 'pt-pt' => 'Canalização']);
        $area->save();

        $this->withAuth()
            ->getJson('/api/v1/admin/audits?auditable_type=operation_area')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.who', 'Ana Costa')
            ->assertJsonPath('data.items.0.staff_email', null)
            ->assertJsonPath('data.items.0.staff_nome', null)
            ->assertJsonPath('data.meta.total', 1);
    }
}
