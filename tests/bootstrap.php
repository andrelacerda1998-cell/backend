<?php

require __DIR__.'/../vendor/autoload.php';

/**
 * Uma corrida de testes de cada vez por base de dados.
 *
 * O PORQUÊ, que não é óbvio e custou meio dia a perceber:
 *
 * 19 classes usam `DatabaseTruncation`, que faz `TRUNCATE` a todas as tabelas
 * antes de cada teste. Em InnoDB o MySQL implementa `TRUNCATE TABLE` como DROP
 * seguido de CREATE — durante essa fracção de segundo a tabela NÃO EXISTE no
 * `information_schema`. Num processo só ninguém repara; é normal e não é bug.
 *
 * Mas basta uma segunda corrida a olhar para a mesma base ao mesmo tempo e ela
 * encontra tabelas que existiam há um milissegundo. O sintoma é este:
 *
 *     SQLSTATE[42S02]: Base table or view not found: 1146
 *     Table 'piquet_test.services' doesn't exist
 *
 * …multiplicado por centenas, em testes diferentes a cada corrida, e sem
 * relação nenhuma com o que se mudou. Vimos 48, depois 139, depois 579, depois
 * 818 falhas na MESMA árvore de código. Reproduz-se à vontade: duas suites em
 * paralelo contra `piquet_test` dão 52 falhas com 29 "doesn't exist".
 *
 * Pior do que falhar é falhar assim: parece que o código está partido. Alguém
 * vai passar a tarde a bissectar commits inocentes.
 *
 * Por isso a segunda corrida PÁRA JÁ, com uma mensagem que diz o que se passa,
 * em vez de produzir centenas de falhas inventadas. Uma linha clara vale mais
 * do que um relatório grande e falso.
 *
 * Se precisares mesmo de duas corridas ao mesmo tempo, dá a cada uma a sua base
 * de dados — é o que resolve de verdade:
 *
 *     docker compose exec -e DB_DATABASE=piquet_test_2 laravel.test \
 *         php artisan test
 *
 * (com `docker compose exec` e não com o `sail`: o `sail` não passa variáveis
 * do terminal para dentro do contentor, e a corrida acabaria na mesma base.)
 *
 * O bloqueio é por NOME DE BASE DE DADOS, por isso duas corridas em bases
 * diferentes não se estorvam.
 *
 * O ficheiro de bloqueio é libertado pelo sistema operativo quando o processo
 * termina — inclusive se rebentar ou levar Ctrl-C. Não há nada para limpar à
 * mão, e uma corrida morta não deixa o caminho fechado.
 */
/**
 * O nome da base tem de ser resolvido COMO O PHPUNIT O RESOLVE.
 *
 * Este ficheiro corre ANTES de o PHPUnit aplicar os `<env>` do phpunit.xml, por
 * isso ler só o ambiente do processo dava sempre o valor errado (ou nenhum).
 * A regra do PHPUnit é: um `<env>` sem `force="true"` NÃO sobrepõe uma variável
 * que já exista no ambiente. Imita-se isso aqui — primeiro o ambiente, depois o
 * que está no ficheiro.
 */
$doAmbiente = $_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE');
$base = ($doAmbiente !== false && $doAmbiente !== null && $doAmbiente !== '')
    ? $doAmbiente
    : nomeDaBaseNoPhpunit(__DIR__.'/../phpunit.xml') ?? 'piquet_test';

function nomeDaBaseNoPhpunit(string $caminho): ?string
{
    if (! is_readable($caminho)) {
        return null;
    }

    $xml = @simplexml_load_file($caminho);

    if ($xml === false) {
        return null;
    }

    foreach ($xml->php->env ?? [] as $env) {
        if ((string) $env['name'] === 'DB_DATABASE') {
            return (string) $env['value'];
        }
    }

    return null;
}
$ficheiro = sys_get_temp_dir().'/piquet-testes-'.preg_replace('/[^A-Za-z0-9_.-]/', '_', (string) $base).'.lock';

$tranca = @fopen($ficheiro, 'c');

if ($tranca === false) {
    // Sem sítio onde escrever o bloqueio não se trava a suite: seria pior
    // impedir toda a gente de testar do que correr o risco da colisão.
    fwrite(STDERR, "AVISO: não foi possível criar o bloqueio em {$ficheiro}; a continuar sem ele.\n");

    return;
}

if (! flock($tranca, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, <<<TEXTO

    ╭──────────────────────────────────────────────────────────────────────╮
    │  JÁ HÁ UMA CORRIDA DE TESTES EM CURSO NESTA BASE DE DADOS            │
    ╰──────────────────────────────────────────────────────────────────────╯

    Base de dados: {$base}

    Duas corridas ao mesmo tempo partem-se uma à outra. O `DatabaseTruncation`
    faz TRUNCATE, que em InnoDB é DROP + CREATE: a outra corrida apanharia
    tabelas a meio de serem recriadas e falharia com centenas de

        Table '{$base}.<qualquer>' doesn't exist

    …que não têm nada a ver com o código. Por isso esta corrida pára aqui.

    Ou esperas que a outra acabe, ou dás a esta a sua própria base de dados.

    Atenção: o `sail` NÃO leva variáveis do teu terminal para dentro do
    contentor — tem de ser o `docker compose exec` a passá-las, e a base tem de
    existir primeiro:

        docker compose exec -T mysql mysql -uroot -ppassword \
            -e "CREATE DATABASE IF NOT EXISTS {$base}_2;
                GRANT ALL ON \`{\$base}_2\`.* TO 'sail'@'%'; FLUSH PRIVILEGES;"

        docker compose exec -e DB_DATABASE={$base}_2 laravel.test \
            php artisan test

    O GRANT é preciso: o utilizador `sail` só tem acesso às bases criadas
    com o contentor. Sem ele a corrida morre com "Access denied", que é
    outro erro a parecer um bug de código.
    (Se tiveres a certeza de que não há corrida nenhuma, sobrou um processo
    pendurado: `pkill -f "artisan test"`.)


    TEXTO);

    exit(1);
}

// A referência tem de sobreviver ao fim deste ficheiro: se o `fopen` for
// recolhido pelo coletor de lixo, o bloqueio cai e deixa de servir para nada.
$GLOBALS['__piquet_tranca_dos_testes'] = $tranca;
