# Seleção de profissional (matching)

Especificação do fluxo em que o cliente escolhe entre vários profissionais em vez
de escolher um às cegas antes de pagar.

## Porquê

O fluxo atual tem duas fraquezas concretas, ambas visíveis no código:

1. **O cliente paga antes de haver aceitação.** `OpenServiceController::creditCard()`
   cria o serviço, cobra, e só depois chama `notifyVendor()`. Se o profissional
   recusar, é preciso desfazer o pagamento — daí existir o `dispatchCancelJob()`.
2. **Uma recusa deita o pedido abaixo.** `findVendor()` recebe um `vendor_id` único
   vindo do cliente. Só essa pessoa é notificada. Se disser que não, acabou.

O novo fluxo inverte a ordem (aceitação primeiro, pagamento depois) e deixa de
depender de uma só pessoa.

## Princípio que governa o desenho

> **Ninguém aceita em vão.**

Um profissional que aceita e não recebe trabalho gastou atenção a troco de nada.
Repetido, ensina-o a ignorar notificações — e sem oferta não há marketplace. Todas
as decisões abaixo saem daqui.

## Dois caminhos, um só ecrã

O cliente vê o mesmo ecrã nos dois casos. O que muda é o que acontece por trás,
porque a urgência tem valor diferente.

### Imediato ("agora") — shortlist primeiro, perguntar depois

O `can_accept_service` já sabe quem está genuinamente livre sem ser preciso
perguntar: exclui quem tem serviço aberto, documentos por verificar ou IBAN em
falta (`Vendor::canAcceptService`). Isso permite mostrar opções sem notificar
ninguém.

```
cliente escolhe categoria → tipo → "agora"
  → ranking dos elegíveis E livres, com orçamento próprio de cada um
  → cliente vê os 3 melhores IMEDIATAMENTE (zero espera)
  → cliente escolhe um
  → SÓ ESSE é notificado                       ← ninguém aceita em vão
  → aceita  → checkout de um toque → pago → serviço arranca
  → recusa/expira → passa automaticamente ao 2.º da lista
                    ("O João não estava disponível — a contactar o Miguel")
  → esgotados os 3 → "tentar novamente"
```

Vantagens: não há janela de broadcast nenhuma, logo não há espera antes de
escolher; e é chamada uma pessoa de cada vez, logo o custo de aceitar sem ganhar
é zero.

Custo: "livre" é uma previsão, não uma promessa. Se o escolhido não responder,
perde-se a janela de aceitação dele antes de passar ao seguinte. Mitiga-se
exigindo atividade recente na app (ver `matching.require_recent_activity_minutes`).

### Agendado — ondas, com escolha entre quem aceitou

Aqui há tempo, e o cliente não fica à espera: pede, fecha a app, e é avisado
quando houver profissionais.

```
cliente escolhe categoria → tipo → "agendar" + data/hora
  → ranking dos elegíveis para aquele slot
  → notifica a 1.ª onda (N melhores)
  → cada aceitação aparece AO VIVO no ecrã do cliente (não se espera pelos 3)
  → se ao fim de X segundos houver menos de 3, notifica a onda seguinte
  → ao 3.º sim, o pedido fecha e os restantes recebem "já preenchido"
  → cliente escolhe → checkout → pago → agendado
  → nenhum aceitou → "tentar novamente"
```

## Ranking

Ordem definida pelo negócio, por prioridade:

1. **Melhor avaliação** — média de `services.rating_by_customer` nos serviços
   fechados da área de operação. Lê-se a fonte e não o resumo em cache
   (`vendor_ratings`), que é recalculado por observer e pode estar atrasado.
2. **Preço mais barato** — orçamento calculado para aquele serviço concreto
3. **Menor distância** — `Vendor::calculateDistance()` à morada do serviço

### Faixas de avaliação (decisão necessária)

Ordenação lexicográfica estrita faz com que o 2.º e o 3.º critério **nunca
contem**: com médias decimais (4,7 vs 4,6) praticamente não há empates, logo a
avaliação decide sempre sozinha.

Para os três critérios funcionarem pela ordem definida, a avaliação é agrupada em
faixas e o preço ordena **dentro** da faixa. Faixas configuráveis em
`matching.rating_bands`; por omissão `[4.5, 4.0, 3.0]`, o que dá:

| faixa | avaliação |
|---|---|
| A | ≥ 4,5 |
| B | 4,0 – 4,49 |
| C | 3,0 – 3,99 |
| D | < 3,0 |

### Arranque a frio

