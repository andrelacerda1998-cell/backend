<?php

namespace App\Http\Requests\Api\Common;

use Illuminate\Foundation\Http\FormRequest;

class ChatRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'message' => 'required|string',
        ];
    }
}
