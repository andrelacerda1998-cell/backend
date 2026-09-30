Boa tarde,

Obrigado — os cartões e os UUIDs resolvem os pontos 1 e 2. Já configurámos os
dois serviços e vamos avançar com os testes.

Sobre o ponto 3, aqui vai o detalhe que pediram.

## O que pretendemos validar

Queremos rejeitar qualquer resposta 2xx cuja assinatura não confira, em vez de
a aceitar. Hoje a verificação já corre, mas em modo permissivo: se a resposta
não trouxer `validation_hash`, registamos um aviso e continuamos. Só queremos
passar a fail-closed depois de confirmarmos convosco que todas as respostas
são assinadas — caso contrário estaríamos a recusar cobranças boas, e do lado
do cliente isso é indistinguível de um pagamento falhado.

## Endpoints onde fazemos a validação

Validamos em todas as respostas 2xx destes endpoints:

- `customer` — criação e actualização do perfil do cliente
- `payment-method/card` — tokenização de cartão
- `payment` — criação, autorização, confirmação, reembolso e cancelamento
- `payment/wallet` — Apple Pay e Google Pay
- `api-key/me` — verificação de saúde

## O algoritmo que implementámos

Para cada resposta 2xx:

1. tomamos todos os campos do corpo EXCETO `message`, `code`, `current_time`
   e `validation_hash`;
2. serializamos em JSON, pela ordem em que os campos vêm na resposta, sem
   escapar barras nem caracteres unicode (equivalente a `JSON_UNESCAPED_SLASHES`
   e `JSON_UNESCAPED_UNICODE`);
3. calculamos `sha256(json + api_signature)`;
4. comparamos com o `validation_hash` recebido.

## O que precisamos que confirmem

1. **Os quatro campos excluídos estão certos?** É a parte que herdámos sem
   documentação. Se o hash for calculado sobre outro conjunto, a verificação
   falha em respostas perfeitamente válidas.

2. **A ordem das chaves.** O hash muda conforme a ordem de serialização.
   Confirmam que é a ordem em que os campos aparecem no corpo da resposta, e
   não, por exemplo, ordem alfabética?

3. **A codificação.** Barras e acentos vão sem escape? Um `/` escapado como
   `\/` dá um hash diferente.

4. **Cobertura.** Todos os endpoints acima devolvem `validation_hash` em 2xx?
   Interessa-nos em particular o `payment/wallet`, que é o mais recente do
   nosso lado e o que menos exercitámos.

Se houver um exemplo de resposta assinada — corpo e hash correspondente —
conseguimos verificar a nossa implementação contra ele sem vos ocupar mais
tempo.

## Três pedidos adicionais

**a) Cartão de teste para pagamento recusado.** Os códigos `0101` e `3333`
cobrem a autenticação 3DS. Falta-nos o caso em que o 3DS corre bem e o emissor
recusa a autorização (saldo insuficiente, cartão bloqueado) — é um desfecho
diferente, e em produção é o mais frequente dos dois. Têm um PAN para isso?

**b) O terceiro serviço.** No nosso sandbox aparecem três métodos activos. A
vossa resposta identificou dois; falta o `A33E…8214 — "SANDBOX-PAYLANDS"
(PLD)`. É esse que deve ser usado no endpoint `payment/wallet` para Apple Pay
e Google Pay, ou há outro?

**c) Apple Pay e Google Pay.** Continuamos à espera do `merchant id` para
registar nas credenciais da app, e do `gatewayMerchantId` que o Google Pay
exige na configuração do gateway. Sem estes dois, as carteiras ficam paradas
mesmo com o resto pronto.

Com os melhores cumprimentos,
Piquet Technologies Lda
