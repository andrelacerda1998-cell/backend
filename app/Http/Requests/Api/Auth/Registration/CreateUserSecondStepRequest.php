<?php

namespace App\Http\Requests\Api\Auth\Registration;

use Illuminate\Foundation\Http\FormRequest;

class CreateUserSecondStepRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'zip' => ['string'],
        ];
    }
}
