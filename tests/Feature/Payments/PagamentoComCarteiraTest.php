<?php

namespace Tests\Feature\Payments;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use RwInteractive\PayshopSdk\Api\Payments\WalletPayment;
use RwInteractive\PayshopSdk\Enums\Payment\Wallet;
use RwInteractive\PayshopSdk\Exceptions\Api\ApiError;
use RwInteractive\PayshopSdk\Exceptions\Api\CreditCardValidationRequired;
use Tests\TestCase;

/**
 * Pagamento de uma ordem com Apple Pay ou Google Pay (`POST /payment/wallet`).
 *
 * Numa app é o único caminho possível: a documentação do PaynoPain diz que o
 * botão das carteiras não se pode mostrar num webview, por isso é a app que
 * pede o payload ao sistema operativo e o backend que paga a ordem com ele.
 *
 * ESTE TESTE VIVE AQUI E NÃO NO payshop-sdk de propósito. O SDK é um
 * repositório à parte e não tem CI nenhum — um teste lá dentro nunca correria
 * sozinho. Aqui corre a cada push, e o `vendor/rwi/payshop-sdk` é um symlink
 * para o mesmo código, por isso é o código a sério que está a ser exercitado.
 *
 * Não toca na rede: o que se prende é o que se ENVIA à API de pagamentos e o
 * que se faz com cada resposta. Contra a sandbox não daria para prender o 303
 * nem a recusa, que são justamente os ramos onde um engano custa dinheiro a
 * alguém.
 */
class PagamentoComCarteiraTest extends TestCase
{
    private array $pedidos = [];

    /** Serviço com um cliente HTTP falso, que responde o que se lhe disser. */
    private function servico(array $respostas): WalletPayment
    {
        $stack = HandlerStack::create(new MockHandler($respostas));
        $stack->push(Middleware::history($this->pedidos));

        return (new WalletPayment)->withClient(new Client([
            'handler' => $stack,
            'base_uri' => 'https://api.paylands.com/v1/sandbox/',
        ]));
    }

    private function corpoEnviado(): array
    {
        return json_decode((string) $this->pedidos[0]['request']->getBody(), true);
    }

    private function ordemPaga(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'message' => 'OK',
            'code' => 200,
            'order' => [
                'uuid' => '91016708-967B-4B58-AD6B-94776C9F7220',
                'amount' => 5333,
                // O que interessa à Piquet: a ordem cativa agora e cobra-se
                // depois. Se as carteiras só permitissem captura imediata,
                // isto mudava o produto, não só o código.
                'operative' => 'AUTHORIZATION',
                'paid' => false,
                'status' => 'SUCCESS',
            ],
        ]));
    }

    public function test_manda_a_ordem_a_carteira_e_o_payload(): void
    {
        $servico = $this->servico([$this->ordemPaga()]);

        $servico->pay(
            'A6E3F0C2-1111-2222-3333-444455556666',
            Wallet::APPLE_PAY,
            ['paymentData' => ['data' => 'cifrado']],
        );

        $this->assertStringContainsString('payment/wallet', (string) $this->pedidos[0]['request']->getUri());

        $corpo = $this->corpoEnviado();
        $this->assertSame('A6E3F0C2-1111-2222-3333-444455556666', $corpo['order_uuid']);
        $this->assertSame('APPLEPAY', $corpo['wallet']);
        $this->assertSame(['paymentData' => ['data' => 'cifrado']], $corpo['payload']);
    }

    public function test_assina_o_pedido_como_o_resto_do_sdk(): void
    {
        config(['payshop-sdk.api.signature' => 'assinatura-de-teste']);

        $this->servico([$this->ordemPaga()])->pay('uuid', Wallet::APPLE_PAY, ['x' => 1]);

        $this->assertSame('assinatura-de-teste', $this->corpoEnviado()['signature']);
    }

    public function test_devolve_a_ordem_quando_o_pagamento_passa(): void
    {
        $ordem = $this->servico([$this->ordemPaga()])->pay('uuid', Wallet::APPLE_PAY, ['x' => 1]);

        $this->assertSame('91016708-967B-4B58-AD6B-94776C9F7220', $ordem['uuid']);
        $this->assertSame('AUTHORIZATION', $ordem['operative']);
    }

    public function test_so_manda_o_customer_ip_quando_o_recebe(): void
    {
        $this->servico([$this->ordemPaga()])->pay('uuid', Wallet::APPLE_PAY, ['x' => 1]);
        $this->assertArrayNotHasKey('customer_ip', $this->corpoEnviado());

        $this->pedidos = [];
        $this->servico([$this->ordemPaga()])->pay('uuid', Wallet::APPLE_PAY, ['x' => 1], '62.43.214.55');
        $this->assertSame('62.43.214.55', $this->corpoEnviado()['customer_ip']);
    }

    public function test_um_303_do_google_pay_pede_3ds_com_o_url_do_desafio(): void
    {
        // O 303 não é uma falha: é o banco a pedir autenticação. Vem com o
        // mesmo formato do pagamento com cartão (`details` traz o URL), e é
        // por isso que se lança a MESMA exceção — a app reaproveita a máquina
        // de 3DS que já tem em vez de ganhar um segundo caminho para manter.
        $servico = $this->servico([
            new Response(303, ['Content-Type' => 'application/json'], json_encode([
                'code' => 303,
                'details' => 'https://api.paylands.com/v1/payment/tokenized/082a593d',
                'message' => 'See Other',
            ])),
        ]);

        try {
            $servico->pay('uuid', Wallet::GOOGLE_PAY, ['x' => 1]);
            $this->fail('Um 303 tem de interromper o fluxo com o URL do desafio.');
        } catch (CreditCardValidationRequired $e) {
            $this->assertSame('https://api.paylands.com/v1/payment/tokenized/082a593d', $e->getUrl());
        }
    }

    public function test_leva_a_mensagem_da_api_quando_o_pagamento_e_recusado(): void
    {
        // Sem isto a app dizia "ocorreu um erro" a um cartão recusado, a um
        // payload mal formado e a uma carteira por configurar — três conversas
        // diferentes com o cliente.
        $servico = $this->servico([
            new Response(400, ['Content-Type' => 'application/json'], json_encode([
                'code' => 400,
                'message' => 'Invalid wallet payload',
            ])),
        ]);

        $this->expectException(ApiError::class);
        $this->expectExceptionMessage('Invalid wallet payload');

        $servico->pay('uuid', Wallet::GOOGLE_PAY, ['x' => 1]);
    }

    public function test_sabe_qual_das_carteiras_pode_exigir_3ds(): void
    {
        $this->assertTrue(Wallet::GOOGLE_PAY->podeExigir3ds());
        $this->assertFalse(Wallet::APPLE_PAY->podeExigir3ds(), 'O Apple Pay conta como pagamento seguro.');
    }
}
