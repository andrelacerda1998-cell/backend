<?php

namespace App\Services\InvoiceXpress\Contracts;

use App\Models\User;
use App\Models\User\UserBillingInfo;
use Carbon\Carbon;

trait HasInvoicesItems
{
    private const CURRENCY_CODE = 'EUR';
    private const UNIT_TYPE = 'service';

    /** O nome na fatura de quem não tem nome nenhum (decisão do André, 13/10/2026). */
    private const CONSUMIDOR_FINAL = 'Consumidor final';

    private function calculateVatRate(): float
    {
        $vat = config('services.invoiceExpress.vat');
        return 1 + ($vat / 100);
    }

    private function generateInvoicePayload(Carbon $date, Carbon $dueDate, $serviceId, array $customer, array $items): array
    {
        return [
            'invoice' => [
                "date" => $date->format('d-m-Y'),
                "due_date" => $dueDate->format('d-m-Y'),
                "reference" => $serviceId,
                "client" => $customer,
                "items" => $items,
                "currency_code" => self::CURRENCY_CODE,
                "rate" => strval($this->calculateVatRate())
            ]
        ];
    }

    private function prepareClientData($name, $email, $fiscalId = null, array $address = null): array
    {
        return [
            "name" => $name,
            "email" => $email,
            'fiscal_id' => !$fiscalId ?999999990: $fiscalId,

            "address" => $address['address']??'',
            "city" => $address['locality']??'',
            "postal_code" => $address['postal_code']??'',
            "country" => "Portugal",
        ];
    }

    /**
     * O cliente de uma fatura, a partir do utilizador.
     *
     * DADOS DE FATURAÇÃO PRIMEIRO (decisão do André, 13/10/2026): se o cliente
     * preencheu nome e NIF nos dados de faturação, a fatura leva-os. Até aqui só
     * a morada vinha de lá: o nome era o da conta e o NIF o do pedido ou o do
     * perfil.
     *
     * NIF: o do pedido (a app pré-preenche-o com o dos dados de faturação e o
     * cliente pode trocá-lo no checkout); senão o dos dados de faturação; senão
     * o do perfil; senão o de consumidor final.
     *
     * NOME: o dos dados de faturação, que vai com o NIF deles; se no pedido o
     * cliente escolheu outro NIF, é a pessoa e não a entidade dos dados de
     * faturação, e vai o nome dela. Depois o nome completo; sem ele, o primeiro
     * nome; sem nenhum, "Consumidor final". `users.name` é uma coluna calculada,
     * CONCAT(first_name, " ", last_name), e fica vazia quando falta um dos dois:
     * o InvoiceXpress recusava a fatura ("Elemento client não fornecido").
     *
     * CÓDIGO: sem código, o InvoiceXpress reconhece um cliente que já existe
     * pelo NOME e ignora o resto dos campos (visto a 13/10/2026 na conta de
     * testes: dois rascunhos com o mesmo nome e moradas diferentes ficaram os
     * dois com a morada do primeiro). Dois clientes com o mesmo nome -- ou todos
     * os "Consumidor final" -- saíam com o NIF e a morada de quem foi faturado
     * primeiro. O código é o id do utilizador mais uma impressão dos dados: quem
     * muda de NIF ou de morada passa a ser outro cliente no InvoiceXpress, em
     * vez de ficar com os dados antigos.
     *
     * @param  string|int|null  $nifDoPedido  O NIF escolhido para este serviço (`services.nif`).
     */
    private function clienteDaFatura(User $user, $nifDoPedido, ?array $morada): array
    {
        $faturacao = $user->billingInfo;
        $nifDaFaturacao = trim((string) ($faturacao?->nif ?? ''));
        $nifDoPedido = trim((string) ($nifDoPedido ?? ''));

        $nomeDaFaturacao = ($nifDoPedido === '' || $nifDaFaturacao === '' || $nifDoPedido === $nifDaFaturacao)
            ? trim((string) ($faturacao?->name ?? ''))
            : '';

        $nome = $nomeDaFaturacao
            ?: trim((string) $user->name)
            ?: trim((string) $user->first_name)
            ?: self::CONSUMIDOR_FINAL;

        $nif = $nifDoPedido ?: $nifDaFaturacao ?: $user->nif;

        $cliente = $this->prepareClientData($nome, $user->email, $nif, $morada);

        $cliente['code'] = 'PQ'.$user->getKey().'-'.substr(sha1((string) json_encode([
            $cliente['name'], $cliente['email'], $cliente['fiscal_id'],
            $cliente['address'], $cliente['postal_code'], $cliente['city'],
        ])), 0, 8);

        return $cliente;
    }

    public function item($itemDescription, $itemPrice)
    {
        return [
            "name" => config('services.invoiceExpress.product_name'),
            "description" => $itemDescription,
            "unit_price" => ($itemPrice / 100) / $this->calculateVatRate(),
            "quantity" => 1,
            "unit" => self::UNIT_TYPE
        ];
    }
}
