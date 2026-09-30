# Correr os testes

```bash
./vendor/bin/sail php artisan test
```

1016 testes. Demora cerca de um minuto.

## Uma corrida de cada vez por base de dados

Se tentares uma segunda corrida enquanto a primeira ainda vai a meio, ela
**pára logo** com uma mensagem a dizer porquê. Não é uma chatice: é o que evita
uma tarde perdida.

### O que acontecia antes

19 das 125 classes usam `DatabaseTruncation`, que faz `TRUNCATE` a todas as
tabelas antes de cada teste. Em InnoDB o MySQL implementa `TRUNCATE TABLE`
como **DROP seguido de CREATE** — durante essa fracção de segundo a tabela não
existe no `information_schema`.

Num processo só, ninguém repara. É normal e não é bug.

Mas basta uma segunda corrida a olhar para a mesma base ao mesmo tempo e ela
apanha tabelas a meio de serem recriadas:

```
SQLSTATE[42S02]: Base table or view not found: 1146
Table 'piquet_test.services' doesn't exist
```

…centenas de vezes, em testes diferentes a cada corrida, sem relação nenhuma
com o que se mudou. Medido na mesma árvore de código, em corridas seguidas:

| corrida | falhas |
|---|---|
| 1 | 48 |
| 2 | 139 |
| 3 | 579 |
| 4 | 818 |

Reproduz-se à vontade: duas suites em paralelo contra `piquet_test` dão 52
falhas, 29 delas com `doesn't exist`.

O pior não é falhar — é **parecer que o código está partido**. Alguém vai
bissectar commits inocentes à procura de um bug que não existe.

### Onde isto vive

`tests/bootstrap.php`, apontado pelo `bootstrap=` do `phpunit.xml`. Usa um
`flock` exclusivo num ficheiro por NOME DE BASE DE DADOS. O sistema operativo
liberta-o quando o processo termina — inclusive se rebentar ou levar Ctrl-C.
Não há nada para limpar à mão, e uma corrida morta não deixa o caminho fechado.

### Se precisares mesmo de duas ao mesmo tempo

Dá a cada uma a sua base. Duas bases diferentes não se estorvam — o bloqueio é
por nome.

```bash
# criar a segunda base e dar-lhe permissões
docker compose exec -T mysql mysql -uroot -ppassword -e \
  "CREATE DATABASE IF NOT EXISTS \`piquet_test_2\`;
   GRANT ALL ON \`piquet_test_2\`.* TO 'sail'@'%'; FLUSH PRIVILEGES;"

# correr nela
docker compose exec -e DB_DATABASE=piquet_test_2 laravel.test \
    php artisan test
```

Dois pormenores que custam tempo se não se souberem:

- **o `sail` não passa variáveis do teu terminal para dentro do contentor.**
  `DB_DATABASE=x ./vendor/bin/sail php artisan test` corre na base de sempre.
  Tem de ser `docker compose exec -e`.
- **o `GRANT` é preciso.** O utilizador `sail` só tem acesso às bases criadas
  com o contentor; sem ele a corrida morre com `Access denied`, que é outro
  erro a parecer um bug de código.

## Base de testes partida

Se a suite falhar em massa com `doesn't exist` mesmo numa corrida só, a base
ficou com o esquema incompleto (uma reconstrução interrompida). Repõe-se:

```bash
docker compose exec -T -e DB_DATABASE=piquet_test laravel.test \
    php artisan migrate:fresh --force
```

## As três estratégias de base de dados

Convive lá dentro mais do que uma:

| estratégia | classes | o que faz |
|---|---|---|
| `RefreshDatabase` | 94 | transação por teste, revertida no fim |
| `DatabaseTruncation` | 19 | `TRUNCATE` a tudo ANTES de cada teste |
| nenhuma | 21 | não tocam na base — são testes puros |

### Não converter as 19 para `RefreshDatabase`

Parece dívida técnica. **Não é.** Foi tentado e medido em 30/09/2026:

```
Tests: 31 failed (0 assertions)

SQLSTATE[42S01]: Base table or view already exists: 1050
  Table 'users' already exists   ← create table a colidir consigo próprio
SQLSTATE[42S02]: Table 'piquet_test.migrations' doesn't exist
```

Estes testes exercitam código que faz **commit** (carteiras, transações,
liquidações). Um commit fecha a transação que o `RefreshDatabase` abriu, e o
framework reage assim:

```php
// RefreshDatabase.php
if ($connection->getPdo() && ! $connection->getPdo()->inTransaction()) {
    RefreshDatabaseState::$migrated = false;   // força migrate:fresh no seguinte
}
```

Resultado: um `migrate:fresh` por teste, a derrubar e recriar 79 tabelas de
cada vez, a colidir consigo próprio e a deixar o esquema partido.

`DatabaseTruncation` existe exactamente para isto: limpa sem transação nenhuma,
por isso não há transação para partir.

### O que elas deixam para trás, e porque não faz mal

O `DatabaseTruncation` limpa ANTES de cada teste, nunca depois do último. No fim
de uma corrida ficam ~11 tabelas com as linhas do último teste a correr (hoje,
o `ServiceExtrasFlowTest`).

Não faz mal porque a corrida seguinte começa com um `migrate:fresh` — o
`RefreshDatabase` dispara-o no primeiro teste de cada processo. As sobras
desaparecem antes de alguém as ler.

É frágil, isso sim: a segurança depende de a ordem de execução pôr um teste de
`RefreshDatabase` antes de qualquer coisa que leia a base. Hoje põe (os Unit
puros correm primeiro). Se algum dia aparecer um teste a ler dados sem
estratégia e a correr cedo, vai ler o lixo da corrida anterior.
