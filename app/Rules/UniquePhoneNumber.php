<?php

namespace App\Rules;

use App\Services\Common\PhoneLoginSmsService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Unicidade de telefone tolerante a formatos: compara o número normalizado
 * com todas as variantes guardadas (+351X…, +351-X…, X…), só entre users ativos.
 */
class UniquePhoneNumber implements ValidationRule
{
    public function __construct(private readonly int|string|null $ignoreUserId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        $existing = app(PhoneLoginSmsService::class)->findUserByPhone((string) $value);

        if ($existing && (int) $existing->id !== (int) ($this->ignoreUserId ?? 0)) {
            $fail(__('request/validation.unique', ['attribute' => __('request/validation.attributes.phone_number')]));
        }
    }
}
