# Portal de Uso e Faturamento Azure — Edição LAMP

Aplicação web em **PHP + MySQL** para hospedagem em servidor **LAMP**
(Linux, Apache, MySQL/MariaDB, PHP) que:

- **Extrai os dados de custo/uso da Azure automaticamente a cada 12 horas**
  (via cron), usando a **Azure Cost Management API**;
- Disponibiliza **relatórios públicos** (gráficos e tabelas) sem exigir login;
- Oferece um **botão “Atualizar”** que dispara uma nova extração sob demanda
  e recarrega os dados ao clicar.

É uma reimplementação, em stack LAMP, do projeto .NET original *Azure Usage
and Billing Insights (AUBI) Portal* (que usava Azure WebJobs + SQL Server +
PowerBI). A antiga API Commerce/RateCard/UsageAggregates usada pelo projeto
original foi descontinuada pela Microsoft; esta versão usa a **Cost
Management Query API** (`Microsoft.CostManagement/query`), atual e suportada.

---

## Arquitetura

```
Cron (a cada 12h) ─┐
                   ├─> bin/extract.php ─> AzureClient (OAuth2 + Cost Mgmt API)
Botão "Atualizar" ─┘                          │
                                              ▼
                                      MySQL (usage_records)
                                              │
                          public/api/*.php (JSON) ─> public/index.php (gráficos)
```

| Camada        | Arquivo(s)                                            |
|---------------|-------------------------------------------------------|
| Configuração  | `config/config.php` (a partir de `config.example.php`)|
| Banco         | `sql/schema.sql`, `src/Database.php`                   |
| Azure         | `src/AzureClient.php` (token + Cost Management API)    |
| Extração      | `src/Extractor.php`, `bin/extract.php`                 |
| Relatórios    | `src/ReportRepository.php`, `public/api/*.php`         |
| Front-end     | `public/index.php`, `public/assets/{app.js,style.css}`|

---

## Pré-requisitos

- Linux com Apache 2.4+
- PHP 7.4+ (testado com 8.x) com extensões **pdo_mysql** e **curl**
- MySQL 5.7+ ou MariaDB 10.3+
- Um **App Registration (Service Principal)** no Entra ID (Azure AD) com a role
  **Cost Management Reader** (ou **Reader**) atribuída em cada assinatura a
  monitorar.

### Criando o Service Principal (resumo)

No portal Azure → **Microsoft Entra ID → App registrations → New registration**.
Depois, em **Certificates & secrets**, gere um **client secret**. Anote:
`Directory (tenant) ID`, `Application (client) ID` e o valor do secret.

Em cada **Subscription → Access control (IAM) → Add role assignment**,
atribua **Cost Management Reader** ao app criado.

Via CLI (alternativa):

```bash
az ad sp create-for-rbac \
  --name "azure-portal-lamp" \
  --role "Cost Management Reader" \
  --scopes /subscriptions/<SUBSCRIPTION_ID>
```

---

## Instalação passo a passo

### 1. Copiar os arquivos

```bash
sudo mkdir -p /var/www/azure-portal
sudo cp -r LampPortal/* LampPortal/.htaccess /var/www/azure-portal/ 2>/dev/null || \
  sudo cp -r LampPortal/. /var/www/azure-portal/
cd /var/www/azure-portal
```

### 2. Criar o banco e o usuário MySQL

```bash
mysql -u root -p < sql/schema.sql

mysql -u root -p -e "
  CREATE USER IF NOT EXISTS 'azure_portal'@'localhost' IDENTIFIED BY 'UMA_SENHA_FORTE';
  GRANT SELECT, INSERT, UPDATE, DELETE ON azure_portal.* TO 'azure_portal'@'localhost';
  FLUSH PRIVILEGES;"
```

### 3. Configurar a aplicação

```bash
cp config/config.example.php config/config.php
nano config/config.php   # preencha db + azure (tenant, client, secret, subscriptions)
```

### 4. Permissões

```bash
sudo chown -R www-data:www-data /var/www/azure-portal
sudo chmod -R 755 /var/www/azure-portal
sudo chmod -R 775 /var/www/azure-portal/runtime   # gravável (locks/logs)
sudo chmod 640 /var/www/azure-portal/config/config.php
```

### 5. Configurar o Apache

