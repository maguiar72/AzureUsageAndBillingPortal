-- =====================================================================
--  Migracao: adiciona a coluna `service_plans` em `license_skus`.
--
--  Guarda (JSON) as funcionalidades de cada plano (service plans) para a
--  pagina de detalhe (plano.php). Bancos NOVOS ja recebem a coluna pelo
--  schema.sql; rode este script apenas em bancos EXISTENTES.
--
--  Uso:
--    mysql -h <host> -u <user> -p azure_portal < sql/migrations/2026-07-add-service-plans.sql
--
--  MySQL 8 / MariaDB nao suportam "ADD COLUMN IF NOT EXISTS" de forma
--  portavel, entao o ALTER e condicionado via INFORMATION_SCHEMA para
--  poder ser reexecutado sem erro.
-- =====================================================================

USE `azure_portal`;

SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'license_skus'
     AND COLUMN_NAME  = 'service_plans'
);

SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE `license_skus` ADD COLUMN `service_plans` TEXT NULL AFTER `capability_status`',
  'SELECT ''service_plans ja existe; nada a fazer.''');

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
