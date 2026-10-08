SET @t8_audit_ip_migration_sql = IF(
  (SELECT COUNT(*)
   FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'audit_logs'
     AND column_name = 'ip_address') = 0,
  'ALTER TABLE `audit_logs` ADD COLUMN `ip_address` VARCHAR(45) NULL',
  'DO 0'
);
PREPARE t8_audit_ip_migration FROM @t8_audit_ip_migration_sql;
EXECUTE t8_audit_ip_migration;
DEALLOCATE PREPARE t8_audit_ip_migration;
