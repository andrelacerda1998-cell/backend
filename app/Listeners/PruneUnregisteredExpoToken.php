<?php

namespace App\Listeners;

use App\Models\Device;
use Illuminate\Notifications\Events\NotificationFailed;
use NotificationChannels\Expo\ExpoError;

/**
 * Poda tokens de push mortos.
 *
 * O canal Expo (laravel-notification-channels/expo) dispara NotificationFailed por
 * cada token que a Expo rejeita. Quando o motivo é DeviceNotRegistered, o token
 * deixou de ser válido (app desinstalada, sessão trocada de dispositivo, etc.) e a
 * Expo diz explicitamente para parar de enviar para ele.
 *
 * Sem isto, os tokens mortos acumulavam-se: `routeNotificationForExpo()` enviava
 * para TODOS os dispositivos do utilizador, incluindo os que já não recebem — e
 * nunca se conseguia saber se um técnico foi mesmo avisado (foi o que ficou por
 * provar no incidente de 13/08, com o técnico a ter 3 dispositivos, 2 antigos).
 * Ao apagar o Device (soft delete, fica auditado), o próximo envio já só vai para
 * os dispositivos vivos.
 */
class PruneUnregisteredExpoToken
{
    public function handle(NotificationFailed $event): void
    {
        if ($event->channel !== 'expo') {
            return;
        }

        $error = $event->data;

        if (! $error instanceof ExpoError || ! $error->type->isDeviceNotRegistered()) {
            return;
        }

        Device::query()
            ->where('expo_token', (string) $error->token)
            ->delete();
    }
}
