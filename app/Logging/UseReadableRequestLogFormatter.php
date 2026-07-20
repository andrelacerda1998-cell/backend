<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use JsonException;
use Monolog\Formatter\LineFormatter;
use Monolog\LogRecord;

class UseReadableRequestLogFormatter
{
    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(function (LogRecord $record): LogRecord {
            return $record->with(context: $this->normalize($record->context));
        });

        $formatter = new LineFormatter(
            format: "[%datetime%] %level_name%: %message%\nContext: %context%\nExtra: %extra%\n\n",
            dateFormat: 'Y-m-d H:i:s',
            allowInlineLineBreaks: true,
            ignoreEmptyContextAndExtra: true,
        );

        $formatter->setJsonPrettyPrint(true);
        $formatter->setBasePath(base_path());

        foreach ($logger->getHandlers() as $handler) {
            $handler->setFormatter($formatter);
        }
    }

    /**
     * @param array<mixed> $context
     * @return array<mixed>
     */
    private function normalize(array $context): array
    {
        foreach ($context as $key => $value) {
            $context[$key] = $this->normalizeValue($key, $value);
        }

        return $context;
    }

    private function normalizeValue(mixed $key, mixed $value): mixed
    {
        if ($this->isSensitiveKey($key)) {
            return '[redacted]';
        }

        if (is_array($value)) {
            return $this->normalize($value);
        }

        if (is_string($value) && $this->looksLikeJson($value)) {
            try {
                return $this->normalize(json_decode($value, true, flags: JSON_THROW_ON_ERROR));
            } catch (JsonException) {
                return $value;
            }
        }

        return $value;
    }

    private function isSensitiveKey(mixed $key): bool
    {
        if (! is_string($key)) {
            return false;
        }

        return in_array(strtolower($key), [
            'api_key',
            'authorization',
            'password',
            'signature',
            'token',
            // Dados de cartão (PCI-DSS): nunca devem chegar aos logs em claro.
            'card_pan',
            'card_cvv',
            'card_expiry_month',
            'card_expiry_year',
            'card_holder',
            'pan',
            'cvv',
        ], true);
    }

    private function looksLikeJson(string $value): bool
    {
        $value = trim($value);

        return str_starts_with($value, '{') || str_starts_with($value, '[');
    }
}
