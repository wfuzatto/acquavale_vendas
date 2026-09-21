# AcquaVale Vendas

Sistema de **venda, gestão e validação de ingressos do AcquaVale Park**, com identidade visual baseada no site oficial: azul profundo, azul água, verde-limão, formas arredondadas e linguagem visual aquática.

## Implementado
- Loja responsiva de ingressos e adicionais.
- Cadastro de produtos com nome, SKU, preço, NCM, CEST, tipo, duração, regra de validação e status.
- Produtos iniciais: ingresso 1 dia, ingresso 2 dias e locker.
- Carrinho e resumo antes do pagamento.
- Cadastro individual por ingresso: nome, sobrenome, foto, e-mail, telefone, data de entrada, CPF/RG/CNH e sexo.
- Foto pela câmera ou galeria, armazenada fora da pasta pública.
- Consentimento explícito para uso da foto no controle de acesso.
- Simulação de pagamento aprovado.
- MySQL para pedidos, itens, visitantes, ingressos e validações.
- Painel administrativo.
- API autenticada para catracas/face scanner.
- Validador auxiliar de teste.
- Fila de integração com claim + ack + idempotência.
- Docker Compose com PHP 8.3/Apache + MySQL 8.4.

## Executar
```bash
cp .env.example .env
docker compose up -d --build
```

Acessos:
- Loja: http://localhost:8088/
- Admin: http://localhost:8088/admin.php
- Validador: http://localhost:8088/validator.php

Desenvolvimento: `admin@acquavale.local` / `change-me-now`.
Troque senha, API key e senhas do banco antes de produção.

## Validação
- `once_total`: uma validação em toda a validade.
- `once_per_day`: uma validação por dia; adequado a ingresso de 2+ dias.
- `unlimited_validity`: não consome enquanto estiver válido.

## Não duplicar vendas no sistema auxiliar
O transporte distribuído não garante magicamente "exactly once". O projeto implementa efeito idempotente: `sale-next` faz claim transacional, o consumidor usa `order_code` como chave idempotente e confirma com `sale-ack`. Se cair antes do ACK, o claim expira e a mesma venda pode ser reenviada sem duplicar o efeito.

## LGPD/biometria
Foto usada para reconhecimento facial é dado biométrico sensível. O projeto já guarda a imagem fora do webroot, exige consentimento e restringe sua entrega à API/admin. Antes da produção ainda devem ser formalizados retenção, base legal/termo, perfis de acesso, criptografia/backup, auditoria e exclusão.

Veja `docs/API.md` e `docs/ARCHITECTURE.md`.
