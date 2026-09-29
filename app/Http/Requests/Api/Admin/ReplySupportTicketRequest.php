<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReplySupportTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // A coluna e TEXT, mas um limite evita que um engano do outro lado
            // encha a base de dados. 5000 e o mesmo que o tecnico pode escrever.
            'admin_reply' => ['sometimes', 'nullable', 'string', 'max:5000'],
            // Os tres estados da migracao: open | answered | closed.
            'status' => ['sometimes', 'required', Rule::in(['open', 'answered', 'closed'])],
        ];
    }
}
