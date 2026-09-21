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

O fluxo recomendado é **pull local**. O servidor Vale Visitor consulta o AcquaVale Vendas por HTTPS; o servidor web não abre conexão diretamente para a rede local.

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

O ACK é recusado enquanto qualquer ticket do pedido não estiver `confirmed` para o mesmo consumidor. Assim, `orders.integration_status=processed` significa que o sistema local reportou confirmação do HikCentral para todos os visitantes.

### 4. Consultar estado

`POST /api.php?action=sale-status`

```json
{"consumer":"vale-visitor","order_code":"PED-..."}
```

Retorna o estado por ticket e os IDs persistidos do sistema local/HikCentral.
