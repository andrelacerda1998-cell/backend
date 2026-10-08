# Carteira do cliente

A Carteira tem duas partes. Na app aparecem juntas ("Disponível para serviços") e separadas por baixo.

| Parte | Onde vive | O que é | Expira |
|---|---|---|---|
| **Saldo** | carteira Bavix `default` do `User` | dinheiro do cliente: reembolsos de pedidos cancelados ou recusados | não |
| **Crédito de convites** | carteira Bavix `convites` do `User` (`HasWallets`) | crédito dado pela Piquet | sim (`wallet_credits.expires_at`) |

Tudo passa por `App\Services\Carteira\CarteiraDoCliente`.

## Dinheiro da Piquet
- O crédito de convites entra por **transferência da `system_wallet()`** (`creditarConvites`, com `forceTransfer`). Quando o serviço fecha, o `CloseService::settle()` continua a depositar a comissão inteira na carteira do sistema. A parte paga com crédito já tinha saído de lá, e as contas batem certo.
- O que expira volta à carteira do sistema (`carteira:expirar`, diário às 03:40).

## Pagar
- `buildTransactionTotals` calcula as partes: **primeiro os convites, depois o Saldo**, e o resto vai ao cartão ou MB Way.
  - `balance` e `balance_total_used` continuam a ser o total, para as apps já publicadas.
  - As partes vão em `balance_convites_used` e `balance_saldo_used`.
- No serviço: `credit_used` passa a ter só o Saldo e `referral_credit_used` os convites.
- `ProcessesServicePayment::debitarCarteira`:
  - o Saldo sai por `withdraw` (com `service_id` no meta);
  - os convites saem por `debitarConvites`, que gasta primeiro os créditos que expiram primeiro e regista cada parte em `wallet_credit_usages`.
- Um crédito fora de prazo não se gasta, mesmo antes de a tarefa diária o recolher: o disponível é a soma dos créditos `usable()`.

## Devolver (cancelado, recusado, 3DS expirado)
`devolver($service, $customer, $metaSaldo)` substitui o antigo `deposit($service->credit_used)` no `ServiceObserver`, no `RefuseService` e no `ExpirePending3dsCommand`. As guardas de cada sítio ficaram onde estavam.
- O Saldo volta ao Saldo, com o meta de sempre.
- Os convites voltam à linha de onde saíram, a partir dos usos ainda não devolvidos. Isto torna a devolução idempotente: uma segunda chamada não devolve nada.
- Se o crédito expirou enquanto o serviço estava pendente, essa parte volta à Piquet e não ao cliente.

## App
`GET /api/v1/customer/wallet` devolve:
- o total, as duas partes e `convites_a_expirar`;
- os movimentos das duas carteiras numa só lista paginada, com o texto pronto no idioma do cliente (`resources/lang/*/carteira.php`).

## Por fazer (fases seguintes)
- Convites: códigos, regras e a recompensa, que chama `creditarConvites`.
- Aviso uma semana antes de o crédito expirar.
- Ecrã Carteira na app.
- No backoffice, as duas carteiras na ficha do cliente. Hoje o `WalletRelationManager` só mostra a `default`.
