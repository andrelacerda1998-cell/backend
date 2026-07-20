<?php

namespace App\Http\Requests\Api\Common;

use Illuminate\Foundation\Http\FormRequest;

class GooglePlacesAutocompleteRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'input' => 'required|string|min:3|max:100',
        ];
    }
}
