# Consulta de reservas

O iPlate tem dois fluxos diferentes. O app Android consulta os detalhes em
`/api/obter_token` e `/api/reserva` do Expresso, enviando JSON. A autenticação do
Expresso usa `cpf` e `password`; a busca de sugestões
do backend iPlate usa `api/login.php` e `api/reservation-search.php`, com outra
conta e outro token. As credenciais desses serviços não são intercambiáveis.

O AcquaVale usa `ReservationService` tanto no Passo 0 quanto na revalidação antes
de criar o pedido. Configure o provedor em `config/config.local.php`:

```php
'reservation' => ['provider' => 'expresso'],
'expresso' => [
    'token_url' => 'https://vale.expresso.app/api/obter_token',
    'reservation_url' => 'https://vale.expresso.app/api/reserva',
    'cpf' => 'CPF_DA_API',
    'password' => 'SENHA_DA_API',
    'timeout_seconds' => 20,
],
```

Durante o desenvolvimento, `reservation.bypass` pode ser definido como `true`
para aceitar qualquer código não vazio sem chamar a API externa. Esse modo gera
uma reserva sintética e deve permanecer `false` em produção.

Para usar o backend iPlate, defina `provider` como `iplate` e preencha a seção
`iplate` mostrada em `config/config.example.php`, usando o endereço onde o backend
realmente está instalado e uma conta de operador ativa.

Para instalações antigas sem `reservation.provider`, o AcquaVale seleciona
Expresso primeiro quando `expresso.cpf` (ou o legado `expresso.user`) e
`expresso.password` estiverem preenchidos.
Somente se Expresso não estiver configurado ele tenta o backend iPlate.
Senhas e tokens ficam no servidor. O arquivo local não deve entrar no Git.

HTTP 200 com `token: null` não autoriza uma consulta: o serviço não emitiu um token
válido. Confira as credenciais e a permissão do usuário no endpoint. HTTP 404 no
login do iPlate indica que o endereço configurado não disponibiliza esse endpoint.

A versão local atualizada de `iPlate/backend/api/reservation-search.php` aceita
`exact=1` para buscar o código exato sem limitar a data ao dia atual, mantendo os
status `confirmada` e `checkin`. A busca de sugestões sem esse parâmetro mantém o
filtro de chegadas de hoje. Erros de banco retornam HTTP 503, em vez de simular uma
busca bem-sucedida sem resultados. Para essa alternativa funcionar, o backend
precisa acessar o banco de hotelaria com `reservas`, `reserva_hospedes` e `hospedes`.

## Verificação sem acessar serviços reais

Sirva `tests/fixtures/reservation-api.php` com o servidor embutido do PHP em um
endereço de loopback. Defina `AQV_TEST_API_URL` com esse endereço e execute
`php tests/reservation_service_test.php`. Os testes cobrem o contrato JSON,
normalização dos campos, seleção de provedor, token nulo, erro HTTP e reserva
diferente da solicitada, usando somente dados sintéticos.

No projeto iPlate, `php backend/tests/reservation-search-test.php` verifica a busca
exata, o filtro de hoje, a autenticação e a resposta de indisponibilidade. Esse
teste cria e remove uma base temporária no MySQL local (`127.0.0.1:3306`, usuário
`root`), sem carregar as credenciais do banco configurado na aplicação.
