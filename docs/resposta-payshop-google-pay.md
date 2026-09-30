Boa tarde,

Obrigado pelo exemplo — foi o que permitiu encontrar o problema. Tinham razão:
o payload estava incorreto. Comparámos campo a campo com o vosso exemplo,
encontrámos três diferenças e corrigimo-las.

Ficam abaixo, primeiro o que corrigimos, depois quatro pontos em que precisamos
de vocês.

## O que estava errado do nosso lado

**1. Faltava o envelope.** Enviávamos apenas o conteúdo de
`paymentMethodData.tokenizationData.token` em vez do objeto `PaymentData`
completo. A biblioteca que usamos na app expõe um campo com nome enganador
(`androidPayToken`) que contém só o token.

**2. O token ia reformatado.** A mesma biblioteca faz `JSON.parse` dos campos
`signedMessage` e `intermediateSigningKey.signedKey` e devolve-os como objetos.
Como a assinatura do Google é calculada sobre aquelas strings exactas, voltar a
serializá-las produz bytes diferentes — a verificação falharia do vosso lado
mesmo com o envelope correto, e por uma razão muito menos visível do que esta.
Passámos a enviar a string original intacta.

**3. O payload ia como objeto, não como string.** No vosso exemplo o campo é
`"payload": "{...}"`. Nós enviávamo-lo aninhado no corpo do pedido. Corrigido, e
a serializar sem escapar as barras (`/` e não `\/`), dado que o token traz
base64 com barras.

Confirmámos também o que **não** estava errado, para não vos fazer perder tempo:
o `signature` no corpo do pedido já era enviado, e o campo `wallet` já ia como
`"GOOGLEPAY"`, tal como no vosso exemplo.

## O que enviamos agora

```
{
  "signature": "<a nossa>",
  "order_uuid": "...",
  "wallet": "GOOGLEPAY",
  "payload": "{\"apiVersion\":2,\"apiVersionMinor\":0,\"paymentMethodData\":{\"description\":\"VISA •••• 4000\",\"info\":{\"billingAddress\":{...},\"cardDetails\":\"4000\",\"cardNetwork\":\"VISA\"},\"tokenizationData\":{\"token\":\"<string original do Google, intacta>\",\"type\":\"PAYMENT_GATEWAY\"},\"type\":\"CARD\"}}"
}
```

## Quatro pontos em que precisamos de vocês

**1. O valor que dizem ser fixo — qual é?**

Escrevem que "o valor é fixo e igual para todos os comerciantes", mas não nos
indicam qual. Assumimos que se refere ao **`gatewayMerchantId`** que o Google Pay
exige (a par de `gateway: "paynopain"`), uma vez que era o que perguntávamos.

Não o vamos adivinhar. Hoje temos esse campo com um valor deliberadamente
inválido, porque um valor plausível mas errado passaria despercebido na app e só
falharia do vosso lado — depois de o cliente já ter autenticado o pagamento.

Pedimos o valor literal, em texto, de:
- `gatewayMerchantId`, para a configuração do Google Pay;
- `merchant id` da **Apple Pay**, para registar nas credenciais da app.

**2. `cardFundingSource` é obrigatório?**

O vosso exemplo traz `"cardFundingSource": "DEBIT"` dentro de
`paymentMethodData.info`. Esse campo **não existe** no objeto `CardInfo`
documentado pelo Google, que devolve `cardNetwork`, `cardDetails`,
`assuranceDetails` e `billingAddress`. Não temos de onde o preencher.

É obrigatório? Se for, de que campo do Google o devemos derivar — do `cardClass`?

**3. O vosso sandbox aceita tokens de ambiente TEST?**

Em `environment: TEST`, o Google Pay não devolve um token real: devolve a string
literal `examplePaymentMethodToken`. Um token desses não é decifrável.

Precisamos de saber se o vosso sandbox aceita pagamentos com tokens TEST, ou se
temos de usar `environment: PRODUCTION` contra o sandbox. Isto condiciona se
conseguimos testar antes da aprovação da conta no Google Pay Business Console.

**4. Ficaram por responder quatro pontos da mensagem anterior.**

Não os repetimos por insistência — é que sem eles não conseguimos fechar a
validação de assinaturas nem cobrir os casos de erro:

- o conjunto de campos excluídos do cálculo do `validation_hash`, a ordem de
  serialização e a codificação (os pontos 1 a 3 da mensagem anterior);
- um PAN de teste para **autorização recusada** com 3DS bem-sucedido — é o
  desfecho mais frequente em produção e não o conseguimos exercitar;
- a identificação do terceiro serviço no nosso sandbox,
  `A33E…8214 — "SANDBOX-PAYLANDS" (PLD)`, e se é esse que deve ser usado no
  `payment/wallet`.

## O estado real do nosso lado

A correção está feita e testada internamente, mas **não está provada contra o
vosso ambiente** — e não a damos por fechada sem isso. Assim que tivermos o
`gatewayMerchantId` e a resposta ao ponto 3, fazemos um pagamento com Google Pay
e confirmamos convosco se a resposta passa a trazer o `validation_hash`
preenchido.

Obrigado,
André Lacerda
Piquet
