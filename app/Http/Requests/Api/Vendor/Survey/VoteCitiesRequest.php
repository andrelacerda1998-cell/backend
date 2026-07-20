<?php

namespace App\Http\Requests\Api\Vendor\Survey;

use Illuminate\Foundation\Http\FormRequest;

class VoteCitiesRequest extends FormRequest
{
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
}