Use o vhost de exemplo (`apache-vhost.example.conf`). **O `DocumentRoot`
aponta para `public/`** para que `config/`, `src/` e `runtime/` fiquem fora
do alcance da web.

```bash
sudo cp apache-vhost.example.conf /etc/apache2/sites-available/azure-portal.conf
sudo nano /etc/apache2/sites-available/azure-portal.conf   # ajuste ServerName/caminho
sudo a2enmod headers
sudo a2ensite azure-portal
sudo systemctl reload apache2
```

### 6. Primeira extração (teste)

```bash
sudo -u www-data php bin/extract.php manual
# Saída esperada: [OK] Extracao concluida: N registros atualizados.
```

Abra o site no navegador — os gráficos devem aparecer.

### 7. Agendar a extração a cada 12 horas

```bash
sudo crontab -u www-data -e
```

Cole (ajuste o caminho — ver `crontab.example`):

```cron
0 0,12 * * * /usr/bin/php /var/www/azure-portal/bin/extract.php cron >> /var/www/azure-portal/runtime/cron.log 2>&1
```

Roda às **00:00 e 12:00** (horário do servidor).

---

## Como funciona o botão “Atualizar”

`public/api/refresh.php` (POST):

1. Rejeita se houver uma extração **em andamento** (lock de arquivo);
2. Aplica um **intervalo mínimo** entre refreshes (`refresh_min_interval`,
   padrão 5 min) para evitar abuso do botão público;
3. Tenta rodar `bin/extract.php` em **segundo plano** (via `exec`); se `exec`
   estiver desabilitado, faz um **fallback síncrono**;
4. O front-end faz *polling* em `api/status.php` e recarrega os gráficos ao
   concluir.

A extração é **idempotente**: usa `INSERT ... ON DUPLICATE KEY UPDATE`, então
rodar o refresh várias vezes apenas atualiza os mesmos registros.

---

## Endpoints JSON públicos

| Endpoint                    | Método | Descrição                                  |
|-----------------------------|--------|--------------------------------------------|
| `api/summary.php?days=30`   | GET    | Cartões-resumo (custo total, contagens)    |
| `api/timeseries.php?days=30`| GET    | Série de custo diário                       |
| `api/by_service.php?days=30`| GET    | Custo por serviço, região e assinatura      |
| `api/status.php`            | GET    | Estado da extração atual/última             |
| `api/refresh.php`           | POST   | Dispara nova extração (botão Atualizar)     |

O parâmetro `days` aceita 1–365 (padrão 30).

---

## Segurança

- `config/config.php` contém segredos e está no `.gitignore`; mantenha-o com
  permissão restrita e **fora** do `DocumentRoot` (`public/`).
- Os relatórios são **públicos e somente-leitura**; o usuário MySQL da app não
  precisa de privilégios de DDL.
- O botão de refresh é protegido por lock + rate-limit. Se quiser restringi-lo
  ainda mais, proteja `api/refresh.php` com autenticação básica do Apache.
- Recomenda-se HTTPS (Let's Encrypt/Certbot) em produção.

---

## Personalização

- **Assinaturas monitoradas**, janela de extração (`lookback_days`), fuso e
  nome do site: em `config/config.php`.
- **Frequência da extração**: edite a linha do cron (ex.: `0 */6 * * *` para
  cada 6 horas).
- **Gráficos**: Chart.js é carregado via CDN em `public/index.php`. Para um
  ambiente sem internet de saída, baixe o `chart.umd.min.js` para
  `public/assets/` e ajuste o `src`.

---

## Solução de problemas

| Sintoma | Causa provável / solução |
|---------|--------------------------|
| `Configuracao ausente` | Você não criou `config/config.php`. |
| `Falha ao obter token` | tenant/client/secret incorretos, ou secret expirado. |
| `Erro na Cost Management API (HTTP 401/403)` | Service principal sem a role *Cost Management Reader* na subscription. |
| Gráficos vazios | Nenhuma extração rodou ainda — execute `php bin/extract.php manual`. |
| Refresh sempre síncrono/lento | `exec()` desabilitado no PHP; a extração roda no request. Reduza `lookback_days` ou habilite `exec`. |
| `Aguarde Ns...` no refresh | Rate-limit ativo (`refresh_min_interval`). |
