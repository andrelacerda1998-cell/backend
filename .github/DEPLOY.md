# Pipeline de deploy (substitui o Jenkins da agência)

`workflows/deploy.yml` corre em cada push para `main` (ou manualmente via "Run workflow"). Faz o mesmo que o Jenkins fazia, mas em GitHub Actions + GitHub Container Registry (`ghcr.io`) em vez de Docker Hub:

1. **test** — sobe MySQL+Redis como serviços, corre `composer install` + `php artisan test`.
2. **build-base** — builda `infra/docker/base.Dockerfile` (a "golden image") só se o ficheiro mudou (tag = hash do próprio Dockerfile), e faz push para `ghcr.io/<owner>/piquet-laravel-base`.
3. **build-app** — builda `infra/docker/app.Dockerfile` a partir da base, com o código do `backend` + `payshop-sdk` (checkout como irmão, tal como o `composer.json` espera), push para `ghcr.io/<owner>/piquet-laravel` com tag `<run_number>-<sha curto>` e `latest`.
4. **deploy** — copia o `.env` de produção para o servidor, faz login no `ghcr.io` no servidor, troca a tag da imagem no `docker-compose.yaml` existente (`sed`, não reescreve o ficheiro todo), `docker compose pull laravel-prod && up -d laravel-prod` (só o serviço da app — ver incidente abaixo), e limpa imagens antigas.

## Incidente 2026-07-22: `docker compose pull` sem filtro derrubou a base de dados

No primeiro deploy real, o passo de deploy corria `docker compose pull` e `docker compose up -d` sem especificar o serviço. Isso puxa e recria **todos** os serviços do `docker-compose.yaml`, não só a app. A imagem do `mysql-laravel-prod` no compose de produção estava definida como `image: mysql` (sem tag = `mysql:latest`, flutuante). O pull trouxe uma major version mais recente do MySQL (o servidor já tinha sido inicializado em 9.2.0; o pull trouxe 9.7.1), o container foi recriado com essa versão nova, e o MySQL recusou-se a arrancar (`Data Dictionary initialization failed`) por incompatibilidade entre major versions do "data dictionary" — o servidor ficou em crash loop e a app parada à espera dele.

Recuperado sem perda de dados (o MySQL aborta antes de escrever/migrar nada quando deteta a incompatibilidade) — encontrámos a imagem antiga ainda em disco (tinha ficado "dangling" quando a tag `latest` mudou de digest), confirmámos a versão exata através de `mysql_upgrade_history` no volume de dados, e recriámos o `mysql-laravel-prod` apontado a essa imagem, agora fixada com uma tag estável (`mysql:9.2.0-piquet-restore`) em vez de `mysql` flutuante.

Duas correções ficaram feitas:
- O workflow agora só faz `pull`/`up -d` do serviço `laravel-prod` — nunca mexe em mysql/redis/meilisearch automaticamente.
- A imagem do `mysql-laravel-prod` no `docker-compose.yaml` do servidor ficou fixada (`mysql:9.2.0-piquet-restore`).

**Por fazer** (não bloqueia o pipeline, mas fica como risco conhecido): `redis-laravel-prod` (`redis:alpine`) e `meilisearch-laravel-prod` (`getmeili/meilisearch:latest`) continuam com tags flutuantes no `docker-compose.yaml` do servidor. Sobreviveram a este incidente porque não foram tocados desta vez, mas um `docker compose pull`/`up -d` manual sem filtro no servidor (fora do pipeline) pode repetir o mesmo problema com qualquer um deles. Fixar as duas tags às versões exatas atualmente a correr, da mesma forma que se fez para o mysql.

## Secrets a configurar no GitHub (Settings → Secrets and variables → Actions)

| Secret | Valor | Nota |
|---|---|---|
| `PAYSHOP_SDK_PAT` | Personal Access Token (classic ou fine-grained) com `repo` / leitura no repositório `payshop-sdk` | Necessário porque o `GITHUB_TOKEN` automático só tem acesso ao repo onde o workflow corre — `payshop-sdk` é outro repo. Criar em GitHub → Settings → Developer settings → Personal access tokens. |
| `PROD_ENV_FILE` | Conteúdo completo do `.env` de produção | O mesmo que está em `credentials/backend/.env.production` no handoff, **com uma correção**: a linha `INVOICE_XPRESS_PASSWORD=FiX$Piqu3t2024` tem de ficar `INVOICE_XPRESS_PASSWORD=FiX$$Piqu3t2024` (`$$` em vez de `$`). O Docker Compose interpola `$` dentro do `.env` como variável — sem escapar, ele tenta substituir `$Piqu3t2024` por uma variável de ambiente inexistente e corta a password para só `FiX`, partindo a integração com o InvoiceXpress silenciosamente. **Ação pendente:** atualizar este secret no GitHub com o `$$` corrigido. |
| `DEPLOY_HOST` | `149.36.249.12` | |
| `DEPLOY_USER` | `deployer` | |
| `DEPLOY_SSH_KEY` | Chave privada SSH do `deployer` | Recomendo gerar um par **dedicado ao GitHub Actions** (`ssh-keygen -t ed25519 -f github-actions-deploy`) e adicionar a pública ao `~/.ssh/authorized_keys` do `deployer` no servidor, em vez de reusar a `piquet_prod` pessoal. Cola a chave privada aqui. |

`GITHUB_TOKEN` (login no `ghcr.io` para build/push e para o `docker login` feito via SSH durante o deploy) já existe automaticamente, não precisa de ser criado — o workflow já declara `permissions: packages: write` explicitamente, não depende da configuração default do repo.

## Visibilidade das imagens no ghcr.io

Por default, pacotes no `ghcr.io` criados por um workflow ficam **privados**. Isso é bom para segurança, mas tem uma implicação: se um dia precisares de fazer `docker compose pull` **manualmente** no servidor (fora do pipeline), o `docker login` feito durante o deploy expira com o `GITHUB_TOKEN` do workflow — não fica guardado no servidor. Duas opções, a decidir mais tarde:

- Deixar como está: qualquer pull fora do pipeline exige correr `docker login ghcr.io` manualmente no servidor com um PAT válido.
- Tornar o pacote `piquet-laravel` público (Settings do pacote no GitHub) — mais simples para debugging manual, mas a imagem final inclui o código da aplicação (não segredos, esses vêm só do `.env` fora da imagem).

## Antes do primeiro push com este workflow

1. Confirmar que `payshop-sdk` é privado e que o `PAYSHOP_SDK_PAT` tem acesso a ele.
2. Gerar e configurar o par de chaves SSH dedicado ao Actions (ver tabela acima).
3. Confirmar no servidor que `~/project-laravel/docker-compose.yaml` tem uma linha `image: ...piquet-laravel...` para o serviço da app — é essa linha que o `sed` do passo de deploy substitui. Se o nome do serviço ou formato mudou entretanto, confirmar antes de confiar no primeiro deploy automático.
4. Correr o workflow uma vez manualmente (`workflow_dispatch`) e acompanhar o output do job `deploy` antes de depender dele para pushes normais.
