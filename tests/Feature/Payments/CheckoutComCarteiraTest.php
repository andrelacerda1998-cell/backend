<?php

namespace Tests\Feature\Payments;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\Service;
use App\Models\User;
use App\Trait\Services\ProcessesServicePayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RwInteractive\PayshopSdk\Api\Payments\WalletPayment;
use RwInteractive\PayshopSdk\Enums\Payment\OperationType;
use RwInteractive\PayshopSdk\Enums\Payment\Status;
use RwInteractive\PayshopSdk\Enums\Payment\Wallet;
use RwInteractive\PayshopSdk\Exceptions\Api\ApiError;
use RwInteractive\PayshopSdk\Exceptions\Api\CreditCardValidationRequired;
use RwInteractive\PayshopSdk\Models\PaymentOrder;
use Tests\TestCase;

/**
 * Cobrar um serviço com Apple Pay ou Google Pay.
 *
 * A carteira entra pelo MESMO caminho do cartão — ordem DEFERRED, saldo usado,
 * 3DS, cancelamento da ordem órfã — e é isso que estes testes prendem. Duas
 * implementações de cobrança divergem em silêncio, e divergir aqui significa
 * cobrar mal a alguém.
 *
 * O que NÃO está aqui, e continua por provar: que o Payshop honre o DEFERRED
 * numa carteira, ou seja, cativar agora e capturar quando o serviço fecha.
 * Isso precisa de um payload a sério, de um telemóvel. Estes testes provam que
 * NÓS pedimos o diferimento; não que ele seja respeitado.
 */
class CheckoutComCarteiraTest extends TestCase
{
    use RefreshDatabase;

    /** Regista o que lhe foi pedido, ou rebenta como a API rebentaria. */
    private function carteiraFalsa(?\Throwable $rebenta = null): WalletPayment
    {
        $falsa = new class($rebenta) extends WalletPayment
        {
            public array $recebido = [];

            public function __construct(private ?\Throwable $rebenta) {}

            public function pay(string $orderUuid, Wallet $wallet, array|string $payload, ?string $customerIp = null): array
            {
                $this->recebido = compact('orderUuid', 'wallet', 'payload', 'customerIp');

                if ($this->rebenta) {
                    throw $this->rebenta;
                }

                return ['uuid' => $orderUuid, 'operative' => 'AUTHORIZATION', 'status' => 'SUCCESS'];
            }
        };

        $this->app->instance(WalletPayment::class, $falsa);

        return $falsa;
    }

    /**
     * Cliente reduzido ao que este caminho lhe pede: criar a ordem e descontar
     * saldo. Um User a sério faria uma chamada ao Payshop para criar a ordem —
     * que é exactamente o que não se quer num teste.
     */
    private function clienteFalso(): object
    {
        return new class
        {
            public array $ordensCriadas = [];

            public int $levantado = 0;

            public function createPaymentOrder(...$args): PaymentOrder
            {
                $this->ordensCriadas[] = $args;

                // Uma linha a sério: o `services.payment_order_id` tem chave
                // estrangeira para esta tabela, e um id inventado não passa.
                return PaymentOrderEspiada::create([
                    // Um utilizador a sério: a ordem tem chave estrangeira para
                    // `users`, e o id 1 só existe no primeiro teste da classe.
                    'user_id' => User::factory()->create()->id,
                    'uuid' => '1F405EA3-9798-42A6-9E87-BD347EF67F55',
                    'amount' => 5333,
                    'paid' => false,
                    'status' => Status::CREATED,
                    'type' => OperationType::DEFERRED,
                    // A tabela do SDK não tem defaults: todas estas colunas são
                    // NOT NULL sem valor por omissão.
                    'refunded' => 0,
                    'service' => 'CREDORAX',
                    'service_uuid' => '9A1BDCC8-DB30-4ED2-8523-62B330A67873',
                    'token' => '',
                    'ip' => '',
                ]);
            }

            public function withdraw(int $quanto): void
            {
                $this->levantado += $quanto;
            }
        };
    }

    /** Uma classe qualquer que use o trait — é o trait que está em teste. */
    private function cobrador(): object
    {
        return new class
        {
            use ProcessesServicePayment {
                processWalletPayment as public;
            }
        };
    }

    private function totais(int $aPagar = 5333, int $saldo = 0): array
    {
        return [
            'balance' => $saldo,
            'balance_total_used' => $saldo,
            'value_for_payment' => $aPagar,
        ];
    }

    /**
     * Uma string ja pronta passa intacta.
     *
     * E o caso real do Apple Pay. Codifica-la outra vez dava um JSON dentro de
     * um JSON, e o Payshop recusaria -- o mesmo erro que acabamos de corrigir,
     * so que ao contrario.
     */
    public function test_um_payload_que_ja_e_string_nao_e_codificado_outra_vez(): void
    {
        $falsa = $this->carteiraFalsa();
        $cru = '{"data":"abc","version":"EC_v1"}';

        $this->cobrador()->processWalletPayment(
            $this->clienteFalso(),
            Service::factory()->create(),
            null,
            $this->totais(),
            Wallet::APPLE_PAY,
            $cru,
            '62.43.214.55',
        );

        $this->assertSame($cru, $falsa->recebido['payload']);
    }

