<?php

namespace App\Http\Requests\Api\Common;

use Illuminate\Foundation\Http\FormRequest;

class AppNeedsUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'version' => 'string|required',
            'platform' => 'string|required|in:ios,android',
        ];
    }
}
