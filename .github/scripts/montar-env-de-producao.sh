#!/usr/bin/env bash
# Monta o .env de produção: o ficheiro do segredo PROD_ENV_FILE e, por cima,
# as variáveis guardadas em segredos próprios (para não ser preciso reescrever
# o ficheiro inteiro só para mudar uma linha).
#
# Entrada (ambiente): ENV_CONTENT (PROD_ENV_FILE), NOTIFICATION_URL (opcional,
# segredo PAYSHOP_SDK_NOTIFICATION_URL). Saída: o ficheiro em $1.
set -euo pipefail
DESTINO="${1:?uso: montar-env-de-producao.sh <destino>}"

printf '%s\n' "${ENV_CONTENT:-}" > "$DESTINO"

if [ -n "${NOTIFICATION_URL:-}" ]; then
  # O segredo próprio manda: tira a linha que o ficheiro tenha e põe a dele.
  grep -v '^PAYSHOP_SDK_NOTIFICATION_URL=' "$DESTINO" > "$DESTINO.tmp" || true
  mv "$DESTINO.tmp" "$DESTINO"
  printf 'PAYSHOP_SDK_NOTIFICATION_URL=%s\n' "$NOTIFICATION_URL" >> "$DESTINO"
fi
