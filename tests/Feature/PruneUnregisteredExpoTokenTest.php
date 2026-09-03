<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoError;
use NotificationChannels\Expo\ExpoErrorType;
use NotificationChannels\Expo\ExpoPushToken;
use Tests\TestCase;

/**
 * Tokens de push mortos (DeviceNotRegistered) têm de ser podados, senão
 * acumulam-se e nunca se sabe se um técnico foi mesmo avisado (incidente 13/08).
 */
class PruneUnregisteredExpoTokenTest extends TestCase
{
    use RefreshDatabase;

    private const DEAD = 'ExponentPushToken[dead-000000000000]';
    private const ALIVE = 'ExponentPushToken[alive-00000000000]';

    private function fireFailed(User $user, string $token, ExpoErrorType $type): void
    {
        event(new NotificationFailed(
            $user,
            new class extends Notification {},
            'expo',
            ExpoError::make($type, ExpoPushToken::make($token), 'x'),
        ));
    }

    public function test_apaga_o_device_do_token_nao_registado(): void
    {
        $user = User::factory()->create();
        $dead = Device::create(['user_id' => $user->id, 'expo_token' => self::DEAD, 'device_name' => 'test']);
        $alive = Device::create(['user_id' => $user->id, 'expo_token' => self::ALIVE, 'device_name' => 'test']);

        $this->fireFailed($user, self::DEAD, ExpoErrorType::DeviceNotRegistered);

        $this->assertSoftDeleted('devices', ['id' => $dead->id]);
        $this->assertDatabaseHas('devices', ['id' => $alive->id, 'deleted_at' => null]);
    }

    public function test_nao_apaga_para_outros_tipos_de_erro(): void
    {
        $user = User::factory()->create();
        $device = Device::create(['user_id' => $user->id, 'expo_token' => self::DEAD, 'device_name' => 'test']);

        // MessageRateExceeded é transitório — o token continua válido.
        $this->fireFailed($user, self::DEAD, ExpoErrorType::MessageRateExceeded);

        $this->assertDatabaseHas('devices', ['id' => $device->id, 'deleted_at' => null]);
    }

    public function test_ignora_falhas_de_outros_canais(): void
    {
        $user = User::factory()->create();
        $device = Device::create(['user_id' => $user->id, 'expo_token' => self::DEAD, 'device_name' => 'test']);

        event(new NotificationFailed($user, new class extends Notification {}, 'mail', []));

        $this->assertDatabaseHas('devices', ['id' => $device->id, 'deleted_at' => null]);
    }
}
