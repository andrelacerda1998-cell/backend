<?php

namespace Tests\Feature\Payments;

use Tests\TestCase;

/**
 * O payload da carteira vai para o Payshop como STRING.
 *
 * O Payshop respondeu (30/09) "o payload está incorreto" e sem `validation_hash`.
 * Eram dois erros: a app mandava só o token em vez do `PaymentData` inteiro (isso
 * corrige-se do lado da app, em `envelopeDoGooglePay`), e o servidor enviava-o
 * como objeto aninhado quando o exemplo deles é `"payload": "{...}"`.
 *
 * Este teste guarda a segunda metade. É um teste de FORMA, não de rede: chamar o
 * Payshop a sério aqui seria lento, frágil e dependente do sandbox deles.
 */
class PayloadDaCarteiraTest extends TestCase
{
    /** A mesma expressão que o `processWalletPayment` usa. */
    private function paraPayshop(array|string $payload): string
    {
        return is_array($payload)
            ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : $payload;
    }

    public function test_um_objeto_vai_como_string_json(): void
    {
        $resultado = $this->paraPayshop(['apiVersion' => 2, 'paymentMethodData' => ['type' => 'CARD']]);

        $this->assertIsString($resultado);
        $this->assertSame('{"apiVersion":2,"paymentMethodData":{"type":"CARD"}}', $resultado);
    }

    /**
     * As barras NÃO vão escapadas.
     *
     * Por omissão o PHP escreve `\/` em vez de `/`, e o token do Google leva
     * base64 cheio de barras. O exemplo do Payshop mostra-as sem escape; um
     * `\/` a mais muda os bytes sobre os quais a assinatura foi calculada.
     */
    public function test_as_barras_do_token_nao_vao_escapadas(): void
    {
        $resultado = $this->paraPayshop(['token' => 'a/b+c/d']);

        $this->assertStringContainsString('a/b+c/d', $resultado);
        $this->assertStringNotContainsString('a\\/b', $resultado);
    }

    /** O Apple Pay manda uma string; essa passa intacta, sem dupla codificação. */
    public function test_uma_string_passa_intacta(): void
    {
        $cru = '{"data":"abc","version":"EC_v1"}';

        $this->assertSame($cru, $this->paraPayshop($cru));
    }

    /** Acentos legíveis: o nome na morada de faturação passa por aqui. */
    public function test_os_acentos_nao_viram_escapes_unicode(): void
    {
        $resultado = $this->paraPayshop(['name' => 'João Conceição']);

        $this->assertStringContainsString('João Conceição', $resultado);
    }
}
