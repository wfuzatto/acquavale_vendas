# AcquaVale Vendas

Sistema web de venda, gestão e validação de ingressos do AcquaVale Park.

A implantação principal foi preparada para **cPanel / HostGator compartilhada**, usando somente **PHP 8.2+ e MySQL**, sem Docker e sem dependência obrigatória de Composer.

## Estrutura

```
acquavale_vendas/
├── app/                 código PHP privado
├── config/              configuração privada
├── database/            schema e dados iniciais
├── storage/             logs e fotos privadas
├── cpanel/              instruções de deploy
└── public_html/         único diretório público
```

O DocumentRoot do domínio/subdomínio deve apontar para `public_html/`.

## Recursos implementados

- loja responsiva no visual AcquaVale;
- produtos com nome, SKU, preço, NCM, CEST, tipo, duração e regra de validação;
- ingresso de 1 dia, 2 dias e locker como exemplos iniciais;
- fluxo de compra com Passo 0 obrigatório para validar a reserva no Expresso, seguido de ingressos, cadastro individual das pessoas, regras, resumo, pagamento e QR Codes;
- cada unidade de ingresso exige um cadastro completo próprio; por exemplo, 3 ingressos de 2 dias geram 3 pessoas e 3 QR Codes;
- nome, sobrenome, e-mail, telefone, data de entrada, CPF/RG/CNH, sexo e foto por visitante;
- foto pela câmera ou galeria;
- armazenamento da foto fora do DocumentRoot;
- consentimento para uso da foto no controle de acesso;
- pedido, itens, visitantes e ingressos persistidos em MySQL;
- pagamento simulado em etapa própria;
- QR Code individual gerado após a aprovação do pagamento, com ponto preparado para futuro envio por e-mail/WhatsApp;
- painel administrativo;
- API autenticada para catracas/face scanner;
- validação transacional e idempotente;
- fila de integração de vendas com claim + ACK, evitando duplicidade de efeito;
- instalador web para cPanel.

## Banco de dados

Todas as tabelas próprias do sistema usam o prefixo fixo `acquavale_vendas_`, evitando colisões quando o mesmo banco MySQL atende outras aplicações. Instalações antigas sem prefixo são migradas automaticamente.

## Instalação rápida

1. Crie banco e usuário MySQL no cPanel.
2. Aponte o domínio/subdomínio para `acquavale_vendas/public_html`.
3. Selecione PHP 8.2 ou superior.
4. Abra `/install.php`.
5. Informe os dados do banco e crie a conta administrativa.

Veja [cpanel/DEPLOY.md](cpanel/DEPLOY.md).

## Segurança

`config/config.local.php` não é versionado. Fotos ficam em `storage/private/visitors/`, fora do diretório público. O instalador cria `storage/install.lock` ao finalizar.

Antes de produção, o ambiente deve usar HTTPS. Por envolver foto destinada a identificação facial, também deve existir política de retenção, exclusão, acesso e auditoria compatível com a operação do parque.
