#!/usr/bin/env bash
#
# Cria a base de dados dos testes.
#
# O `phpunit.xml` aponta para `piquet_test`, que e o mesmo nome que a CI cria
# (ver o servico mysql em .github/workflows/deploy.yml). O script que vem com
# o Sail cria `testing` e mais nada — com nomes diferentes nos dois sitios,
# qualquer acesso a base fora do `RefreshDatabase` passava aqui e rebentava la
# com "Unknown database", que se le como codigo partido e nao como
# configuracao desalinhada.
#
# Corre so na primeira vez que o volume do MySQL e criado, como todos os
# scripts de `docker-entrypoint-initdb.d`.

mysql --user=root --password="$MYSQL_ROOT_PASSWORD" <<-EOSQL
    CREATE DATABASE IF NOT EXISTS piquet_test;
EOSQL

if [ -n "$MYSQL_USER" ]; then
mysql --user=root --password="$MYSQL_ROOT_PASSWORD" <<-EOSQL
    GRANT ALL PRIVILEGES ON \`piquet\_test\`.* TO '$MYSQL_USER'@'%';
EOSQL
fi