    public function test_paga_a_ordem_com_o_payload_da_carteira(): void
    {
        $falsa = $this->carteiraFalsa();
        $servico = Service::factory()->create();

        $url = $this->cobrador()->processWalletPayment(
            $this->clienteFalso(),
            $servico,
            null,
            $this->totais(),
            Wallet::APPLE_PAY,
            ['paymentData' => ['data' => 'cifrado']],
            '62.43.214.55',
        );

        $this->assertNull($url, 'O Apple Pay não pede 3DS.');
        $this->assertSame('1F405EA3-9798-42A6-9E87-BD347EF67F55', $falsa->recebido['orderUuid']);
        $this->assertSame(Wallet::APPLE_PAY, $falsa->recebido['wallet']);
        // O Payshop quer o payload como STRING, nao como objeto aninhado (resposta
        // deles de 30/09). Este teste fixava o formato antigo.
        $this->assertSame('{"paymentData":{"data":"cifrado"}}', $falsa->recebido['payload']);
        $this->assertSame('62.43.214.55', $falsa->recebido['customerIp']);

        $this->assertSame(PaymentStatus::PAID, $servico->fresh()->payment_status);
        $this->assertNotNull($servico->fresh()->payment_order_id, 'A ordem tem de ficar presa ao serviço.');
    }

    public function test_pede_a_ordem_em_diferido(): void
    {
        // Cativar agora e cobrar quando o serviço fecha é o modelo inteiro da
        // Piquet. Se alguém trocar isto por uma captura imediata, o dinheiro
        // sai antes de haver trabalho feito.
        $cliente = $this->clienteFalso();
        $this->carteiraFalsa();

        $this->cobrador()->processWalletPayment(
            $cliente, Service::factory()->create(), null, $this->totais(),
            Wallet::APPLE_PAY, ['x' => 1],
        );

        $this->assertSame(OperationType::DEFERRED, $cliente->ordensCriadas[0][0]);
        $this->assertSame(5333, $cliente->ordensCriadas[0][1]);
    }

    public function test_um_303_do_google_pay_poe_o_servico_em_3ds(): void
    {
        // Mesmo estado do cartão, de propósito: a app já sabe abrir este URL e
        // voltar a perguntar pelo pagamento quando o cliente regressa do banco.
        $this->carteiraFalsa(new CreditCardValidationRequired('https://api.paylands.com/v1/payment/tokenized/abc'));
        $servico = Service::factory()->create();

        $url = $this->cobrador()->processWalletPayment(
            $this->clienteFalso(), $servico, null, $this->totais(),
            Wallet::GOOGLE_PAY, ['x' => 1],
        );

        $this->assertSame('https://api.paylands.com/v1/payment/tokenized/abc', $url);
        $this->assertSame(PaymentStatus::PENDING, $servico->fresh()->payment_status);
        $this->assertSame(ServiceStatus::PENDING_3DS, $servico->fresh()->status);
    }

    public function test_uma_recusa_cancela_a_ordem_e_re_lanca(): void
    {
        // A ordem remota já existe quando o pagamento falha. Sem o cancelamento
        // ficava uma autorização pendurada no cartão de alguém.
        PaymentOrderEspiada::$canceladas = [];
        $this->carteiraFalsa(new ApiError('Invalid wallet payload', 400));
        $cliente = $this->clienteFalso();
        $servico = Service::factory()->create();

        try {
            $this->cobrador()->processWalletPayment(
                $cliente, $servico, null, $this->totais(),
                Wallet::GOOGLE_PAY, ['x' => 1],
            );
            $this->fail('Uma recusa tem de rebentar para o checkout fazer rollback.');
        } catch (ApiError $e) {
            $this->assertSame('Invalid wallet payload', $e->getMessage());
        }

        $this->assertSame(
            ['1F405EA3-9798-42A6-9E87-BD347EF67F55'],
            PaymentOrderEspiada::$canceladas,
            'A ordem remota tem de ser cancelada — senão fica uma autorização pendurada.',
        );
    }

    public function test_usa_o_saldo_do_cliente_antes_de_cobrar(): void
    {
        $this->carteiraFalsa();
        $cliente = $this->clienteFalso();

        $this->cobrador()->processWalletPayment(
            $cliente, Service::factory()->create(), null, $this->totais(aPagar: 3000, saldo: 500),
            Wallet::APPLE_PAY, ['x' => 1],
        );

        $this->assertSame(500, $cliente->levantado);
    }

    public function test_sem_nada_a_pagar_nao_fala_com_a_carteira(): void
    {
        // Um serviço inteiramente coberto por saldo ou cupão. Criar uma ordem
        // de zero euros seria pedir ao Payshop uma cobrança que não existe.
        $falsa = $this->carteiraFalsa();
        $cliente = $this->clienteFalso();
        $servico = Service::factory()->create();

        $url = $this->cobrador()->processWalletPayment(
            $cliente, $servico, null, $this->totais(aPagar: 0),
            Wallet::APPLE_PAY, ['x' => 1],
        );

        $this->assertNull($url);
        $this->assertSame([], $falsa->recebido);
        $this->assertSame([], $cliente->ordensCriadas);
        $this->assertSame(PaymentStatus::PAID, $servico->fresh()->payment_status);
    }
}

/**
 * Ordem de pagamento que não fala com o Payshop.
 *
 * O `cancel()` do SDK manda um pedido à API. Aqui só se quer saber se o
 * caminho de erro o chamou — falar com o Payshop num teste seria cobrar (ou
 * cancelar) a sério.
 */
class PaymentOrderEspiada extends PaymentOrder
{
    protected $table = 'payshop_payments_orders';

    public static array $canceladas = [];

    public function cancel(): void
    {
        static::$canceladas[] = $this->uuid;
    }
}
