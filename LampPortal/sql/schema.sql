-- =====================================================================
--  Azure Usage & Billing Portal  -  LAMP edition
--  MySQL / MariaDB schema
--
--  Uso:
--    mysql -u root -p < sql/schema.sql
--
--  Cria o banco `azure_portal` e as tabelas necessarias para armazenar
--  os dados de custo/uso extraidos da Azure Cost Management API.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `azure_portal`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `azure_portal`;

-- ---------------------------------------------------------------------
--  Assinaturas (subscriptions) monitoradas
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `subscriptions` (
  `subscription_id` CHAR(36)      NOT NULL,
  `display_name`    VARCHAR(255)  NOT NULL DEFAULT '',
  `tenant_id`       CHAR(36)      NOT NULL,
  `is_active`       TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`subscription_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Registros de custo/uso agregados por dia / servico / regiao
--  A chave unica evita duplicidade ao re-extrair a mesma janela.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `usage_records` (
  `id`                BIGINT        NOT NULL AUTO_INCREMENT,
  `subscription_id`   CHAR(36)      NOT NULL,
  `usage_date`        DATE          NOT NULL,
  `service_name`      VARCHAR(255)  NOT NULL DEFAULT 'Unknown',
  `resource_location` VARCHAR(255)  NOT NULL DEFAULT 'Unknown',
  `meter_category`    VARCHAR(255)  NOT NULL DEFAULT '',
  `cost`              DECIMAL(20,6) NOT NULL DEFAULT 0,
  `currency`          VARCHAR(10)   NOT NULL DEFAULT 'USD',
  `updated_at`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  -- Indices de prefixo (100 chars) nas colunas de texto para caber no
  -- limite de 3072 bytes do InnoDB com utf8mb4. Os valores da Azure sao
  -- curtos, entao o prefixo garante a unicidade na pratica.
  UNIQUE KEY `uq_record` (`subscription_id`,`usage_date`,`service_name`(100),`resource_location`(100),`meter_category`(100)),
  KEY `ix_date`    (`usage_date`),
  KEY `ix_service` (`service_name`),
  KEY `ix_sub`     (`subscription_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Historico das extracoes (para auditoria e para o painel publico)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `extraction_log` (
  `id`             BIGINT       NOT NULL AUTO_INCREMENT,
  `started_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at`    DATETIME     NULL,
  `status`         ENUM('running','success','error') NOT NULL DEFAULT 'running',
  `trigger_source` ENUM('cron','manual') NOT NULL DEFAULT 'cron',
  `rows_upserted`  INT          NOT NULL DEFAULT 0,
  `message`        TEXT         NULL,
  PRIMARY KEY (`id`),
  KEY `ix_started` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
