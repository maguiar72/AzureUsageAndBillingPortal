# Deploy na Azure — Azure Container Apps (passo a passo)

Este guia hospeda o portal **100% na infraestrutura Azure**, de forma
moderna e sem segredos, usando:

| Componente | Serviço Azure | Papel |
|------------|---------------|-------|
| Portal web (relatórios públicos + botão Atualizar) | **Azure Container App** (Apache+PHP) | serve `public/` |
| Extração a cada 12 h | **Azure Container Apps Job** (cron nativo) | roda `php bin/extract.php cron` |
| Banco de dados | **Azure Database for MySQL Flexible Server** | armazena os dados |
| Autenticação na Azure | **Managed Identity** (user-assigned) | **sem client secret** |
| Imagem do container | **Azure Container Registry (ACR)** | 1 imagem para web e job |
| Observabilidade | **Log Analytics** (criado com o Environment) | logs |

**Por que esta arquitetura?** O Container Apps Job resolve o agendamento
(cron) de forma nativa e reaproveita exatamente o mesmo `bin/extract.php`;
a Managed Identity elimina o segredo do service principal; web e job usam
a **mesma imagem**; escala a zero (job) mantendo só o web ligado (barato).

```
                 ┌─────────────── Azure Container Apps Environment ──────────────┐
   Internet ───► │  Container App (web, Apache+PHP)  ── ingress externo (público) │
                 │        │ MySQL (TLS)                                            │
                 │        ▼                                                        │
                 │  Container Apps Job (cron 0 */12) ── php bin/extract.php cron   │
                 └────────┬───────────────────────────────────────────────────────┘
                          │ Managed Identity (Cost Management Reader)
                          ▼
              Azure Cost Management API   +   Azure DB for MySQL Flexible Server
```

> Todos os comandos são `bash` (Cloud Shell ou máquina com Azure CLI). No
> Windows use o Cloud Shell (bash) para copiar/colar sem ajustes.

---

## 0. Pré-requisitos (uma vez)

```bash
# Azure CLI atualizada e login
az upgrade --yes
az login
# Assinatura que será monitorada/faturada (pré-preenchida):
az account set --subscription "b1bfe399-f3df-402f-a27e-b33e73b4a776"

# Extensões necessárias
az extension add --name containerapp --upgrade
az extension add --name rdbms-connect --upgrade   # para rodar SQL no MySQL

# Registrar os resource providers (idempotente)
az provider register --namespace Microsoft.App
az provider register --namespace Microsoft.OperationalInsights
az provider register --namespace Microsoft.ContainerRegistry
az provider register --namespace Microsoft.DBforMySQL
```

---

## 1. Variáveis de ambiente do deploy

Edite os valores marcados e **cole o bloco inteiro** no terminal. Eles são
reutilizados nos passos seguintes.

```bash
# ---- Ajuste estes ----
export LOCATION="brazilsouth"                                    # Brazil South
export SUBSCRIPTION_ID="b1bfe399-f3df-402f-a27e-b33e73b4a776"    # assinatura a monitorar
export RG="rg-azure-portal"
export MYSQL_ADMIN="azadmin"
export MYSQL_ADMIN_PASS='Troque#Admin-$(openssl rand -hex 6)'   # senha forte
export APP_DB_USER="azure_portal"
export APP_DB_PASS='Troque#App-$(openssl rand -hex 6)'          # senha forte
export SITE_NAME="Portal de Uso e Faturamento Azure"
# ----------------------

# Nomes de recursos (ACR e MySQL precisam ser globalmente únicos)
RAND=$RANDOM
export ACR="acrazureportal${RAND}"
export MYSQL="mysql-azure-portal-${RAND}"
export ENVIRONMENT="cae-azure-portal"
export APP="azure-portal-web"
export JOB="azure-portal-extract"
export IDENTITY="id-azure-portal"
export DB_NAME="azure_portal"
export IMAGE="azure-portal"
export IMAGE_TAG="v1"

# Tenant derivado da conta logada (a assinatura já foi fixada acima)
export TENANT_ID=$(az account show --query tenantId -o tsv)

echo "RG=$RG  ACR=$ACR  MYSQL=$MYSQL  SUB=$SUBSCRIPTION_ID"
```

