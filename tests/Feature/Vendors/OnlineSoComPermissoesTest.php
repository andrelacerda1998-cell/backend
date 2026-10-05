<?php

namespace Tests\Feature\Vendors;

use App\Enums\Vendors\StatusVendor;
use App\Models\GeneralSettings\Gender;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * SEM LOCALIZAÇÃO OU NOTIFICAÇÕES NÃO SE VAI ONLINE.
 *
 * O servidor não vê as permissões do telemóvel; a app manda-as no pedido. A app
 * já recusa antes de chegar aqui -- isto é a segunda linha. Um técnico online
 * sem notificações não é avisado de nenhum pedido, e cada um dura 120 segundos.
 */
class OnlineSoComPermissoesTest extends TestCase
{
    use DatabaseTruncation;

    protected array $tablesToTruncate = ['users', 'wallets', 'vendors', 'services', 'vendors_location'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null']);
        config(['app.locale' => 'pt-pt']);
        Gender::firstOrCreate(['name' => 'Masculino']);
        Notification::fake();
    }

    /** Passa em todas as outras portas de ir online (menos de 3 serviços: AT ainda não exigida). */
    private function tecnicoOffline(): Vendor
    {
        $user = User::factory()->create([
            'is_test' => true,
            'email_verified_at' => now(),
            'phone_number_verified_at' => now(),
        ]);

        return Vendor::factory()->create([
            'user_id' => $user->id,
            'status' => StatusVendor::OFFLINE,
            'iban' => 'PT50000000000000000000000',
            'invoice_workspace' => 'ws-'.$user->id,
            'at_user' => null,
            'at_valid' => false,
        ]);
    }

    private function irOnline(Vendor $v, array $extra = [])
    {
        return $this->actingAs($v->user, 'api')
            ->putJson('/api/v1/vendor/status', array_merge(['status' => StatusVendor::ONLINE->value], $extra));
    }

    /** As versões da app que já estão nas lojas não mandam os campos: não ficam trancadas. */
    public function test_sem_os_campos_vai_online_como_antes(): void
    {
        $v = $this->tecnicoOffline();

        $this->irOnline($v)->assertOk();
        $this->assertSame(StatusVendor::ONLINE, $v->fresh()->status);
    }

    public function test_com_as_duas_ligadas_vai_online(): void
    {
        $v = $this->tecnicoOffline();

        $this->irOnline($v, ['location_enabled' => true, 'notifications_enabled' => true])->assertOk();
    }

    public function test_sem_localizacao_nao_vai_online(): void
    {
        $v = $this->tecnicoOffline();

        $this->irOnline($v, ['location_enabled' => false, 'notifications_enabled' => true])->assertStatus(403);
        $this->assertSame(StatusVendor::OFFLINE, $v->fresh()->status);
    }

    public function test_sem_notificacoes_nao_vai_online(): void
    {
        $v = $this->tecnicoOffline();

        $this->irOnline($v, ['location_enabled' => true, 'notifications_enabled' => false])->assertStatus(403);
        $this->assertSame(StatusVendor::OFFLINE, $v->fresh()->status);
    }

    /**
     * 403 E NÃO 422, com a frase em português.
     *
     * Na app do técnico qualquer 422 ao mudar de estado aparece como "conta em
     * verificação". Com 403 aparece esta frase -- a que diz o que fazer.
     */
    public function test_a_recusa_diz_o_que_fazer_em_portugues(): void
    {
        $v = $this->tecnicoOffline();

        // O cabeçalho que a app do técnico manda (SessionContext). É ele que
        // escolhe a língua da resposta, não o `app.locale`.
        $msg = $this->actingAs($v->user, 'api')
            ->withHeaders(['Accept-Language' => 'pt-pt'])
            ->putJson('/api/v1/vendor/status', ['status' => StatusVendor::ONLINE->value, 'notifications_enabled' => false])
            ->assertStatus(403)
            ->json('message');

        $this->assertStringNotContainsString('exceptions.', $msg, 'Saiu a chave crua em vez da frase.');
        $this->assertStringContainsString('notificações', $msg);
    }

    /** Também como formulário ("0"), não só como booleano de JSON. */
    public function test_tambem_apanha_o_zero_de_um_formulario(): void
    {
        $v = $this->tecnicoOffline();

        $this->actingAs($v->user, 'api')
            ->put('/api/v1/vendor/status', ['status' => StatusVendor::ONLINE->value, 'notifications_enabled' => '0'], ['Accept' => 'application/json'])
            ->assertStatus(403);
    }

    /** IR OFFLINE nunca é bloqueado por isto: é exactamente o que se quer. */
    public function test_ir_offline_com_as_permissoes_desligadas_e_permitido(): void
    {
        $v = $this->tecnicoOffline();
        $v->update(['status' => StatusVendor::ONLINE]);

        $this->actingAs($v->user, 'api')
            ->putJson('/api/v1/vendor/status', [
                'status' => StatusVendor::OFFLINE->value,
                'location_enabled' => false,
                'notifications_enabled' => false,
            ])
            ->assertOk();

        $this->assertSame(StatusVendor::OFFLINE, $v->fresh()->status);
    }
}
