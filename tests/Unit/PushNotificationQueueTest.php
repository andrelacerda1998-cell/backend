<?php

namespace Tests\Unit;

use App\Notifications\Customer\ServiceVendorOnTheWayNotification;
use App\Notifications\Vendor\VendorNoShowNudgeNotification;
use ReflectionClass;
use Tests\TestCase;

/**
 * As notificações de push encaminham o canal expo para a queue "push" (com
 * retry no Horizon), deixando o canal database na default. Regressão do
 * incidente 13/08: com tries=1 na default, uma falha transitória do Expo
 * perdia o push sem nova tentativa.
 */
class PushNotificationQueueTest extends TestCase
{
    public static function pushNotifications(): array
    {
        return [
            'cliente: técnico a caminho' => [ServiceVendorOnTheWayNotification::class],
            'vendor: nudge de não-comparência' => [VendorNoShowNudgeNotification::class],
        ];
    }

    /**
     * @dataProvider pushNotifications
     */
    public function test_expo_channel_is_routed_to_the_push_queue(string $notificationClass): void
    {
        // Sem construtor: viaQueues() não depende do payload da notificação.
        $notification = (new ReflectionClass($notificationClass))->newInstanceWithoutConstructor();

        $this->assertSame(['expo' => 'push'], $notification->viaQueues());
    }
}
