# API de integração

Todas as chamadas usam `Authorization: Bearer SEU_API_KEY`.

## Health
`GET /api.php?action=health`

## Buscar próxima venda paga
`POST /api.php?action=sale-next`

Body: `{"consumer":"face_scanner"}`

Retorna no máximo uma venda e um `claim_token`. O claim é transacional e evita dois consumidores pegarem a mesma venda ao mesmo tempo.

## Confirmar processamento
`POST /api.php?action=sale-ack`

```json
{"order_code":"PED-...","claim_token":"...","consumer":"face_scanner","external_reference":"id-no-destino"}
```

O banco impede mais de um recibo para a combinação `order_id + consumer`.

## Validar ingresso
`POST /api.php?action=ticket-validate`

```json
{"ticket_code":"AQV-...","gate_code":"ENTRADA-01","idempotency_key":"uuid-da-requisicao"}
```

A validação trava a linha do ingresso dentro de transação. A resposta válida inclui `visitor_id` e o endpoint autenticado da foto:
`GET /api.php?action=visitor-photo&visitor_id=123`.


## Integração com Vale Visitor / HikCentral

O fluxo principal é **push online → Vale Visitor local**. Após o pagamento, o AcquaVale Vendas envia a venda para o receiver local por HTTPS com assinatura HMAC. O fluxo `sale-next` continua disponível como fallback/pull para diagnóstico ou contingência.

### 1. Buscar venda paga

`POST /api.php?action=sale-next`

```json
{"consumer":"vale-visitor"}
```

Cada ticket retorna `ticket_code`, dados do visitante, validade e `photo_url` absoluta autenticada.

### 2. Reportar estado de cada visitante

`POST /api.php?action=sale-ticket-status`

```json
{
  "consumer":"vale-visitor",
  "order_code":"PED-...",
  "ticket_code":"AQV-...",
  "state":"confirmed",
  "external_reservation_id":"123",
  "hcp_visitor_id":"1",
  "hcp_reference":"757...",
  "message":"HikCentral confirmou face e credencial.",
  "details":{"state":"confirmed","doors":[]}
}
```

Estados aceitos: `pending`, `imported`, `syncing`, `confirmed`, `failed`.

### 3. ACK somente após double check

`POST /api.php?action=sale-ack`

O ACK é recusado enquanto qualquer ticket do pedido não estiver `confirmed` para o mesmo consumidor. Assim, `acquavale_vendas_orders.integration_status=processed` significa que o sistema local reportou confirmação do HikCentral para todos os visitantes.

### 4. Consultar estado

`POST /api.php?action=sale-status`

```json
{"consumer":"vale-visitor","order_code":"PED-..."}
```

Retorna o estado por ticket e os IDs persistidos do sistema local/HikCentral.


### Push assinado para o Vale Visitor

O site envia `sale.paid` para a URL configurada em `visitor_receiver.url`.

Cabeçalhos:
- `X-AQV-Timestamp`
- `X-AQV-Delivery-Id`
- `X-AQV-Signature: sha256=<HMAC>`

A assinatura é:

```text
HMAC-SHA256(shared_secret, timestamp + "\n" + raw_json_body)
```

O payload inclui `order_code`, `claim_token`, itens, tickets, dados do visitante, validade e `photo_url`. A foto continua protegida pela API key e é baixada pelo Vale Visitor usando `Authorization: Bearer`.

O receiver local deve responder HTTP 202 rapidamente; a comunicação com o HikCentral acontece no worker local, não dentro da requisição do site.