Um profissional sem avaliações não tem faixa. Se ficar no fundo, nunca é
escolhido, nunca recebe avaliação, e nunca sai do fundo — a oferta nova morre à
nascença.

Regra: **uma das 3 vagas é reservada** a quem tem menos de
`matching.new_vendor_min_ratings` avaliações (por omissão 5), desde que cumpra os
critérios de elegibilidade. Se não houver ninguém nessas condições, a vaga volta
ao ranking normal.

## Preços

`RateService` já calcula o preço do cliente **a partir da tarifa do profissional**
(`calculateForCustomerWithoutDiscount` = `calculateForVendor / (1 - comissão)`).
Logo profissionais diferentes dão preços diferentes ao cliente — o que este fluxo
precisa, e já existe.

**O orçamento é congelado por candidato.** `calculateHourCommission()` depende da
hora (`Carbon::now()`): um orçamento calculado às 17:59 e pago às 18:01 mudaria de
banda. O preço mostrado ao cliente é o preço cobrado — grava-se em
`service_candidates.quoted_amount` no momento em que o candidato é gerado, e é
esse que segue para o checkout.

## Estados

`services.vendor_id` já é nullable, por isso o serviço pode existir sem
profissional atribuído.

Novos valores em `ServiceStatus`:

| estado | significado |
|---|---|
| `Matching` | à procura de profissional; sem `vendor_id`, sem pagamento |
| `AwaitingPayment` | profissional aceitou; à espera do checkout |
| `MatchingFailed` | ninguém aceitou / todos recusaram; terminal |

Transições:

```
Matching ──candidato aceita──> AwaitingPayment ──pago──> Accepted | Scheduled
   │                                  │
   │                                  └──checkout expira──> MatchingFailed
   └──sem candidatos / esgotado──> MatchingFailed
```

`Matching`, `AwaitingPayment` e `MatchingFailed` ficam **fora** do conjunto de
"serviço aberto" (`Vendor::openServices`), pela mesma razão que `Pending3DS` já
está: um serviço por atribuir não pode bloquear o profissional de aceitar outros.

## Tabela `service_candidates`

| coluna | notas |
|---|---|
| `service_id` | |
| `vendor_id` | |
| `rank` | posição no ranking, 1-based |
| `wave` | onda em que foi notificado (imediato: sempre 1) |
| `status` | `shortlisted` \| `notified` \| `accepted` \| `declined` \| `expired` \| `selected` \| `lost` |
| `rating_band` | faixa usada na ordenação, para auditar decisões |
| `quoted_amount` | preço do cliente, congelado |
| `quoted_amount_for_vendor` | o que o profissional recebe, congelado |
| `quoted_distance` | km usados no cálculo |
| `is_new_vendor_slot` | ocupou a vaga reservada a quem tem poucas avaliações |
| `notified_at` / `responded_at` / `expires_at` | |

Índice único `(service_id, vendor_id)`.

Guardar os candidatos perdedores é deliberado: sem isso não há como responder a
"porque é que não me apareceu este pedido?" nem medir se o ranking está a
funcionar.

## Regras que protegem o profissional

**Aceitar não bloqueia a agenda.** Aceitar é "estou disponível e interessado", não
é compromisso. Só o escolhido tem o slot reservado. Isto permite aceitar dois
pedidos em paralelo sem se prejudicar; a sobreposição é verificada no momento da
confirmação, não no da aceitação.

**A janela é visível.** O profissional vê quanto tempo falta até a proposta
caducar. Sem isto fica pendurado sem saber se pode aceitar outra coisa — e é aí
que desiste da app.

**O perdedor é avisado em segundos, com motivo.** "O cliente escolheu outro
profissional" imediatamente, nunca silêncio. Silêncio é o que destrói a confiança.

**Ao 3.º sim, o pedido fecha.** Quem ainda não respondeu deixa de ver o pedido e
recebe "já preenchido" — não fica a responder a algo que já não existe.

## Definições configuráveis (`MatchingSettings`)

Nenhum destes números é inventado aqui; são pontos de calibração com dados reais.

| definição | omissão | o que controla |
|---|---|---|
| `shortlist_size` | 3 | quantos o cliente vê |
| `wave_size` | 6 | quantos são notificados por onda (agendado) |
| `wave_interval_seconds` | 45 | espera antes da onda seguinte |
| `max_waves` | 3 | até onde vai antes de desistir |
| `vendor_response_seconds_immediate` | 60 | igual à janela de hoje |
| `vendor_response_seconds_scheduled` | 1800 | 30 min |
| `customer_choice_seconds` | 120 | quanto tempo o cliente tem para escolher |
| `checkout_seconds` | 300 | quanto tempo tem para pagar depois de escolher |
| `rating_bands` | `[4.5, 4.0, 3.0]` | fronteiras das faixas |
| `new_vendor_min_ratings` | 5 | abaixo disto conta como profissional novo |
| `require_recent_activity_minutes` | 15 | para entrar na shortlist do imediato |

