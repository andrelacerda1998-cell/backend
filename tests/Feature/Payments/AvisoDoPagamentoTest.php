<?php

namespace Tests\Feature\Payments;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use RwInteractive\PayshopSdk\Api\Payments\PaymentOrder;
use RwInteractive\PayshopSdk\Enums\Payment\OperationType;
use Tests\TestCase;

/**
 * O aviso servidor-a-servidor do Paylands (`url_post`).
 *
 * A ordem é criada com o URL a que o Paylands avisa quando ela muda de
 * estado (cativada, cobrada, libertada, devolvida). Sem ele o Paylands não
 * avisa ninguém, e o backoffice só sabia dos pagamentos uma vez por dia.
 * Só segue quando PAYSHOP_SDK_NOTIFICATION_URL está definida: sem a variável
 * a ordem é criada exatamente como antes.
 *
 * Como o PagamentoComCarteiraTest, vive aqui e não no payshop-sdk (que não
 * tem CI): o vendor/rwi/payshop-sdk é o código a sério.
 */
class AvisoDoPagamentoTest extends TestCase
{
    private array $pedidos = [];

    private function ordens(): PaymentOrder
    {
        $resposta = new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'message' => 'OK',
            'code' => 200,
            'order' => ['uuid' => '91016708-967B-4B58-AD6B-94776C9F7220', 'amount' => 4500, 'status' => 'CREATED'],
        ]));
        $stack = HandlerStack::create(new MockHandler([$resposta]));
        $stack->push(Middleware::history($this->pedidos));

        return (new PaymentOrder)->withClient(new Client([
            'handler' => $stack,
            'base_uri' => 'https://api.paylands.com/v1/sandbox/',
        ]));
    }

    private function corpoEnviado(): array
    {
        return json_decode((string) $this->pedidos[0]['request']->getBody(), true);
    }

    private function criarComCartao(): void
    {
        $this->ordens()->createWithCreditCard(OperationType::DEFERRED, 4500, 'Teste', now()->addHour(), 'cliente-1', ['service' => 1]);
    }

    public function test_com_o_url_configurado_a_ordem_pede_aviso(): void
    {
        config(['payshop-sdk.notification_url' => 'https://backoffice.test/api/webhooks/paylands?key=abc']);

        $this->criarComCartao();

        $this->assertSame('https://backoffice.test/api/webhooks/paylands?key=abc', $this->corpoEnviado()['url_post']);
        // O regresso do browser depois do 3DS continua lá.
        $this->assertArrayHasKey('url_ok', $this->corpoEnviado());
    }

    public function test_sem_o_url_a_ordem_e_criada_como_sempre(): void
    {
        config(['payshop-sdk.notification_url' => null]);
        $this->criarComCartao();
        $this->assertArrayNotHasKey('url_post', $this->corpoEnviado());

        $this->pedidos = [];
        config(['payshop-sdk.notification_url' => '']);
        $this->criarComCartao();
        $this->assertArrayNotHasKey('url_post', $this->corpoEnviado());
    }

    public function test_o_mb_way_tambem_pede_aviso_mesmo_sem_regresso_do_browser(): void
    {
        config(['payshop-sdk.notification_url' => 'https://backoffice.test/api/webhooks/paylands?key=abc']);

        $this->ordens()->createWithMbWay(
            OperationType::DEFERRED, 4500, 'Teste', now()->addHour(), 'cliente-1', ['service' => 1],
            '912345678', 'Marta', 'Silva',
        );

        $corpo = $this->corpoEnviado();
        $this->assertSame('https://backoffice.test/api/webhooks/paylands?key=abc', $corpo['url_post']);
        $this->assertArrayNotHasKey('url_ok', $corpo);
    }
}
