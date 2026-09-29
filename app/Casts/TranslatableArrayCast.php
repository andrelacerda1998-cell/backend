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
     * (português), e por fim qualquer outro que a app sirva. Um tópico em
     * português no meio de uma lista francesa é mau; uma linha em branco é
     * pior, porque parece que falta ali alguma coisa que ninguém sabe o que é
     * — e pior ainda porque estas linhas são o registo do que foi combinado,
     * e uma que desapareça é uma discussão à porta do cliente.
     *
     * Mas só idiomas que a app sirva. Um tópico gravado apenas num idioma que
     * não existe na app é dado partido, não uma tradução em falta: esse sai
     * mesmo da lista, que era o que este código já fazia bem.
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
        $suportados = (array) config('app.locales', ['pt-pt', 'en']);

        return collect($value)
            ->map(function ($item) use ($locale, $recurso, $suportados) {
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

                // Último recurso: qualquer idioma QUE A APP SIRVA. Um tópico
                // gravado só num idioma que não existe na app é dado partido,
                // não uma tradução em falta — mostrá-lo seria pôr no ecrã de
                // um cliente texto que ninguém escolheu pôr lá.
                foreach ($suportados as $tentativa) {
                    if (isset($item[$tentativa]) && is_string($item[$tentativa]) && $item[$tentativa] !== '') {
                        return $item[$tentativa];
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
