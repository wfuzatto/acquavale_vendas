# Arquitetura

Componentes: loja pública, painel administrativo, API interna, MySQL e storage privado de fotos.

Modelo: `acquavale_vendas_products -> acquavale_vendas_order_items -> acquavale_vendas_orders -> acquavale_vendas_visitors -> acquavale_vendas_tickets -> acquavale_vendas_ticket_redemptions`.

A separação entre pedido, visitante e ingresso permite uma compra com vários ingressos, cada um associado à pessoa que usará a catraca.

## Pagamentos
A tela atual simula aprovação. A integração futura deve entrar como adapter de adquirente/PIX com webhook idempotente, sem alterar o restante do fluxo.

## Fiscal
NCM e CEST ficam no produto e são congelados no item do pedido. Para ingressos/serviços, a classificação fiscal final deve ser validada com a contabilidade.

## Facial
A loja não calcula embeddings. Ela associa ingresso e foto e expõe a foto somente em endpoint autenticado. O motor facial fica desacoplado.

## Antes de produção
HTTPS, rate limiting/WAF, credenciais fora do webroot, usuários com perfis/2FA, política de retenção e exclusão de biometria, criptografia/backup, pagamento real, vouchers, capacidade por data, cupons/lotes/meia-entrada, reagendamento/refund, observabilidade e API key individual por equipamento.


## Namespace das tabelas
Todas as tabelas próprias desta aplicação usam obrigatoriamente o prefixo `acquavale_vendas_` para permitir convivência com outras aplicações no mesmo banco MySQL. A aplicação também mantém `acquavale_vendas_app_migrations` para controle de migrações. Instalações antigas com tabelas sem prefixo são renomeadas automaticamente, sem cópia de dados.
