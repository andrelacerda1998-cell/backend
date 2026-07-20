<?php

namespace App\Http\Requests\Api\Vendor\Status;

use App\Enums\Vendors\StatusVendor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StatusRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(StatusVendor::class)],
        ];
    }
}
