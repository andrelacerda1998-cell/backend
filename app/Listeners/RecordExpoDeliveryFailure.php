<?php

namespace App\Listeners;

use App\Models\NotificationCampaignLog;
use App\Notifications\CampaignNotification;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Support\Facades\Log;
use NotificationChannels\Expo\ExpoError;

/**
 * Guarda o que a Expo recusou.
 *
 * O `success` do log de campanha so dizia "o `notifyNow()` nao rebentou" — e o
 * canal Expo NAO rebenta quando um push e recusado: dispara `NotificationFailed`
 * por cada token recusado e segue em frente. Resultado: uma campanha em que a
 * Expo recusou TODOS os pushes ficava registada como 100% enviada.
 *
 * O unico erro que ja era tratado e o `DeviceNotRegistered`, e so para podar o
 * token (ver PruneUnregisteredExpoToken). Os outros quatro desapareciam sem
 * deixar rasto:
 *
 *   MessageTooBig        titulo/corpo grandes demais — campanha mal escrita
 *   MessageRateExceeded  demasiados envios seguidos — e preciso abrandar
 *   MismatchSenderId     credenciais de push trocadas entre apps
 *   InvalidCredentials   NADA esta a ser entregue, a ninguem
 *
 * Os dois ultimos sao os graves: nao sao por utilizador, sao de configuracao, e
 * com eles a campanha chega a zero pessoas enquanto o backoffice mostra tudo
 * verde. Sem isto escrito, isso so se descobre porque ninguem responde.
 *
 * Guarda-se em dois sitios, de proposito: no log da campanha (para o alcance
 * medido bater com a realidade) e no log da aplicacao (porque um
 * `InvalidCredentials` nao pertence a uma campanha — pertence a todas).
 */
class RecordExpoDeliveryFailure
{
    public function handle(NotificationFailed $event): void
    {
        if ($event->channel !== 'expo') {
            return;
        }

        $error = $event->data;
        $tipo = $error instanceof ExpoError ? $error->type->value : 'Unknown';

        Log::warning('[expo] push recusado', [
            'type' => $tipo,
            'user_id' => $event->notifiable->id ?? null,
            'notification' => $event->notification::class,
        ]);

        $notification = $event->notification;

        if (! $notification instanceof CampaignNotification) {
            return;
        }

        $logId = $notification->campaignLogId();

        if (! $logId) {
            return;
        }

        // `update` directo e nao `find`+`save`: um utilizador com varios
        // dispositivos gera um evento por token, e a primeira recusa e que
        // interessa — a linha ja fica marcada, e as seguintes acrescentam o
        // tipo sem apagar o que estava.
        $log = NotificationCampaignLog::find($logId);

        if (! $log) {
            return;
        }

        $anterior = $log->error_message;

        $log->update([
            'success' => false,
            'error_message' => $anterior && ! str_contains($anterior, $tipo)
                ? $anterior.', '.$tipo
                : ($anterior ?: $tipo),
        ]);
    }
}
