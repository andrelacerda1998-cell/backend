<?php

namespace App\Notifications\Concerns;

/**
 * Aplica as preferências de notificação do técnico (vendors.notification_preferences)
 * ao conjunto de canais devolvido pelo via().
 *
 * Regras:
 *  - Só silencia quando o notifiable é um técnico. Clientes ficam intactos.
 *  - Só remove o canal 'expo' (o push). 'database' e 'mail' mantêm-se, para o
 *    histórico dentro da app continuar completo mesmo com o toggle desligado.
 *  - Omissão = recebe tudo (coluna a null, chave em falta, valor inválido).
 */
trait RespectsVendorPreference
{
    /**
     * @param  array<int,string>  $channels
     * @return array<int,string>
     */
    protected function applyVendorPreference($notifiable, string $preference, array $channels): array
    {
        if (! is_object($notifiable) || ! method_exists($notifiable, 'wantsVendorNotification')) {
            return $channels;
        }

        if ($notifiable->wantsVendorNotification($preference)) {
            return $channels;
        }

        return array_values(array_diff($channels, ['expo']));
    }
}
