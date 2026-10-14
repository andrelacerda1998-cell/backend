<?php

namespace App\Notifications\Concerns;

use App\Models\Service;

/**
 * O texto dos avisos de "a Piquet cancelou" (ver CancelarPeloBackoffice), na
 * língua de quem o recebe.
 *
 * O tipo de serviço lê-se também quando já saiu do catálogo — o pedido é
 * antigo, o tipo pode ter sido apagado depois. Sem tipo (um personalizado),
 * a frase não o menciona em vez de ficar com um "de ." pendurado.
 */
trait CanceladoPelaPiquet
{
    /** @return array{0: string, 1: string} */
    private function textosDoCancelamento(Service $service, $notifiable, string $quem): array
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');
        $tipo = $service->serviceType()->withTrashed()->first()?->getTranslation('name', $language) ?: null;
        $chave = "notifications.canceladoPelaPiquet.{$quem}";

        return [
            __("{$chave}.title", [], $language),
            $tipo
                ? __("{$chave}.description", ['service_type' => $tipo], $language)
                : __("{$chave}.description_sem_tipo", [], $language),
        ];
    }
}