> As senhas acima usam `$(openssl rand ...)` só como exemplo — troque por
> senhas suas. Guarde `MYSQL_ADMIN_PASS` e `APP_DB_PASS`.

---

## 2. Grupo de recursos

```bash
az group create -n "$RG" -l "$LOCATION"
```

---

## 3. Container Registry + build da imagem (na nuvem, sem Docker local)

```bash
az acr create -g "$RG" -n "$ACR" --sku Basic

# Rode a partir da pasta LampPortal/ (onde está o Dockerfile).
cd LampPortal   # ajuste conforme onde clonou o repositório
az acr build -r "$ACR" -t "${IMAGE}:${IMAGE_TAG}" -f Dockerfile .
```

`az acr build` envia o contexto e **constrói dentro da Azure** (ACR Tasks),
então você não precisa de Docker instalado localmente.

---

## 4. Banco de dados — MySQL Flexible Server

```bash
# Servidor (Burstable B1ms = mais barato; ajuste conforme necessidade)
az mysql flexible-server create \
  -g "$RG" -n "$MYSQL" -l "$LOCATION" \
  --admin-user "$MYSQL_ADMIN" --admin-password "$MYSQL_ADMIN_PASS" \
  --tier Burstable --sku-name Standard_B1ms \
  --version 8.0.21 --storage-size 20 \
  --public-access None --yes

# Permitir que serviços do Azure (o Container App/Job) acessem o servidor
az mysql flexible-server firewall-rule create \
  -g "$RG" -n "$MYSQL" --rule-name AllowAzureServices \
  --start-ip-address 0.0.0.0 --end-ip-address 0.0.0.0

# Banco de dados
az mysql flexible-server db create -g "$RG" -s "$MYSQL" -d "$DB_NAME"
```

### 4.1 Carregar o schema e criar o usuário da aplicação

```bash
export DB_HOST="${MYSQL}.mysql.database.azure.com"
```

**Opção A — cliente `mysql` (recomendada; mais confiável no Cloud Shell).**
O Cloud Shell traz o cliente do **MariaDB**, que usa a flag `--ssl` (o cliente
Oracle MySQL usaria `--ssl-mode=REQUIRED`; no Cloud Shell isso dá erro
*"unknown variable 'ssl-mode'"*):

```bash
# Cria as tabelas (schema do repositório)
mysql -h "$DB_HOST" -u "$MYSQL_ADMIN" -p"$MYSQL_ADMIN_PASS" \
  --ssl "$DB_NAME" < sql/schema.sql

# Cria o usuário da aplicação (host '%' pois a app conecta remotamente)
mysql -h "$DB_HOST" -u "$MYSQL_ADMIN" -p"$MYSQL_ADMIN_PASS" \
  --ssl "$DB_NAME" -e "
CREATE USER IF NOT EXISTS '${APP_DB_USER}'@'%' IDENTIFIED BY '${APP_DB_PASS}';
GRANT SELECT, INSERT, UPDATE, DELETE ON ${DB_NAME}.* TO '${APP_DB_USER}'@'%';
FLUSH PRIVILEGES;"

# Verifica
mysql -h "$DB_HOST" -u "$MYSQL_ADMIN" -p"$MYSQL_ADMIN_PASS" \
  --ssl "$DB_NAME" -e "SHOW TABLES;"
```

> Use `-p"$MYSQL_ADMIN_PASS"` **sem espaço** após o `-p`. A flag `--ssl` ativa
> TLS (exigido pelo Azure MySQL). Se estiver usando o cliente Oracle MySQL em
> vez do MariaDB, troque `--ssl` por `--ssl-mode=REQUIRED`.

