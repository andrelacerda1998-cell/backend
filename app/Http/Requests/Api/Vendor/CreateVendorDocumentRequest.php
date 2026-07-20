<?php

namespace App\Http\Requests\Api\Vendor;

use Illuminate\Foundation\Http\FormRequest;

class CreateVendorDocumentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'type' => ['required', 'exists:documents,id'],
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:2048'],
        ];
    }
}