## O que este documento NÃO decide

- **Os valores acima.** São pontos de partida plausíveis, não regras de negócio.
  Precisam de calibração com tráfego real.
- **Se o cliente vê a tarifa do profissional ou o preço final.** Se a margem não
  for percentagem fixa, a ordem do ranking e a ordem no ecrã podem divergir — e
  isso lê-se como a app a mentir. Decisão de produto.
- **Compensar quem aceita e perde.** Fica registado em `service_candidates` para
  ser possível medir; o que fazer com isso é decisão de negócio.

## Ordem de construção

1. `MatchingSettings` + migração de definições
2. `service_candidates` + modelo + estados novos
3. `VendorRankingService` — elegibilidade, faixas, ordenação, vaga do novato
4. `MatchingService` — shortlist (imediato) e ondas (agendado)
5. ~~Endpoints do cliente: shortlist, escolher, confirmar+pagar~~ — FEITO
   (`MatchingController`, `/customer/services/matching`)
6. ~~Endpoints do profissional: aceitar, recusar~~ — FEITO
   (`MatchingInvitationsController`, `/vendor/services/matching`)
7. ~~Eventos em tempo real~~ — FEITO (`App\Events\Matching\*`)
8. App do cliente: ecrã de escolha com atualização ao vivo
9. App do profissional: proposta com contador visível

## Endpoints do cliente

| método | rota | o que faz |
|---|---|---|
| POST | `/customer/services/matching` | abre o pedido e põe candidatos em cima da mesa |
| GET | `/customer/services/matching/{service}` | estado e quem há para escolher |
| POST | `/customer/services/matching/{service}/select/{candidate}` | o cliente escolhe |
| POST | `/customer/services/matching/{service}/checkout` | cobra o preço congelado |

O checkout NÃO recalcula o preço: parte de `service_candidates.quoted_amount` e
aplica cupão e saldo por cima, com as mesmas regras do fluxo antigo
(`buildTransactionTotals`). A cobrança em si é a mesma
(`ProcessesServicePayment`), extraída do `OpenServiceController` sem alterações
— um segundo caminho de cobrança seria a forma mais rápida de as duas
implementações divergirem em silêncio, e divergirem aqui é cobrar mal a alguém.

Mantém-se do fluxo antigo: o lock do cupão, a barreira da comissão negativa, o
3DS, o MBWay, e a saída antecipada para contas de teste.

## Endpoints do profissional

| método | rota | o que faz |
|---|---|---|
| GET | `/vendor/services/matching` | convites com a janela ainda aberta |
| POST | `/vendor/services/matching/{candidate}/accept` | "estou disponível" |
| POST | `/vendor/services/matching/{candidate}/decline` | recusa |

O payload mostra `amount_for_vendor` e nunca o que o cliente paga: o modelo é
margem por cima, e o profissional recebe 100% do que definiu. Mostra também
`expires_at`, porque uma janela invisível deixa-o pendurado sem saber se pode
aceitar outra coisa.

Uma aceitação tardia distingue "a janela fechou" de "outro foi mais rápido".
São coisas diferentes, e um erro vago faz o profissional achar que a app está
partida.

## Eventos em tempo real

| evento | canal | quando |
|---|---|---|
| `MatchingInvitationEvent` | `service.vendor.{userId}` | foi chamado; a janela dele começou |
| `MatchingCandidateAcceptedEvent` | `service.customer.{userId}` | alguém aceitou — aparece ao vivo no ecrã do cliente |
| `MatchingRequestClosedEvent` | `service.vendor.{userId}` | o pedido fechou; deixa de o ver |
| `MatchingCandidateLostEvent` | `service.vendor.{userId}` | o cliente escolheu outro |

O `MatchingCandidateAcceptedEvent` é o que torna a espera progressiva: o cliente
não espera que a janela feche, vê cada profissional entrar à medida que aceita.
Aos poucos segundos já tem uma opção, e decide se quer esperar por mais.

Os três dirigidos ao profissional existem pelo mesmo motivo: **nunca silêncio**.
Um "sim" que fica sem resposta é o que ensina alguém a deixar de responder.
