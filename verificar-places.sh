#!/usr/bin/env bash
# Verifica se a pesquisa de morada esta a funcionar de ponta a ponta.
#
# Corre isto depois de pores a GOOGLE_MAPS_GEOCODING_API_KEY no .env e
# reiniciares o sail. Diz exatamente onde e que a coisa parte, em vez de
# deixar a app a devolver uma lista vazia sem explicacao.
set -uo pipefail
cd "$(dirname "$0")"

echo "1/3  A chave esta no .env?"
LEN=$(awk -F= '/^GOOGLE_MAPS_GEOCODING_API_KEY=/ {print length($2)}' .env)
if [ "${LEN:-0}" -lt 20 ]; then
  echo "     NAO. A linha existe mas o valor tem ${LEN:-0} caracteres."
  echo "     Poe a chave em .env (linha GOOGLE_MAPS_GEOCODING_API_KEY=) e corre outra vez."
  exit 1
fi
echo "     Sim (${LEN} caracteres)."

echo "2/3  O Laravel ve a chave?"
VISTA=$(docker compose exec -T laravel.test php artisan tinker \
  --execute="echo config('geocoder.key') ? 'SIM' : 'NAO';" 2>/dev/null | tr -d '\r\n ')
if [[ "$VISTA" != *SIM* ]]; then
  echo "     NAO. O .env tem a chave mas a app nao a le."
  echo "     Reinicia: ./vendor/bin/sail restart"
  exit 1
fi
echo "     Sim."

echo "3/3  O Google responde com sugestoes?"
RESP=$(curl -s -m 25 -X POST http://localhost:8000/api/v1/common/places/autocomplete \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"input":"Avenida da Liberdade Lisboa"}')

# A app so mostra sugestoes se vier pelo menos uma com rua preenchida.
N=$(printf '%s' "$RESP" | python3 -c "import sys,json; print(len(json.load(sys.stdin).get('data',{}).get('predictions',[])))" 2>/dev/null || echo 0)
if [ "$N" -gt 0 ]; then
  echo "     Sim: $N sugestoes."
  printf '%s' "$RESP" | python3 -c "
import sys, json
p = json.load(sys.stdin)['data']['predictions'][0]
print('     Exemplo ->', p.get('description'))
print('     rua:', p.get('street_name'), '| cidade:', p.get('city'), '| CP:', p.get('postal_code'))"
  echo
  echo "TUDO OK. A pesquisa de morada na app ja funciona."
else
  echo "     NAO: zero sugestoes."
  echo "     A chave existe mas o Google recusou. Motivo no log:"
  tail -50 storage/logs/laravel.log | grep -i "places autocomplete" | tail -1 | sed 's/^/       /'
  echo "     Normalmente e a 'Places API' por ativar no projeto, ou faturacao por associar."
  exit 1
fi
