<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

class TranslatableArrayCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (is_null($value)) {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return json_encode($value ?? []);
    }

    /**
     * O tópico no idioma pedido, com recuo em vez de silêncio.
     *
     * Antes, um tópico sem o idioma pedido devolvia STRING VAZIA — e o ecrã do
     * serviço mostrava uma linha em branco no meio do "Inclui". A falha não
     * rebentava nada e não aparecia em lado nenhum: só um cliente é que a via.
     *
     * Agora cai por esta ordem: o idioma pedido, o idioma de recurso da app
     * (português), e por fim o primeiro que existir. Um tópico em português no
     * meio de uma lista francesa é mau; uma linha em branco é pior, porque
     * parece que falta ali alguma coisa que ninguém sabe o que é.
     *
     * Os tópicos que ainda são texto simples — o formato antigo, sem idiomas —
     * continuam a sair tal e qual.
     */
    public static function getTranslated($value, $locale): array
    {
        if (!is_array($value) || empty($value)) {
            return [];
        }

        $recurso = config('app.fallback_locale', 'pt-pt');

        return collect($value)
            ->map(function ($item) use ($locale, $recurso) {
                if (is_string($item)) {
                    return $item;
                }

                if (!is_array($item)) {
                    return '';
                }

                foreach ([$locale, $recurso] as $tentativa) {
                    if (isset($item[$tentativa]) && is_string($item[$tentativa]) && $item[$tentativa] !== '') {
                        return $item[$tentativa];
                    }
                }

                // Último recurso: o primeiro idioma que este tópico tenha.
                foreach ($item as $texto) {
                    if (is_string($texto) && $texto !== '') {
                        return $texto;
                    }
                }

                return '';
            })
            // Um tópico que nem assim tenha texto não é um tópico: sai da
            // lista em vez de ficar como uma linha vazia com um ponto.
            ->filter(fn ($texto) => $texto !== '')
            ->values()
            ->toArray();
    }
}
