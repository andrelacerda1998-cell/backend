# Payshop / Paylands — ambiente sandbox

Valores de TESTE do sandbox, fornecidos pelo Payshop em 30/09/2026. Não são
segredos: são os cartões públicos de teste e os identificadores dos serviços
configurados no POP Backoffice. As chaves a sério (`PAYSHOP_SDK_API_KEY`,
`PAYSHOP_SDK_API_SIGNATURE`) continuam só no `.env` e na Vercel.

## Serviços de pagamento (UUIDs)

| método | UUID | variável |
|---|---|---|
| Cartão de crédito/débito | `C8B879C4-60A2-413B-9C72-485A8392660F` | `PAYSHOP_SDK_CREDIT_CARD_SERVICE_UUID` |
| MB WAY | `24A01C1B-EB51-4729-8A41-6791722885BE` | `PAYSHOP_SDK_MBWAY_SERVICE_UUID` |

**Por confirmar:** existe um terceiro serviço no POP sandbox,
`A33E…8214 — "SANDBOX-PAYLANDS" (PLD)`, que o Payshop **não identificou** na
resposta. É o candidato natural para o endpoint `payment/wallet` (Apple Pay e
Google Pay), que hoje não tem variável de ambiente própria. Enquanto não se
souber, os testes de carteira ficam a adivinhar — ver a pergunta em aberto no
fim deste ficheiro.

## Cartão de teste

```
PAN    5299990270000590
CVV    774
Val.   12/2027
```

Cenários 3D Secure (código a introduzir no desafio):

| código | resultado |
|---|---|
| `0101` | 3DS autenticado (OK) |
| `3333` | 3DS falhado (KO) |

**Atenção à diferença:** `3333` é uma falha de AUTENTICAÇÃO, não de
autorização. O Payshop não forneceu um PAN que dê pagamento **recusado pelo
emissor** com 3DS bem-sucedido — que é um caso distinto e o mais comum em
produção (saldo insuficiente, cartão bloqueado). Está pedido.

## Verificação da assinatura (`validation_hash`)

Implementada em `payshop-sdk/src/Api/Concerns/APIRequest.php::validateRequest`,
corre em TODAS as respostas 2xx. Algoritmo:

1. pegar em todos os campos da resposta EXCETO `message`, `code`,
   `current_time` e `validation_hash`;
2. `json_encode($campos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)`
   — pela ordem em que vieram na resposta;
3. `sha256($json . PAYSHOP_SDK_API_SIGNATURE)`;
4. comparar com `validation_hash`; se diferir, `InvalidAuthentication`.

Quando a resposta NÃO traz `validation_hash`:

- `PAYSHOP_SDK_STRICT_SIGNATURE=true` → rejeita (fail-closed);
- desligado (omissão) → regista um aviso e continua.

**Está desligado de propósito.** Ligar antes de confirmar que todas as
respostas 2xx vêm assinadas rejeitaria respostas boas — e uma cobrança
rejeitada por falta de assinatura é indistinguível, do lado do cliente, de um
pagamento que falhou.

## Endpoints que a integração usa

| endpoint | onde |
|---|---|
| `customer` | criar/actualizar o perfil do cliente |
| `payment-method/card` | tokenizar cartão |
| `payment` | criar, autorizar, confirmar, reembolsar, cancelar |
| `payment/wallet` | Apple Pay e Google Pay |
| `api-key/me` | verificação de saúde |

## Perguntas em aberto ao Payshop

1. Ordem e codificação exactas do `validation_hash` (ver acima) — a verificação
   só pode ser ligada depois de confirmada, porque a ordem das chaves muda o
   hash.
2. Que campos ficam de fora do hash. Excluímos quatro; é herdado, não
   confirmado.
3. O `payment/wallet` devolve `validation_hash`? É o endpoint mais recente e o
   menos exercitado.
4. A que método corresponde o terceiro serviço (`A33E…8214`, SANDBOX-PAYLANDS).
5. PAN de teste para pagamento recusado pelo emissor.
6. `merchant id` (Apple Pay / Google Pay) e `gatewayMerchantId` (Google Pay).
