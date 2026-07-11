-- =====================================================================
--  Azure Usage & Billing Portal  -  LAMP edition
--  MySQL / MariaDB schema
--
--  Uso:
--    mysql -u root -p < sql/schema.sql
--
--  Cria o banco `azure_portal` e as tabelas necessarias para armazenar
--  os dados de custo/uso extraidos da Azure Cost Management API, com
--  detalhe por recurso (resource), grupo de recursos e servico, por dia.
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
--  Registros de custo por RECURSO x DIA.
--
--  Granularidade: (assinatura, dia, recurso, servico). Como o ResourceId
--  pode ser longo, a unicidade e garantida por um hash SHA-256 da chave
--  natural (record_hash), evitando o limite de 3072 bytes de indice.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `usage_records` (
  `id`               BIGINT        NOT NULL AUTO_INCREMENT,
  `record_hash`      CHAR(64)      NOT NULL,
  `subscription_id`  CHAR(36)      NOT NULL,
  `usage_date`       DATE          NOT NULL,
  `resource_id`      VARCHAR(600)  NOT NULL DEFAULT '',
  `resource_name`    VARCHAR(255)  NOT NULL DEFAULT '(sem recurso)',
  `resource_group`   VARCHAR(255)  NOT NULL DEFAULT '(sem grupo)',
  `resource_type`    VARCHAR(255)  NOT NULL DEFAULT '',
  `service_name`     VARCHAR(255)  NOT NULL DEFAULT 'Unknown',
  `cost`             DECIMAL(20,6) NOT NULL DEFAULT 0,
  `currency`         VARCHAR(10)   NOT NULL DEFAULT 'USD',
  `updated_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
                                   ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hash` (`record_hash`),
  KEY `ix_date`    (`usage_date`),
  KEY `ix_rg`      (`resource_group`),
  KEY `ix_service` (`service_name`),
  KEY `ix_sub`     (`subscription_id`),
  KEY `ix_resname` (`resource_name`),
  KEY `ix_restype` (`resource_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Historico das extracoes (auditoria e painel publico)
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
