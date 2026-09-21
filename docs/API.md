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
