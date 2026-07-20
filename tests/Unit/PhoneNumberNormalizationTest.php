<?php

namespace Tests\Unit;

use App\Services\Common\PhoneLoginSmsService;
use PHPUnit\Framework\TestCase;

class PhoneNumberNormalizationTest extends TestCase
{
    /**
     * Todas as variantes PT do mesmo número têm de convergir para +351XXXXXXXXX,
     * com ou sem traço — é o que garante que o lookup e a unicidade batem certo
     * entre o signup (envia +351-X…), o guest flow (envia +351X…) e dados legados.
     */
    public function test_portuguese_variants_normalize_to_canonical_format(): void
    {
        $variants = [
            '+351910417271',
            '+351-910417271',
            '910417271',
            '351910417271',
            '00351910417271',
            '+351 910 417 271',
            ' +351-910.417.271 ',
        ];

        foreach ($variants as $variant) {
            $this->assertSame(
                '+351910417271',
                PhoneLoginSmsService::normalizePhoneNumber($variant),
                "Variante '{$variant}' não normalizou para o formato canónico."
            );
        }
    }

    public function test_foreign_numbers_are_kept_intact(): void
    {
        $this->assertSame('+33612345678', PhoneLoginSmsService::normalizePhoneNumber('+33612345678'));
        $this->assertSame('+33612345678', PhoneLoginSmsService::normalizePhoneNumber('+33 612 345 678'));
    }

    public function test_normalization_is_idempotent(): void
    {
        $once = PhoneLoginSmsService::normalizePhoneNumber('+351-910417271');

        $this->assertSame($once, PhoneLoginSmsService::normalizePhoneNumber($once));
    }
}
