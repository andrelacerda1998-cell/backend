<?php

namespace App\Http\Requests\Api\Vendor\Survey;

use App\Models\GeneralSettings\AllowedZone;
use App\Models\GeneralSettings\SurveyCity;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class VoteCitiesRequest extends FormRequest
{
    /** Concelhos que o tecnico tem de escolher para o registo ficar util. */
    private const MIN_CITIES = 3;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'allowed_zone_ids'   => 'nullable|array',
            'allowed_zone_ids.*' => 'integer|exists:allowed_zone,id',
            'survey_city_ids'    => 'nullable|array',
            'survey_city_ids.*'  => 'integer|exists:survey_cities,id',
        ];
    }

    /**
     * O minimo e sobre o TOTAL das duas listas, nao sobre cada uma: as
     * escolhas chegam separadas (zonas ja ativas vs. concelhos por abrir),
     * mas para o tecnico sao a mesma pergunta -- onde queres trabalhar.
     *
     * A app ja exige o mesmo minimo, mas so aqui e que a regra vale para
     * todos os clientes, incluindo versoes antigas.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $chosen = count($this->input('allowed_zone_ids', []))
                + count($this->input('survey_city_ids', []));

            // Nunca exigir mais concelhos do que os que existem para escolher:
            // com a lista quase vazia, o minimo trancaria o registo sem saida.
            $available = AllowedZone::query()->count()
                + SurveyCity::query()->where('active', true)->count();

            $required = min(self::MIN_CITIES, $available);

            if ($required > 0 && $chosen < $required) {
                $validator->errors()->add(
                    'allowed_zone_ids',
                    __('request/validation.cities.min', ['min' => $required]),
                );
            }
        });
    }
}