**Opção B — via Azure CLI** (exige a extensão `rdbms-connect`; sem ela o
comando falha com *"'execute' is misspelled or not recognized"*):

```bash
az extension add --name rdbms-connect --upgrade   # obrigatório para 'execute'

az mysql flexible-server execute \
  -n "$MYSQL" -u "$MYSQL_ADMIN" -p "$MYSQL_ADMIN_PASS" -d "$DB_NAME" \
  --file-path sql/schema.sql

az mysql flexible-server execute \
  -n "$MYSQL" -u "$MYSQL_ADMIN" -p "$MYSQL_ADMIN_PASS" -d "$DB_NAME" \
  -q "CREATE USER IF NOT EXISTS '${APP_DB_USER}'@'%' IDENTIFIED BY '${APP_DB_PASS}';
      GRANT SELECT, INSERT, UPDATE, DELETE ON ${DB_NAME}.* TO '${APP_DB_USER}'@'%';
      FLUSH PRIVILEGES;"
```

> O servidor exige TLS (`require_secure_transport=ON`) por padrão — a
> aplicação já conecta com TLS (o `Dockerfile` inclui o CA do Azure MySQL).

---

## 5. Managed Identity + permissões

```bash
# Identidade gerenciada atribuída pelo usuário (compartilhada por web e job)
az identity create -g "$RG" -n "$IDENTITY"

export IDENTITY_ID=$(az identity show -g "$RG" -n "$IDENTITY" --query id -o tsv)
export IDENTITY_CLIENT_ID=$(az identity show -g "$RG" -n "$IDENTITY" --query clientId -o tsv)
export IDENTITY_PRINCIPAL_ID=$(az identity show -g "$RG" -n "$IDENTITY" --query principalId -o tsv)

# (a) Ler dados de custo da assinatura -> "Cost Management Reader"
az role assignment create \
  --assignee-object-id "$IDENTITY_PRINCIPAL_ID" \
  --assignee-principal-type ServicePrincipal \
  --role "Cost Management Reader" \
  --scope "/subscriptions/${SUBSCRIPTION_ID}"

# (b) Puxar a imagem do ACR -> "AcrPull"
export ACR_ID=$(az acr show -n "$ACR" --query id -o tsv)
az role assignment create \
  --assignee-object-id "$IDENTITY_PRINCIPAL_ID" \
  --assignee-principal-type ServicePrincipal \
  --role "AcrPull" --scope "$ACR_ID"
```

> Para monitorar **várias assinaturas**, repita o passo (a) com o `--scope`
> de cada uma e liste todas em `AZURE_SUBSCRIPTION_IDS` (passo 7/8).
> A propagação das permissões pode levar 1–2 minutos.

---

## 6. Ambiente do Container Apps

```bash
az containerapp env create \
  -g "$RG" -n "$ENVIRONMENT" -l "$LOCATION"
```

Isso cria também um workspace do Log Analytics para os logs.

---

## 7. Container App (portal web, público)

> Feito em **3 etapas**: passar `--registry-identity` junto com
> `--user-assigned` no mesmo `create` causa um conflito de ordem na CLI
> (*"identity already assigned … does not exist"*). O padrão confiável é
> criar com uma imagem pública, apontar o registry para a identidade e só
> então trocar para a sua imagem.

