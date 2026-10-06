# Cesto: encomendas com várias visitas

Decisão do André (06/10/2026). O cesto deixa de ser N pedidos soltos e passa a
ser uma **encomenda**: os serviços que um técnico consegue fazer de uma vez
vão numa só **visita**.

## Modelo

- **Visita = `Service`.** Um técnico, uma ordem de pagamento, uma cativação,
  uma captura, uma fatura, uma avaliação. Todo o caminho do dinheiro é o de
  qualquer pedido (`select` → `checkout` → `CloseService::close`, fecho
  automático das 24h).
- **Linhas = `service_items`.** Só quando a visita tem mais de um serviço. Os
  minutos ficam congelados no pedido. Sem preço por linha: o preço da visita é
  um só (minutos totais × valor/hora + **uma** deslocação).
- **`services_type_id`** de uma visita com várias linhas é o **tipo principal**
  (o que leva mais tempo). É por ele que o resto do backend continua a
  funcionar. Para mostrar, usa-se `Service::titulo()` e `itemsPayload()`.
- **Encomenda = `service_orders`.** Só agrupa. Não guarda dinheiro. O estado
  (`open`/`done`/`canceled`) é lido das visitas pelo `ServiceObserver`.
- Uma visita com uma linha só é um pedido normal: tipo + quantidade, sem
  `service_items`.

## Plano de visitas (`PlanoDeVisitas`)

1. Para cada serviço, quem o pode fazer (o ranking a sério: online,
   documentos, agenda, raio, cidades, pausa).
2. Serviços sem ninguém → `unavailable`.
3. Do maior para o menor, junta-se enquanto a visita junta mantiver **3 ou
   mais** técnicos (`MIN_TECNICOS_PARA_JUNTAR`). Quando os dois lados já
   tinham menos de 3, junta-se se não perderem nenhum.
4. Cada visita é confirmada com o ranking da visita inteira (agenda pelos
   minutos somados, preço junto). Se ninguém couber, desfaz-se em visitas de
   um serviço.

## API

| | |
|---|---|
| `POST /common/services/orders/plan` | Plano, antes de pedir. Público (o convidado vê-o antes do SMS). |
| `POST /customer/services/matching/orders` | Pedir. Recebe `expected_visits` (o plano que o cliente viu). Se o plano refeito for outro, ou houver indisponíveis, **409 com o plano novo** e nada é criado. |
| `GET /customer/services/matching/orders/{order}` | A encomenda e o payload de matching de cada visita. |
| `POST /customer/services/matching/orders/{order}/cancel` | Cancela as visitas em `Matching`/`AwaitingPayment` sem ordem de pagamento. As restantes ficam em `not_canceled`: cancelam-se uma a uma, com as suas regras. |

Cada visita usa os endpoints de matching de sempre (`/matching/{service}`,
`/select`, `/checkout`).

## Pagamento quando há divisão

Cada visita é paga quando o seu técnico é escolhido. Numa encomenda de duas
visitas são duas confirmações. Uma cativação única obrigaria a cativar antes
de haver técnico — fora de questão (ver docs/matching.md).

## Fora da fase 1

- Propor dividir uma visita cujo matching falhou (fase 2).
- Agendamentos recorrentes e pedidos personalizados dentro de um cesto.
- Apps (fases 3 e 4).
