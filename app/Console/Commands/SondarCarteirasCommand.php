<?php

namespace App\Console\Commands;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Console\Command;

/**
 * Responde a UMA pergunta contra a sandbox do Payshop: o Apple Pay e o Google
 * Pay estão disponíveis na nossa conta, e aceitam cativar agora para cobrar
 * depois?
 *
 * A segunda metade é a que decide se isto avança. O modelo da Piquet assenta
 * em `AUTHORIZATION`: cativa-se no checkout e cobra-se quando o serviço fecha.
 * Se as carteiras só permitirem captura imediata, muda o produto e não só o
 * código — e é melhor saber isso antes de haver um botão para carregar.
 *
 * NÃO é preciso iPhone nem Android. A sonda manda um payload deliberadamente
 * inválido e lê a RECUSA, que é o que distingue os dois casos:
 *
 *   - "carteira não configurada / serviço inexistente" -> a conta não tem as
 *     wallets ativas. Bloqueado no Payshop, não em nós;
 *   - "payload inválido / erro a decifrar" -> a carteira ESTÁ ativa e o pedido
 *     chegou ao ponto de tentar decifrar o token. É a boa notícia.
 *
 * Só corre em sandbox. Um pedido destes contra produção criaria uma ordem a
 * sério, e não há nada aqui que valha isso.
 */
class SondarCarteirasCommand extends Command
{
    protected $signature = 'payshop:sondar-carteiras';

    protected $description = 'Verifica, na sandbox do Payshop, se as carteiras estão ativas e se aceitam autorização diferida';

    public function handle(): int
    {
        if (config('payshop-sdk.environment') !== 'sandbox') {
            $this->error('Isto só corre em sandbox. PAYSHOP_SDK_ENVIRONMENT='.config('payshop-sdk.environment'));

            return self::FAILURE;
        }

        $emFalta = collect([
            'PAYSHOP_SDK_API_KEY' => config('payshop-sdk.api.key'),
            'PAYSHOP_SDK_API_SIGNATURE' => config('payshop-sdk.api.signature'),
            'PAYSHOP_SDK_CLIENT_UUID' => config('payshop-sdk.client.uuid'),
            'PAYSHOP_SDK_CREDIT_CARD_SERVICE_UUID' => config('payshop-sdk.paymentServices.creditCard'),
        ])->filter(fn ($v) => blank($v))->keys();

        if ($emFalta->isNotEmpty()) {
            $this->error('Faltam credenciais de sandbox no .env:');
            $emFalta->each(fn ($k) => $this->line("  - {$k}"));
            $this->newLine();
            $this->line('Põe os valores no .env do backend. Não os escrevas aqui nem num commit.');

            return self::FAILURE;
        }

        $http = new Client([
            'base_uri' => config('payshop-sdk.api_endpoint.sandbox'),
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Basic '.base64_encode(config('payshop-sdk.api.key')),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'http_errors' => false,
            'allow_redirects' => false,
        ]);

        $assinatura = config('payshop-sdk.api.signature');

        // UMA ORDEM POR CARTEIRA.
        //
        // A primeira versão criava uma ordem só e usava-a nas duas tentativas.
        // A primeira tentativa consome-a — o pagamento falha e a ordem fecha —
        // e a segunda recebia `404 Order token not found`, que se lia como "a
        // carteira não existe" quando era a ordem que já não servia. Deu um
        // diagnóstico errado à primeira vez que isto correu.
        foreach (['APPLEPAY', 'GOOGLEPAY'] as $carteira) {
            $this->info("A criar uma ordem de 1 cêntimo (AUTHORIZATION) para o {$carteira}...");

            $ordem = $this->pedir($http, 'payment', [
                'signature' => $assinatura,
                'operative' => 'AUTHORIZATION',
                'amount' => 1,
                'description' => 'Sonda de carteiras (Piquet)',
                'customer_ext_id' => 'sonda-carteiras',
                'secure' => false,
                'service' => config('payshop-sdk.paymentServices.creditCard'),
                'expires_in' => 900,
            ]);

            $uuid = data_get($ordem, 'corpo.order.uuid');

            if (! $uuid) {
                $this->linha('ordem', $ordem);
                $this->error('   Sem ordem não há nada a sondar para esta carteira.');
                $this->newLine();

                continue;
            }

            $this->line('   ordem: '.$uuid);
            $this->info('   A tentar /payment/wallet com um payload inválido...');

            $resposta = $this->pedir($http, 'payment/wallet', [
                'signature' => $assinatura,
                'order_uuid' => $uuid,
                'wallet' => $carteira,
                'payload' => ['sonda' => 'payload propositadamente invalido'],
            ]);

            $this->linha($carteira, $resposta);
            $this->newLine();
        }

        $this->newLine();
        $this->line('COMO LER ISTO:');
        $this->line('  - HTTP 303 com um URL .../payment/wrong  -> A CARTEIRA ESTÁ ATIVA. O Paylands aceitou');
        $this->line('    o pedido, tentou processar o payload (que é lixo de propósito) e mandou para o');
        $this->line('    callback de falha. É o que se quer ver aqui.');
        $this->line('  - erro a falar de serviço ou configuração -> a carteira não está ativa na conta.');
        $this->line('  - HTTP 401 -> as credenciais do .env não servem.');
        $this->line('  - a ordem acima criou-se com AUTHORIZATION -> cativar agora e cobrar depois é aceite pela ordem.');
        $this->line('    Isso ainda não prova a captura diferida DA CARTEIRA: para isso é preciso um payload a sério,');
        $this->line('    de um telemóvel, e depois um /payment/order/confirmation. Fica para o teste na app.');

        return self::SUCCESS;
    }

    private function pedir(Client $http, string $endpoint, array $corpo): array
    {
        try {
            $resposta = $http->post($endpoint, ['json' => $corpo]);

            return [
                'status' => $resposta->getStatusCode(),
                'corpo' => json_decode((string) $resposta->getBody(), true),
            ];
        } catch (ClientException $e) {
            return [
                'status' => $e->getResponse()->getStatusCode(),
                'corpo' => json_decode((string) $e->getResponse()->getBody(), true),
            ];
        } catch (\Throwable $e) {
            return ['status' => 0, 'corpo' => ['message' => $e->getMessage()]];
        }
    }

    private function linha(string $rotulo, array $resposta): void
    {
        $mensagem = data_get($resposta, 'corpo.message')
            ?? data_get($resposta, 'corpo.details')
            ?? json_encode($resposta['corpo']);

        $this->line(sprintf('   %-10s HTTP %s — %s', $rotulo, $resposta['status'], $mensagem));
    }
}