```bash
# 7.1 Cria o app com imagem pública temporária + identidade + env vars
az containerapp create \
  -g "$RG" -n "$APP" \
  --environment "$ENVIRONMENT" \
  --image "mcr.microsoft.com/k8se/quickstart:latest" \
  --user-assigned "$IDENTITY_ID" \
  --target-port 80 --ingress external \
  --min-replicas 1 --max-replicas 2 \
  --cpu 0.5 --memory 1.0Gi \
  --secrets "db-pass=${APP_DB_PASS}" \
  --env-vars \
     "DB_HOST=${DB_HOST}" \
     "DB_NAME=${DB_NAME}" \
     "DB_USER=${APP_DB_USER}" \
     "DB_PASS=secretref:db-pass" \
     "DB_SSL=1" \
     "AZURE_AUTH_METHOD=managed_identity" \
     "AZURE_CLIENT_ID=${IDENTITY_CLIENT_ID}" \
     "AZURE_TENANT_ID=${TENANT_ID}" \
     "AZURE_SUBSCRIPTION_IDS=${SUBSCRIPTION_ID}" \
     "AZURE_LOOKBACK_DAYS=60" \
     "APP_SITE_NAME=${SITE_NAME}" \
     "APP_TIMEZONE=America/Sao_Paulo" \
     "APP_CURRENCY=USD"

# 7.2 Aponta o registry para autenticar via a Managed Identity
az containerapp registry set \
  -g "$RG" -n "$APP" \
  --server "${ACR}.azurecr.io" \
  --identity "$IDENTITY_ID"

# 7.3 Troca para a SUA imagem (o pull agora usa a identidade)
az containerapp update \
  -g "$RG" -n "$APP" \
  --image "${ACR}.azurecr.io/${IMAGE}:${IMAGE_TAG}"

# 7.4 URL pública do portal
export APP_URL=$(az containerapp show -g "$RG" -n "$APP" \
  --query properties.configuration.ingress.fqdn -o tsv)
echo "Portal: https://${APP_URL}"
```

> `AZURE_CLIENT_ID` **precisa** ser o `clientId` da identidade user-assigned
> para o token de Managed Identity usar a identidade certa.

---

## 8. Container Apps Job (extração agendada a cada 12 h)

> Mesmo padrão de 3 etapas do Passo 7 (a CLI tem o mesmo conflito ao juntar
> `--registry-identity` com a identidade no `create`).

```bash
# 8.1 Cria o job com imagem pública temporária + identidade + comando + env vars
az containerapp job create \
  -g "$RG" -n "$JOB" \
  --environment "$ENVIRONMENT" \
  --trigger-type Schedule \
  --cron-expression "0 */12 * * *" \
  --image "mcr.microsoft.com/k8se/quickstart:latest" \
  --mi-user-assigned "$IDENTITY_ID" \
  --cpu 0.5 --memory 1.0Gi \
  --replica-timeout 1800 --replica-retry-limit 1 \
  --command "php" "/var/www/html/bin/extract.php" "cron" \
  --secrets "db-pass=${APP_DB_PASS}" \
  --env-vars \
     "DB_HOST=${DB_HOST}" \
     "DB_NAME=${DB_NAME}" \
     "DB_USER=${APP_DB_USER}" \
     "DB_PASS=secretref:db-pass" \
     "DB_SSL=1" \
     "AZURE_AUTH_METHOD=managed_identity" \
     "AZURE_CLIENT_ID=${IDENTITY_CLIENT_ID}" \
     "AZURE_TENANT_ID=${TENANT_ID}" \
     "AZURE_SUBSCRIPTION_IDS=${SUBSCRIPTION_ID}" \
     "AZURE_LOOKBACK_DAYS=60" \
     "APP_CURRENCY=USD"

# 8.2 Aponta o registry para a Managed Identity
az containerapp job registry set \
  -g "$RG" -n "$JOB" \
  --server "${ACR}.azurecr.io" \
  --identity "$IDENTITY_ID"

# 8.3 Troca para a SUA imagem
az containerapp job update \
  -g "$RG" -n "$JOB" \
  --image "${ACR}.azurecr.io/${IMAGE}:${IMAGE_TAG}"
```

- `"0 */12 * * *"` → roda às **00:00 e 12:00 UTC**. (Para 06:00/18:00 no
  horário de Brasília, use `"0 9,21 * * *"`.)
- O job usa a **mesma imagem** do web, apenas trocando o comando.

