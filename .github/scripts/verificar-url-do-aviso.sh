#!/usr/bin/env bash
# O URL do aviso do Payshop (PAYSHOP_SDK_NOTIFICATION_URL), se existir, tem de
# ser um URL a sério: um "<...>" esquecido ou um espaço podiam fazer o
# Paylands recusar as ordens. Vazio é válido (as ordens saem sem aviso).
# Nunca escreve o valor.
set -euo pipefail
url="${1:-}"
if [ -z "$url" ]; then
  echo "PAYSHOP_SDK_NOTIFICATION_URL: não definida (as ordens são criadas sem aviso)."
  exit 0
fi
if ! printf '%s' "$url" | grep -qE '^https://[^[:space:]<>"]+$'; then
  echo "::error::PAYSHOP_SDK_NOTIFICATION_URL não é um URL válido (tem de começar por https:// e não ter espaços, < ou >)."
  exit 1
fi
if ! printf '%s' "$url" | grep -qE '[?&]key=[A-Za-z0-9_-]{16,}'; then
  echo "::error::PAYSHOP_SDK_NOTIFICATION_URL não tem a chave do webhook (?key=...) completa."
  exit 1
fi
echo "PAYSHOP_SDK_NOTIFICATION_URL: presente e com formato válido."
