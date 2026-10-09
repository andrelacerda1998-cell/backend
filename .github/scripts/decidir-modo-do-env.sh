#!/usr/bin/env bash
# Decide como o deploy trata o .env de produção, e escreve `modo=...` em
# $GITHUB_OUTPUT:
#
#   substituir -- o PROD_ENV_FILE está completo: o .env do servidor passa a
#                 ser ele (com os segredos próprios por cima), como sempre.
#   manter     -- o PROD_ENV_FILE está incompleto: fica o .env que já está no
#                 servidor e só se atualizam as linhas com segredo próprio.
#                 Um segredo cortado deixa de poder derrubar a produção.
#
# Falha (e trava o deploy) só quando um segredo próprio é inválido, porque
# aí iria parar ao servidor de qualquer forma.
# Entrada: $1 = .env montado; ambiente NOTIFICATION_URL.
set -euo pipefail
DIR="$(dirname "$0")"
FICHEIRO="${1:?uso: decidir-modo-do-env.sh <.env montado>}"

bash "$DIR/verificar-url-do-aviso.sh" "${NOTIFICATION_URL:-}"

if bash "$DIR/verificar-env-de-producao.sh" "$FICHEIRO"; then
  modo=substituir
else
  modo=manter
  echo "::warning::O PROD_ENV_FILE está incompleto: o deploy mantém o .env que já está no servidor e só atualiza as linhas com segredo próprio. Convém repor o PROD_ENV_FILE completo."
fi
echo "Modo do .env: $modo"
echo "modo=$modo" >> "${GITHUB_OUTPUT:-/dev/null}"