---

## 9. Primeira carga e verificação

```bash
# Dispara o job manualmente para popular os dados agora (não espera o cron)
az containerapp job start -g "$RG" -n "$JOB"

# Acompanha as execuções do job
az containerapp job execution list -g "$RG" -n "$JOB" -o table

# Logs do job (console do extrator)
az containerapp job logs show -g "$RG" -n "$JOB" \
  --container "$JOB" --follow

# Logs do web
az containerapp logs show -g "$RG" -n "$APP" --follow
```

Abra `https://${APP_URL}` no navegador — os gráficos devem carregar. O
**botão “Atualizar”** dispara uma extração sob demanda dentro do container
web (com lock + rate-limit), reaproveitando o mesmo código.

---

## 10. Atualizar a aplicação (novas versões)

```bash
# Rebuild da imagem com nova tag e atualização do web e do job
export IMAGE_TAG="v2"
az acr build -r "$ACR" -t "${IMAGE}:${IMAGE_TAG}" -f Dockerfile .

az containerapp update     -g "$RG" -n "$APP" \
  --image "${ACR}.azurecr.io/${IMAGE}:${IMAGE_TAG}"
az containerapp job update -g "$RG" -n "$JOB" \
  --image "${ACR}.azurecr.io/${IMAGE}:${IMAGE_TAG}"
```

---

## 11. (Opcional) Domínio próprio + HTTPS

O ingress já entrega HTTPS no domínio `*.azurecontainerapps.io`. Para um
domínio próprio com certificado gerenciado grátis:

```bash
# 1) Crie um CNAME no seu DNS apontando portal.seudominio.com -> $APP_URL
# 2) Vincule o domínio e emita o certificado gerenciado:
az containerapp hostname add     -g "$RG" -n "$APP" --hostname portal.seudominio.com
az containerapp hostname bind    -g "$RG" -n "$APP" --hostname portal.seudominio.com \
  --environment "$ENVIRONMENT" --validation-method CNAME
```

---

## 12. Custos e limpeza

- **MySQL B1ms** é o maior item (~US$ 12–15/mês). O **web** com 1 réplica
  0.5 vCPU/1 GiB é barato; o **job** só custa quando roda (segundos, 2×/dia).
- Para desligar tudo e parar a cobrança:

```bash
az group delete -n "$RG" --yes --no-wait
```

---

## Solução de problemas

| Sintoma | Causa / solução |
|---------|-----------------|
| `az containerapp create` falha ao puxar a imagem | Aguarde 1–2 min a propagação do `AcrPull` e rode de novo, ou confira `--registry-identity`. |
| Job com status `Failed` e erro de token | A identidade não tem **Cost Management Reader** no escopo da assinatura, ou `AZURE_CLIENT_ID` não é o `clientId` da identidade. |
| Erro de conexão MySQL / TLS | Confirme a regra de firewall `AllowAzureServices` e `DB_SSL=1`; o host é `<nome>.mysql.database.azure.com`. |
| Gráficos vazios | Nenhuma extração rodou — `az containerapp job start -g $RG -n $JOB`. |
| “Aguarde Ns…” ao clicar Atualizar | Rate-limit (`APP_REFRESH_MIN_INTERVAL`, padrão 300 s). |
| Erro `AADSTS` no token | Só ocorre no modo `client_secret`; no Container Apps use `managed_identity`. |

---

## Resumo do que roda onde

| Item | Como é agendado/servido |
|------|-------------------------|
| Relatórios públicos | Container App (Apache+PHP), ingress externo HTTPS |
| Extração a cada 12 h | Container Apps Job, cron `0 */12 * * *` (UTC) |
| Botão “Atualizar” | `POST /api/refresh.php` no container web (lock + rate-limit) |
| Autenticação Azure | Managed Identity (Cost Management Reader) — sem segredos |
| Dados | Azure DB for MySQL Flexible Server (TLS) |
