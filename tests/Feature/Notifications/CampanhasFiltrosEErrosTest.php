<?php

namespace Tests\Feature\Notifications;

use App\Enums\Services\AddressType;
use App\Jobs\ProcessNotificationCampaign;
use App\Jobs\SendCampaignNotifications;
use App\Listeners\RecordExpoDeliveryFailure;
use App\Models\Address;
use App\Models\Device;
use App\Models\NotificationCampaign;
use App\Models\NotificationCampaignLog;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\CampaignNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Support\Facades\Queue;
use NotificationChannels\Expo\ExpoError;
use NotificationChannels\Expo\ExpoErrorType;
use NotificationChannels\Expo\ExpoPushToken;
use NotificationChannels\Expo\Gateway\ExpoEnvelope;
use NotificationChannels\Expo\Gateway\ExpoGateway;
use NotificationChannels\Expo\Gateway\ExpoResponse;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Filtros de publico-alvo e registo das recusas da Expo.
 *
 * As duas coisas servem a mesma pergunta: a quem e que isto chegou mesmo? Sem
 * filtros, uma campanha fala com toda a gente; sem as recusas guardadas, o
 * backoffice diz que falou, quando pode nao ter falado com ninguem.
 */
class CampanhasFiltrosEErrosTest extends TestCase
{
    use RefreshDatabase;

    private function comDispositivo(User $user): User
    {
        Device::create([
            'user_id' => $user->id,
            'device_name' => 'iPhone de teste',
            'expo_token' => ExpoPushToken::make('ExponentPushToken['.str_pad((string) $user->id, 22, 'x').']'),
        ]);

        return $user;
    }

    private function campanha(array $atributos = []): NotificationCampaign
    {
        return NotificationCampaign::create([
            'name' => 'Campanha',
            'title' => ['pt-pt' => 'Ola'],
            'body' => ['pt-pt' => 'Corpo'],
            'target_type' => 'both',
            'frequency_type' => 'once',
            'is_active' => true,
            ...$atributos,
        ]);
    }

    /** Os ids que a campanha ia mesmo notificar. */
    private function alvos(NotificationCampaign $campanha): array
    {
        Queue::fake();

        // `handle()` directo e nao `dispatchSync`: com a fila falsa, ate o
        // dispatchSync era apanhado pelo fake e o job nunca corria — a lista
        // de alvos vinha sempre vazia e os testes passavam a comparar dois
        // nadas. Assim corre o despachante a serio, e o que fica no fake sao
        // os blocos que ele despachou, que e o que se quer medir.
        (new ProcessNotificationCampaign($campanha))->handle();

        $ids = [];

        foreach (Queue::pushedJobs() as $jobs) {
            foreach ($jobs as $job) {
                if ($job['job'] instanceof SendCampaignNotifications) {
                    $ids = array_merge($ids, (new ReflectionProperty($job['job'], 'userIds'))->getValue($job['job']));
                }
            }
        }

        sort($ids);

        return $ids;
    }

    private function morada(User $user, AddressType $tipo): void
    {
        Address::create([
            'user_id' => $user->id,
            'address_type' => $tipo,
            'address_name' => 'M',
            'name' => 'Rua 1',
            'street_name' => 'Rua',
            'street_number' => '1',
            'postal_code' => '2800-000',
            'city' => 'Almada',
            'municipality' => 'Almada',
            'state' => 'Setubal',
            'country' => 'Portugal',
            'latitude' => 38.66,
            'longitude' => -9.07,
        ]);
    }

    public function test_so_alcanca_tecnicos_sem_morada_de_agendamento(): void
    {
        $comMorada = $this->comDispositivo(Vendor::factory()->create()->user);
        $this->morada($comMorada, AddressType::SCHEDULE_ADDRESS);

        $semMorada = $this->comDispositivo(Vendor::factory()->create()->user);

        $alvos = $this->alvos($this->campanha([
            'target_type' => 'vendor',
            'vendor_missing_schedule_address' => true,
        ]));

        $this->assertSame([$semMorada->id], $alvos);
    }

    public function test_so_alcanca_clientes_que_nunca_pediram(): void
    {
        $nunca = $this->comDispositivo(User::factory()->create());
        $jaPediu = $this->comDispositivo(User::factory()->create());
        Service::factory()->create(['customer_id' => $jaPediu->id]);

        $alvos = $this->alvos($this->campanha([
            'target_type' => 'customer',
            'customer_never_requested' => true,
        ]));

        $this->assertSame([$nunca->id], $alvos);
    }

    public function test_inactividade_conta_a_partir_do_ultimo_servico(): void
    {
        $recente = $this->comDispositivo(User::factory()->create());
        Service::factory()->create(['customer_id' => $recente->id, 'created_at' => now()->subDays(3)]);

        $antigo = $this->comDispositivo(User::factory()->create());
        Service::factory()->create(['customer_id' => $antigo->id, 'created_at' => now()->subDays(40)]);

        $alvos = $this->alvos($this->campanha(['target_type' => 'customer', 'inactive_days' => 30]));

        $this->assertSame([$antigo->id], $alvos);
    }

