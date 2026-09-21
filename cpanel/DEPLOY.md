# Deploy no cPanel / HostGator

Este projeto foi estruturado para **PHP + MySQL nativos**, sem Docker e sem Composer obrigatório.

## Estrutura recomendada

Envie ou clone o repositório para uma pasta da sua conta, por exemplo:

```
/home/SEU_USUARIO/acquavale_vendas/
├── app/
├── config/
├── database/
├── storage/
└── public_html/   <- DocumentRoot do domínio/subdomínio
```

A recomendação é que somente a pasta `public_html/` seja acessível pela web. `config/` contém a senha do banco e `storage/private/visitors/` contém as fotos de identificação.

## 1. PHP

No MultiPHP Manager do cPanel selecione PHP 8.2 ou superior para o domínio do sistema.

Extensões necessárias:
- PDO
- pdo_mysql
- fileinfo
- json
- session

## 2. MySQL

No cPanel:
1. Crie um banco MySQL.
2. Crie um usuário MySQL.
3. Vincule o usuário ao banco.
4. Conceda todos os privilégios ao usuário nesse banco.

Não é necessário importar SQL manualmente se você usar o instalador.

## 3. DocumentRoot

Aponte o domínio ou subdomínio usado para vendas para:

`/home/SEU_USUARIO/acquavale_vendas/public_html`

Exemplo recomendado: `ingressos.acquavale.com.br`.

Se o cPanel usar outro caminho para a conta, mantenha a mesma lógica: o DocumentRoot deve terminar na pasta `public_html` deste projeto.

## 4. Instalador

Abra:

`https://SEU_DOMINIO/install.php`

Informe banco, usuário, senha MySQL e crie a conta administrativa.

O instalador:
- testa os requisitos PHP;
- conecta ao banco já criado pelo cPanel;
- cria as tabelas;
- cadastra os produtos iniciais;
- cria `config/config.local.php` fora do DocumentRoot;
- gera uma API key aleatória;
- cria `storage/install.lock` para impedir reinstalação.

## 5. Permissões

Normalmente as permissões padrão do cPanel bastam. Se necessário:
- diretórios: 750 ou 755;
- arquivos PHP privados: 640 ou 644;
- `config/` e `storage/` precisam ser graváveis pelo PHP durante a instalação.

Evite 777.

## 6. Depois da instalação

Teste:
- Loja: `/`
- Administração: `/admin.php`
- Validador: `/validator.php`
- API health: `/api.php?action=health` com Bearer API key.

A API key fica em `config/config.local.php`.

## Atualização via Git

O arquivo `config/config.local.php`, o lock de instalação e as fotos não entram no Git. Assim é possível atualizar o código sem sobrescrever senha do banco, credenciais da API ou imagens dos visitantes.
