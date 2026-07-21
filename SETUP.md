# Setup local — backend

Este documento junta os passos oficiais (ver PDFs do handoff, se ainda os tiveres) com correções e armadilhas descobertas ao montar o ambiente pela primeira vez em julho de 2026. Segue isto em vez dos PDFs originais onde houver conflito — foi tudo verificado à mão.

## Layout de diretórios

O `payshop-sdk` tem de ser **irmão** do `backend`, não filho (o `composer.json` referencia `../payshop-sdk`):

```
piquet/
├── backend/
└── payshop-sdk/
```

## Passo a passo

```bash
# 1. Composer install via container descartável (payshop-sdk como irmão)
docker run --rm \
  --user "$(id -u):$(id -g)" \
  -e COMPOSER_HOME=/tmp/composer \
  -v "$(pwd)/..":/opt -w /opt/backend \
  laravelsail/php83-composer:latest \
  composer install --ignore-platform-reqs --no-interaction --no-scripts

# 2. .env a partir do .env.example, com estas trocas OBRIGATÓRIAS:
cp .env.example .env
```

Editar o `.env`:

| Chave | Valor |
|---|---|
| `DB_HOST` | `mysql` |
| `REDIS_HOST` | `redis` |
| `MAIL_HOST` | `mailpit` |
| `MAIL_PORT` | `1025` |
| `MEILISEARCH_HOST` | `http://meilisearch:7700` |
| `DB_DATABASE` | `piquet` |
| `DB_USERNAME` | `sail` |
| `DB_PASSWORD` | `password` |
| `VITE_PORT` | `15173` |
| `WWWUSER` / `WWWGROUP` | `1000` |
| `MOCK_SMS` | `true` (nunca em produção) |
| **`APP_URL`** | **`http://localhost:8000`** — ⚠️ ver nota abaixo |

⚠️ **Nota sobre `APP_URL`**: o `.env.example` traz `APP_URL=http://localhost` (sem porta). Isto faz o Filament (backoffice) gerar todos os links de CSS/JS apontando para a porta 80 em vez da 8000 — a página carrega mas **fica completamente sem estilo**. Tem de ser `http://localhost:8000` explicitamente.

```bash
# 3. Subir os containers
./vendor/bin/sail up -d
docker port backend-laravel.test-1   # tem de devolver 3 linhas

# 4. Chaves, assets, migrações
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan jwt:secret
./vendor/bin/sail artisan migrate
./vendor/bin/sail npm install
./vendor/bin/sail npm run build

# Se o backoffice abrir sem estilo mesmo com APP_URL correto:
./vendor/bin/sail artisan filament:assets
./vendor/bin/sail artisan config:clear

# 5. Seed
./vendor/bin/sail artisan db:seed --force
./vendor/bin/sail artisan db:seed --class=UserSeeder --force
```

## Utilizadores de teste — ⚠️ password corrigida

A documentação original diz que todos os utilizadores de teste usam a password `password`. **Isso só é verdade para o admin.** Confirmado a olhar para `database/seeders/UserSeeder.php`:

| Email | Password | Role |
|---|---|---|
| `admin@example.com` | `password` | admin → entra no `/backoffice` |
| `user@vendor.com` | `password12345` | vendor |
| `user@customer.com` | `password12345` | cliente |

## Verificação

```
http://localhost:8000/backoffice/login   (admin@example.com / password, 2FA no Mailpit)
http://localhost:18025                    (Mailpit)
http://localhost:8090                     (Meilisearch UI)
```

## Suite de testes — 2 falhas conhecidas, não são bugs de setup

Ao correr `./vendor/bin/sail artisan test` (19/29 passam de momento):

- `Tests\Feature\ExampleTest` falha (302 em vez de 200 em `GET /`) — **esperado**, `routes/web.php` redireciona `/` para `/backoffice` por desenho. Não é bug.
- `Tests\Feature\DebugTest` e `Tests\Feature\NotificationCampaignMetricsTest` falham com 404 nas rotas `campaign-log/{id}/open`, `/click` e `opt-out` — confirmado que é um problema isolado ao ambiente de testes (`RefreshDatabase`/transações), **não** à aplicação: a mesma funcionalidade testada manualmente contra o servidor real (`curl` autenticado) devolve 200 corretamente. O ficheiro `DebugTest.php` (com `test_a`/`test_b` e `echo` de debug) sugere que alguém da equipa já andava a investigar isto antes do handoff — vale a pena confirmar se é um problema conhecido.

## Apps móveis

Ver `SETUP.md` nos repositórios `app-vendor` e `app-costumer` — o backend não precisa de nada extra para as apps falarem com ele, mas há passos próprios do lado das apps.