    /** Sem dispositivo nao ha push: conta-los inflacionava o alcance. */
    public function test_quem_nao_tem_dispositivo_nunca_entra(): void
    {
        $semDispositivo = User::factory()->create();
        $comDispositivo = $this->comDispositivo(User::factory()->create());

        $alvos = $this->alvos($this->campanha(['target_type' => 'customer']));

        $this->assertSame([$comDispositivo->id], $alvos);
        $this->assertNotContains($semDispositivo->id, $alvos);
    }

    /**
     * O caso que motivou isto: a Expo recusa o push e o canal NAO lanca
     * excepcao — dispara um evento. Sem o ouvir, a linha ficava a dizer
     * "enviada com sucesso" a quem nao recebeu nada.
     */
    public function test_uma_recusa_da_expo_marca_o_log_como_falhado(): void
    {
        $user = $this->comDispositivo(User::factory()->create());
        $campanha = $this->campanha();

        $log = NotificationCampaignLog::create([
            'notification_campaign_id' => $campanha->id,
            'user_id' => $user->id,
            'sent_at' => now(),
            'success' => true,
        ]);

        (new RecordExpoDeliveryFailure)->handle(new NotificationFailed(
            $user,
            new CampaignNotification($campanha, $log->id),
            'expo',
            // Construtor privado: a fabrica e `make(tipo, token, mensagem)`.
            ExpoError::make(
                ExpoErrorType::InvalidCredentials,
                ExpoPushToken::make('ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]'),
                'credenciais invalidas',
            ),
        ));

        $log->refresh();

        $this->assertFalse($log->success);
        $this->assertStringContainsString('InvalidCredentials', $log->error_message);
    }

    /**
     * O envio inteiro, com a Expo substituida por um duplo.
     *
     * Este e o teste que so apareceu ao simular um envio a serio, e apanhou um
     * erro meu: o `notifyNow` NAO lanca excepcao quando a Expo recusa, por isso
     * o `success => true` que vinha a seguir escrevia por cima da falha que o
     * listener acabara de registar. Ficava a recusa no `error_message` e o
     * sucesso a dizer que sim — o pior dos dois mundos, porque parece medido.
     *
     * O duplo do gateway e o que torna isto verificavel sem falar com a Expo:
     * o pacote usa Guzzle proprio, e um `Http::fake` do Laravel NAO o intercepta.
     */
    public function test_uma_recusa_da_expo_no_envio_completo_nao_fica_marcada_como_sucesso(): void
    {
        $this->app->bind(ExpoGateway::class, fn () => new class implements ExpoGateway
        {
            public function sendPushNotifications(ExpoEnvelope $envelope): ExpoResponse
            {
                return ExpoResponse::failed(array_map(
                    fn ($token) => ExpoError::make(ExpoErrorType::InvalidCredentials, $token, 'credenciais invalidas'),
                    $envelope->recipients,
                ));
            }
        });

        $user = $this->comDispositivo(User::factory()->create(['language' => 'pt-pt']));
        $campanha = $this->campanha(['target_type' => 'customer']);

        (new SendCampaignNotifications($campanha, [$user->id]))->handle();

        $log = $campanha->logs()->first();

        $this->assertFalse((bool) $log->success);
        $this->assertStringContainsString('InvalidCredentials', $log->error_message);
    }

    /** E um envio aceite continua a contar como sucesso. */
    public function test_um_envio_aceite_fica_marcado_como_sucesso(): void
    {
        $this->app->bind(ExpoGateway::class, fn () => new class implements ExpoGateway
        {
            public function sendPushNotifications(ExpoEnvelope $envelope): ExpoResponse
            {
                return ExpoResponse::ok();
            }
        });

        $user = $this->comDispositivo(User::factory()->create(['language' => 'pt-pt']));
        $campanha = $this->campanha(['target_type' => 'customer']);

        (new SendCampaignNotifications($campanha, [$user->id]))->handle();

        $log = $campanha->logs()->first();

        $this->assertTrue((bool) $log->success);
        $this->assertNull($log->error_message);
    }

    /** Uma falha noutro canal nao tem nada a ver com isto. */
    public function test_ignora_falhas_de_outros_canais(): void
    {
        $user = $this->comDispositivo(User::factory()->create());
        $campanha = $this->campanha();

        $log = NotificationCampaignLog::create([
            'notification_campaign_id' => $campanha->id,
            'user_id' => $user->id,
            'sent_at' => now(),
            'success' => true,
        ]);

        (new RecordExpoDeliveryFailure)->handle(new NotificationFailed(
            $user,
            new CampaignNotification($campanha, $log->id),
            'mail',
            [],
        ));

        $this->assertTrue($log->refresh()->success);
    }
}
