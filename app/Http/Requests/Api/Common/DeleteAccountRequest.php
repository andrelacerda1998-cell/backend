<?php

namespace App\Http\Requests\Api\Common;

use Illuminate\Foundation\Http\FormRequest;

class DeleteAccountRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'password' => 'string|required'
        ];
    }
}
