<?php

namespace App\Http\Requests\Api\Vendor\Cities;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class SaveCitiesRequest extends FormRequest
{
    /** Minimo de cidades onde o tecnico aceita trabalhar. */
    public const MIN_AVAILABLE = 3;

    /** O top de maior interesse tem tamanho fixo. */
    public const PREFERRED_COUNT = 3;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'available_city_ids'   => 'required|array|min:'.self::MIN_AVAILABLE,
            'available_city_ids.*' => 'integer|distinct|exists:cities,id',
            'preferred_city_ids'   => 'required|array|size:'.self::PREFERRED_COUNT,
            'preferred_city_ids.*' => 'integer|distinct|exists:cities,id',
        ];
    }

    /**
     * O top 3 tem de ser um subconjunto das cidades disponiveis: nao faz
     * sentido priorizar uma cidade onde o tecnico nao aceita trabalhar.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $available = $this->input('available_city_ids', []);
            $preferred = $this->input('preferred_city_ids', []);

            if (array_diff($preferred, $available)) {
                $validator->errors()->add(
                    'preferred_city_ids',
                    __('request/validation.cities.preferred_subset'),
                );
            }
        });
    }
}
