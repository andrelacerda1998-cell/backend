Boa tarde,

Obrigado pelo exemplo — foi o que permitiu encontrar o problema. Tinham razão:
o payload estava incorreto. Encontrámos **três** diferenças face ao exemplo que
enviaram e corrigimo-las todas.

## O que estava errado do nosso lado

**1. Faltava o envelope.** Estávamos a enviar apenas o conteúdo de
`paymentMethodData.tokenizationData.token`, em vez do objeto `PaymentData`
completo. A biblioteca que usamos na app expõe um campo com nome enganador
(`androidPayToken`) que contém só o token, não o objeto que o Google devolve.

**2. O token ia reformatado.** Esta foi a mais subtil, e agradecemos que o
vosso exemplo a tenha exposto. A mesma biblioteca faz `JSON.parse` dos campos
`signedMessage` e `intermediateSigningKey.signedKey` e devolve-os como objetos.
Como a assinatura do Google é calculada sobre aquelas strings exactas, voltar a
serializá-las produzia bytes diferentes — e a verificação teria falhado do vosso
lado mesmo com o envelope correto. Passámos a usar a string original intacta.

**3. O payload ia como objeto, não como string.** No vosso exemplo o campo é
`"payload": "{...}"`, ou seja um JSON serializado dentro de uma string. Nós
enviávamo-lo aninhado no corpo do pedido. Corrigido, e a serializar sem escapar
as barras (`/` e não `\/`), uma vez que o token traz base64 com barras.

## O que enviamos agora

```
{
  "order_uuid": "...",
  "wallet": "GOOGLEPAY",
  "payload": "{\"apiVersion\":2,\"apiVersionMinor\":0,\"paymentMethodData\":{\"description\":\"VISA •••• 4000\",\"info\":{\"billingAddress\":{...},\"cardDetails\":\"4000\",\"cardNetwork\":\"VISA\"},\"tokenizationData\":{\"token\":\"<a string original do Google, intacta>\",\"type\":\"PAYMENT_GATEWAY\"},\"type\":\"CARD\"}}"
}
```

**Podem confirmar que é este o formato que esperam?** Se houver algum campo
obrigatório que não estejamos a preencher, agradecemos que o indiquem.

## O que continua a bloquear-nos

Na vossa mensagem indicam que "o valor é fixo e igual para todos os
comerciantes", mas não nos indicam **qual é esse valor**.

Assumimos que se refere ao **`gatewayMerchantId`** que o Google Pay exige na
configuração do gateway (a par de `gateway: "paynopain"`). Não queremos
adivinhá-lo: hoje temos esse campo com um valor deliberadamente inválido, porque
um valor plausível mas errado passaria despercebido na app e só falharia do
vosso lado, depois de o cliente já ter autenticado o pagamento.

**Pedimos que nos indiquem, em texto, o valor literal a usar em:**

1. `gatewayMerchantId` — para a configuração do Google Pay;
2. `merchant id` da **Apple Pay** — para registar nas credenciais da app.

Sem estes dois, as carteiras ficam paradas do nosso lado, mesmo com o payload
corrigido.

## A seguir

Assim que tivermos o `gatewayMerchantId`, fazemos um pagamento real com Google
Pay no sandbox e confirmamos convosco se a resposta passa a trazer o
`validation_hash` preenchido. Até lá, a correção está feita mas não está
provada contra o vosso ambiente — e não a damos por fechada sem isso.

Obrigado,
André Lacerda
Piquet
