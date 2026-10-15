<?php

namespace Tests\Feature;

use App\Jobs\ProcessNotificationCampaign;
use App\Jobs\SendCampaignNotifications;
use App\Models\Device;
use App\Models\GeneralSettings\OperationArea;
use App\Models\NotificationCampaign;
use App\Models\NotificationCampaignOptOut;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Campanhas\PublicoDaCampanha;
use App\Services\Translation\Translator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use NotificationChannels\Expo\ExpoPushToken;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Criar campanhas de push no backoffice (15/10/2026): nascem desligadas, o
 * público conta-se com a regra do envio, e só se ligam com o inglês revisto.
 */
class AdminCriarCampanhasPushTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.admin_api.token' => 'a-valid-token']);
    }

    private function admin(): static
    {
        return $this->withHeaders(['Authorization' => 'Bearer a-valid-token']);
    }

    private function dados(array $extra = []): array
    {
        return array_replace_recursive([
            'name' => 'Voltar a pedir',
            'title' => ['pt-pt' => 'Precisas de um canalizador?', 'en' => ''],
            'body' => ['pt-pt' => 'Há técnicos na tua zona.', 'en' => ''],
            'target_type' => 'customer',
            'frequency_type' => 'once',
        ], $extra);
    }

    private function comDispositivo(User $user): User
    {
        Device::create([
            'user_id' => $user->id,
            'device_name' => 'Telemóvel de teste',
            'expo_token' => ExpoPushToken::make('ExponentPushToken['.str_pad((string) $user->id, 22, 'x').']'),
        ]);

        return $user;
    }

    /** Os ids que o envio notificaria, a correr o despachante a sério. */
    private function quemOEnvioNotifica(NotificationCampaign $campanha): array
    {
        Queue::fake();
        (new ProcessNotificationCampaign($campanha))->handle();

        $ids = [];
        foreach (Queue::pushedJobs() as $jobs) {
            foreach ($jobs as $job) {
                if ($job['job'] instanceof SendCampaignNotifications) {
                    $ids = array_merge($ids, (new ReflectionProperty($job['job'], 'userIds'))->getValue($job['job']));
                }
            }
        }

        return $ids;
    }

    // ------------------------------------------------------------- criar

    public function test_cria_desligada_e_sem_os_filtros_que_nao_se_aplicam(): void
    {
        $resposta = $this->admin()->postJson('/api/v1/admin/notification-campaigns', $this->dados([
            'is_active' => true,
            'user_status' => 'online',
            'vendor_eligibility' => 'ready',
            'vendor_missing_schedule_address' => true,
            'customer_never_requested' => true,
        ]))->assertCreated();

        $campanha = NotificationCampaign::findOrFail($resposta->json('data.id'));

        $this->assertFalse($campanha->is_active);
        $this->assertTrue($resposta->json('data.rascunho'));
        $this->assertNull($campanha->user_status);
        $this->assertNull($campanha->vendor_eligibility);
        $this->assertFalse($campanha->vendor_missing_schedule_address);
        $this->assertTrue($campanha->customer_never_requested);
        $this->assertSame('Precisas de um canalizador?', $campanha->title['pt-pt']);
    }

    /** O inglês só conta como revisto quando há inglês e alguém o marcou. */
    public function test_o_ingles_fica_por_rever_ate_alguem_o_marcar(): void
    {
        $porRever = $this->admin()->postJson('/api/v1/admin/notification-campaigns', $this->dados([
            'title' => ['en' => 'Need a plumber?'],
        ]))->assertCreated();
        $revisto = $this->admin()->postJson('/api/v1/admin/notification-campaigns', $this->dados([
            'title' => ['en' => 'Need a plumber?'], 'english_reviewed' => true,
        ]))->assertCreated();

        $this->assertTrue($porRever->json('data.ingles_por_rever'));
        $this->assertFalse($revisto->json('data.ingles_por_rever'));
    }

    public function test_recusa_o_que_o_filament_tambem_recusava_e_um_intervalo_abaixo_de_uma_hora(): void
    {
        $this->admin()->postJson('/api/v1/admin/notification-campaigns', $this->dados(['title' => ['pt-pt' => '']]))
            ->assertUnprocessable()->assertJsonValidationErrors('title.pt-pt');

        $this->admin()->postJson('/api/v1/admin/notification-campaigns', $this->dados([
            'frequency_type' => 'custom', 'frequency_value' => 30, 'frequency_unit' => 'minutes',
        ]))->assertUnprocessable()->assertJsonValidationErrors('frequency_value');

        $this->admin()->postJson('/api/v1/admin/notification-campaigns', $this->dados([
            'frequency_type' => 'custom', 'frequency_value' => 2, 'frequency_unit' => 'hours',
        ]))->assertCreated();
    }

    public function test_o_destino_ao_abrir_tem_de_existir_no_catalogo(): void
    {
        $area = OperationArea::create(['name' => 'Categoria Push Teste']);

        $this->admin()->postJson('/api/v1/admin/notification-campaigns', $this->dados([
            'open_type' => 'OperationArea', 'open_id' => $area->id + 1000,
        ]))->assertUnprocessable()->assertJsonValidationErrors('open_id');

        $this->admin()->postJson('/api/v1/admin/notification-campaigns', $this->dados([
            'open_type' => 'OperationArea', 'open_id' => $area->id,
        ]))->assertCreated()->assertJsonPath('data.open_id', $area->id);
    }

    // ------------------------------------------------------------ público

    /** O número que o backoffice mostra é o de quem o envio notifica. */
    public function test_o_publico_conta_quem_o_envio_notifica(): void
    {
        $this->comDispositivo(User::factory()->create());
        $this->comDispositivo(User::factory()->create());
        User::factory()->create(); // sem dispositivo: não recebe push
        $saiu = $this->comDispositivo(User::factory()->create());
        NotificationCampaignOptOut::create(['user_id' => $saiu->id, 'notification_campaign_id' => null]);
        $this->comDispositivo(Vendor::factory()->create()->user); // técnico: fora de "customer"

        $contado = $this->admin()->postJson('/api/v1/admin/notification-campaigns/publico', ['target_type' => 'customer'])
            ->assertOk()->json('data.pessoas');

        $campanha = NotificationCampaign::create($this->dados() + ['is_active' => true]);

        $this->assertSame(count($this->quemOEnvioNotifica($campanha)), $contado);
        $this->assertSame(2, $contado);
    }

    /** Com o filtro de elegibilidade os técnicos vão em blocos, e o número bate na mesma. */
    public function test_com_elegibilidade_a_contagem_bate_com_o_envio(): void
    {
        $this->comDispositivo(Vendor::factory()->create()->user);
        $this->comDispositivo(Vendor::factory()->create()->user);
        $this->comDispositivo(User::factory()->create());

        foreach (['incomplete', 'ready'] as $elegibilidade) {
            $campanha = new NotificationCampaign(['target_type' => 'both', 'vendor_eligibility' => $elegibilidade]);

            $this->assertSame(
                app(PublicoDaCampanha::class)->utilizadores($campanha)->count(),
                app(PublicoDaCampanha::class)->contar($campanha),
                $elegibilidade,
            );
        }
    }

    public function test_o_publico_de_uma_campanha_gravada(): void
    {
        $this->comDispositivo(User::factory()->create());
        $campanha = NotificationCampaign::create($this->dados() + ['is_active' => false]);

        $this->admin()->getJson("/api/v1/admin/notification-campaigns/{$campanha->id}/publico")
            ->assertOk()->assertJsonPath('data.pessoas', 1);
    }

    // ------------------------------------------------- rascunhos e ligar

    public function test_um_rascunho_edita_se_e_apaga_se(): void
    {
        $campanha = NotificationCampaign::create($this->dados() + ['is_active' => false]);

        $this->admin()->putJson("/api/v1/admin/notification-campaigns/{$campanha->id}", $this->dados(['name' => 'Outro nome']))
            ->assertOk()->assertJsonPath('data.name', 'Outro nome');
        $this->admin()->deleteJson("/api/v1/admin/notification-campaigns/{$campanha->id}")->assertOk();

        $this->assertSoftDeleted($campanha);
    }

    /** Depois de enviada, os números são dela: não se reescreve nem se apaga. */
    public function test_uma_campanha_ja_enviada_ou_ligada_nao_se_mexe(): void
    {
        $enviada = NotificationCampaign::create($this->dados() + ['is_active' => false, 'last_sent_at' => now()]);
        $ligada = NotificationCampaign::create($this->dados() + ['is_active' => true]);

        $this->admin()->putJson("/api/v1/admin/notification-campaigns/{$enviada->id}", $this->dados())->assertStatus(409);
        $this->admin()->deleteJson("/api/v1/admin/notification-campaigns/{$ligada->id}")->assertStatus(409);
    }

    /** Ligada com o inglês por rever, não saía para ninguém e parecia ativa. */
    public function test_nao_liga_com_o_ingles_por_rever(): void
    {
        $porRever = NotificationCampaign::create($this->dados(['title' => ['en' => 'Hi']]) + ['is_active' => false]);
        $semIngles = NotificationCampaign::create($this->dados() + ['is_active' => false]);

        $this->admin()->putJson("/api/v1/admin/notification-campaigns/{$porRever->id}/active", ['active' => true])->assertStatus(409);
        $this->admin()->putJson("/api/v1/admin/notification-campaigns/{$semIngles->id}/active", ['active' => true])
            ->assertOk()->assertJsonPath('data.is_active', true);
        // Desligar nunca é recusado.
        $this->admin()->putJson("/api/v1/admin/notification-campaigns/{$porRever->id}/active", ['active' => false])->assertOk();
    }

    // ----------------------------------------------------------- traduzir

    public function test_traduz_quando_ha_tradutor_e_diz_quando_nao_ha(): void
    {
        $this->app->instance(Translator::class, new class implements Translator
        {
            public function translate(string $text, string $from, string $to): ?string
            {
                return "EN: {$text}";
            }

            public function isConfigured(): bool
            {
                return true;
            }
        });

        $this->admin()->postJson('/api/v1/admin/notification-campaigns/traduzir', ['title' => 'Olá', 'body' => ''])
            ->assertOk()->assertJsonPath('data.title', 'EN: Olá')->assertJsonPath('data.body', '');

        $this->app->instance(Translator::class, new class implements Translator
        {
            public function translate(string $text, string $from, string $to): ?string
            {
                return null;
            }

            public function isConfigured(): bool
            {
                return false;
            }
        });

        $this->admin()->postJson('/api/v1/admin/notification-campaigns/traduzir', ['title' => 'Olá'])->assertStatus(503);
    }

    public function test_sem_o_token_do_backoffice_nao_cria(): void
    {
        // As migrações antigas já deixam campanhas na base: conta-se a diferença.
        $antes = NotificationCampaign::count();

        $this->postJson('/api/v1/admin/notification-campaigns', $this->dados())->assertUnauthorized();
        $this->assertSame($antes, NotificationCampaign::count());
    }
}
