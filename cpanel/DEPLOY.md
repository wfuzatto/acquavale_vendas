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

Todas as tabelas criadas por este projeto usam o prefixo `acquavale_vendas_` (por exemplo, `acquavale_vendas_products` e `acquavale_vendas_orders`). Isso permite compartilhar o mesmo banco com outras aplicações sem colisão de nomes.

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


## 7. Validação de reserva via backend iPlate

A compra começa no **Passo 0 - Reserva**.

O AcquaVale Vendas replica o fluxo completo já usado pelo app iPlate:

1. faz login server-side em `login.php`;
2. recebe o `api_token` do usuário iPlate;
3. consulta `reservation-search.php` com esse token e o número exato da reserva;
4. somente reservas com status **confirmada** ou **checkin** liberam a compra;
5. antes de criar o pedido, a reserva é consultada novamente no servidor.

URL padrão do backend:

`https://vale.expresso.app/iplate/backend/api/vehicle-entry-create.php`

O sistema deriva automaticamente os endpoints `login.php` e `reservation-search.php`.

Em instalações novas, informe no instalador:
- URL do backend iPlate;
- usuário do backend iPlate;
- senha do backend iPlate.

Em uma instalação já existente, acrescente ao `config/config.local.php`:

```php
'iplate' => [
    'server_url' => 'https://vale.expresso.app/iplate/backend/api/vehicle-entry-create.php',
    'username' => 'SEU_USUARIO_IPLATE',
    'password' => 'SUA_SENHA_IPLATE',
    'timeout_seconds' => 20,
],
```

A senha e o token nunca são enviados ao navegador. O `api_token` é obtido no servidor e armazenado apenas na sessão por até 30 minutos. Se o backend responder 401, o token é descartado, um novo login é feito e a consulta é tentada novamente.

O fluxo antigo de autenticação direta em `/api/obter_token` não é mais necessário para validar a compra.
