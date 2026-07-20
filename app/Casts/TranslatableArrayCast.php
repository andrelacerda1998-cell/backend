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

    public static function getTranslated($value, $locale): array
    {
        if (!is_array($value) || empty($value)) {
            return [];
        }

        return collect($value)->map(function ($item) use ($locale) {
            if (is_array($item) && isset($item[$locale])) {
                return $item[$locale];
            }
            return is_string($item) ? $item : '';
        })->toArray();
    }
}
