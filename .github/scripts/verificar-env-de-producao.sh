#!/usr/bin/env bash
# Verifica um .env de produção ANTES de ele ir para o servidor.
#
# O deploy substitui o .env do servidor pelo segredo PROD_ENV_FILE. Se esse
# segredo ficar incompleto -- colar só uma linha nova no "Update" do GitHub,
# que substitui o valor inteiro, é um engano fácil -- o servidor perdia a base
# de dados, as chaves e os pagamentos no deploy seguinte.
#
# Nunca escreve valores: só nomes de variáveis e contagens.
set -euo pipefail

FICHEIRO="${1:?uso: verificar-env-de-producao.sh <ficheiro .env>}"

# As que a app não dispensa em produção.
ESSENCIAIS=(APP_KEY APP_ENV DB_HOST DB_DATABASE DB_USERNAME DB_PASSWORD JWT_SECRET PAYSHOP_SDK_API_KEY ADMIN_API_TOKEN)
# Um .env de produção a sério tem dezenas de variáveis; menos do que isto é um ficheiro cortado.
MINIMO=20

if [ ! -s "$FICHEIRO" ]; then
  echo "::error::O .env de produção está vazio."
  exit 1
fi

nomes=$(grep -E '^[A-Za-z_][A-Za-z0-9_]*=' "$FICHEIRO" | cut -d= -f1 | sort -u)
total=$(printf '%s\n' "$nomes" | grep -c . || true)
echo "Variáveis no .env: $total"
echo "Nomes: $(printf '%s ' $nomes)"

falhou=0
if [ "$total" -lt "$MINIMO" ]; then
  echo "::error::Só $total variáveis (mínimo $MINIMO): o PROD_ENV_FILE parece cortado."
  falhou=1
fi

for v in "${ESSENCIAIS[@]}"; do
  if ! grep -qE "^${v}=.+" "$FICHEIRO"; then
    echo "::error::Falta $v (ou está vazia) no PROD_ENV_FILE."
    falhou=1
  fi
done

# O URL do aviso do Payshop, se lá estiver, tem de ser um URL a sério: um
# "<...>" esquecido ou um espaço podiam fazer o Paylands recusar as ordens.
url=$(grep -E '^PAYSHOP_SDK_NOTIFICATION_URL=' "$FICHEIRO" | tail -1 | cut -d= -f2- || true)
if [ -n "$url" ]; then
  if ! printf '%s' "$url" | grep -qE '^https://[^[:space:]<>"]+$'; then
    echo "::error::PAYSHOP_SDK_NOTIFICATION_URL não é um URL válido (tem de começar por https:// e não ter espaços, < ou >)."
    falhou=1
  elif ! printf '%s' "$url" | grep -qE '[?&]key=[A-Za-z0-9_-]{16,}'; then
    echo "::error::PAYSHOP_SDK_NOTIFICATION_URL não tem a chave do webhook (?key=...) completa."
    falhou=1
  else
    echo "PAYSHOP_SDK_NOTIFICATION_URL: presente e com formato válido."
  fi
else
  echo "PAYSHOP_SDK_NOTIFICATION_URL: não definida (as ordens são criadas sem aviso)."
fi

if [ "$falhou" -ne 0 ]; then
  echo "::error::.env de produção recusado: nada foi enviado para o servidor."
  exit 1
fi
echo "OK: o .env de produção tem o essencial."
