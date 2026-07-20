<?php

namespace App\Http\Requests\Api\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LocaleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'language' => 'string|required|in:'.implode(',', config('app.locales')),
        ];
    }

    protected function prepareForValidation(): void
    {
        $language = $this->get('language');
        if ($language) {
            $this->merge(['language' => normalizeAcceptLanguage($language)]);
        }
    }
}
