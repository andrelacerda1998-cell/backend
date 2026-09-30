# Resposta ao Payshop — Google Pay (enviada a 30/09/2026)

Este ficheiro é o que foi ENVIADO, palavra por palavra. Houve um rascunho mais
longo, com cinco pedidos e a explicação detalhada de cada correção; ficou em
git (commit `85a1fc9`) e não foi enviado. Guarda-se a versão real para que
daqui a semanas ninguém confunda o que foi pedido com o que ficou por pedir.

O que foi cortado face ao rascunho, e que continua por perguntar ao Payshop:

- se o `cardFundingSource` do exemplo deles é obrigatório (não existe no
  `CardInfo` documentado pelo Google, não temos de onde o preencher);
- o detalhe do cálculo do `validation_hash` — campos excluídos, ordem de
  serialização, codificação — que vai abaixo numa linha só, sem detalhe;
- a identificação do terceiro serviço do sandbox, `A33E…8214`.

---

Boa tarde,

Obrigado pelo exemplo — foi com ele que encontrámos o problema. Tinham razão,
o payload estava errado, e já está corrigido:

1. **Faltava o envelope** — enviávamos só o `token` em vez do objeto
   `PaymentData` completo.
2. **O token ia reformatado** — a biblioteca que usamos fazia `JSON.parse` do
   `signedMessage`, o que quebra a assinatura do Google. Passámos a enviar a
   string original intacta.
3. **O `payload` ia como objeto** — agora vai como string, e sem escapar as
   barras.

É este o formato que enviamos agora:

```
{
  "signature": "<a nossa>",
  "order_uuid": "...",
  "wallet": "GOOGLEPAY",
  "payload": "{\"apiVersion\":2,...,\"tokenizationData\":{\"token\":\"<string original do Google>\",\"type\":\"PAYMENT_GATEWAY\"},\"type\":\"CARD\"}"
}
```

**Precisamos de três coisas para fechar isto:**

**1. Os identificadores.** Dizem que "o valor é fixo e igual para todos os
comerciantes", mas não indicam qual. Precisamos do valor literal do
`gatewayMerchantId` (Google Pay) e do `merchant id` da Apple Pay. Sem eles as
carteiras ficam paradas, e não os queremos adivinhar.

**2. O `payment/wallet` devolve `validation_hash`?** O sintoma que reportámos
foi a ausência desse campo. Assumimos que era do payload — mas se aquele
endpoint simplesmente não assina as respostas, a nossa correção não muda nada.
Uma resposta sim/não poupa-nos outra ronda.

**3. Como testamos?** Em `environment: TEST` a biblioteca devolve o token
**vazio** — não conseguimos sequer construir um payload. O vosso sandbox aceita
tokens TEST, ou devemos usar `PRODUCTION` contra ele?

Continuam também por responder os pontos sobre o cálculo do `validation_hash`
da mensagem anterior, e o PAN de teste para autorização recusada.

Assim que tivermos o `gatewayMerchantId`, fazemos um pagamento real e
confirmamos convosco.

Obrigado,
André Lacerda
Piquet Technologies Lda
