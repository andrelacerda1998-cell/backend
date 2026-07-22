# Checklist — validar a pipeline pela primeira vez

Segue por ordem. Não avances para o passo seguinte se o anterior não tiver passado.

## 0. Antes de começar

- [ ] `git push` feito (o commit do workflow já está pronto localmente, só falta subir)
- [ ] Secrets configurados em **GitHub → repo `backend` → Settings → Secrets and variables → Actions**:
  - [ ] `PAYSHOP_SDK_PAT` — PAT com acesso de leitura ao repo `payshop-sdk`
  - [ ] `PROD_ENV_FILE` — conteúdo completo de `credentials/backend/.env.production`
  - [ ] `DEPLOY_HOST` — `149.36.249.12`
  - [ ] `DEPLOY_USER` — `deployer`
  - [ ] `DEPLOY_SSH_KEY` — chave privada SSH (idealmente um par novo, só para o Actions — ver nota no `DEPLOY.md`)

## 1. Correr só o build (sem tocar no servidor)

- [ ] GitHub → separador **Actions** → workflow "Deploy Piquet backend (produção)" → **Run workflow**
- [ ] Deixar a checkbox **"deploy"** desmarcada
- [ ] Clicar **Run workflow**
- [ ] Acompanhar o job **`test`** — tem de ficar verde (testes a passar)
- [ ] Acompanhar o job **`build-base`** — verde. Se for a primeira vez, vai buildar do zero (demora mais); em runs seguintes, se o `base.Dockerfile` não mudou, deve saltar o build (log diz algo como "skipped" ou o step de build nem corre)
- [ ] Acompanhar o job **`build-app`** — verde
- [ ] Confirmar que o job **`deploy` não corre** (deve aparecer "skipped" — é o comportamento esperado com a checkbox desmarcada)

**Se algo falhar aqui:** o erro mais provável é `PAYSHOP_SDK_PAT` sem permissão para o repo `payshop-sdk` (erro tipo "repository not found" ou "403" no step de checkout). Cola-me o log do step que falhou.

## 2. Confirmar as imagens no ghcr.io

- [ ] GitHub → o teu perfil (ou organização) → separador **Packages**
- [ ] Aparece `piquet-laravel-base` com pelo menos uma versão
- [ ] Aparece `piquet-laravel` com uma tag no formato `<numero>-<sha curto>` e outra `latest`
- [ ] Tamanho da imagem parece plausível (algumas centenas de MB, não 0 nem vários GB)

## 3. Deploy real (só depois do passo 1 e 2 passarem)

- [ ] GitHub → Actions → **Run workflow** outra vez
- [ ] Desta vez, marcar a checkbox **"deploy"**
- [ ] Acompanhar o job **`deploy`** linha a linha:
  - [ ] "Copiar .env para o servidor" — verde
  - [ ] "Deploy via SSH" — verde, sem erro no `docker compose pull` nem `docker compose up -d`

**Se falhar aqui:** provavelmente `DEPLOY_SSH_KEY` mal formatada (tem de incluir as linhas `-----BEGIN...-----` e `-----END...-----`) ou o `sed` não encontrou a linha `image:` esperada no `docker-compose.yaml` do servidor — nesse caso o deploy "passa" mas a imagem não muda (ver passo 4).

## 4. Confirmar no servidor que mudou mesmo

SSH para o servidor:

```bash
ssh -i ~/.ssh/piquet_prod deployer@149.36.249.12
cd ~/project-laravel
```

- [ ] `grep image docker-compose.yaml` → mostra a tag nova (`ghcr.io/.../piquet-laravel:<numero>-<sha>`), não a antiga (`devopsrwinteractive/piquet-laravel-prod:355`)
- [ ] `docker compose ps` → o serviço da app está "Up", com horário de arranque recente (agora, não há dias)
- [ ] `docker compose logs --tail=50 <nome-do-serviço-da-app>` → sem stack traces nem erros de arranque
- [ ] `docker image prune -f` já correu (o step final do deploy faz isto automaticamente — confirma que não ficaram imagens antigas acumuladas)

## 5. Confirmar que a aplicação real funciona

- [ ] `curl -I https://app.piquetapp.com` → `200` ou `302`, não `502`/`503`
- [ ] Abrir `https://app.piquetapp.com/backoffice/login` no browser, login com as credenciais reais → entra
- [ ] Abrir a app vendor (ou costumer) apontada para produção, fazer login com um utilizador real → funciona

## Se algo correr mal depois do deploy

O servidor tem backups do `docker-compose.yaml` anterior (`docker-compose.yaml.bak-20260710-*`). Rollback manual:

```bash
cd ~/project-laravel
cp docker-compose.yaml.bak-20260710-220741 docker-compose.yaml   # o mais recente
docker compose up -d
```

(Isto só reverte a referência da imagem — a imagem antiga `devopsrwinteractive/piquet-laravel-prod:355` só vai continuar a existir localmente no servidor se o `docker image prune -f` de deploys anteriores não a tiver apagado. Vale a pena confirmar com `docker images` antes de precisares disto.)
