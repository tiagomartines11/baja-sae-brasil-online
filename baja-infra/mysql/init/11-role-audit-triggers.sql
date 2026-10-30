-- role_audit is append-only in MySQL itself (BAJAAPP-12), for the dev
-- database.
--
-- Runs once, on the first boot of the mysql container, after 10. The same
-- SQL as PropelMigration_1793100000 (which explains what the triggers do
-- and do not stop), and records that migration as applied so
-- `propel migration:migrate` on a fresh dev database does not run it again.

USE baja_resultados;

CREATE TRIGGER IF NOT EXISTS `role_audit_no_update` BEFORE UPDATE ON `role_audit`
    FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'role_audit is append-only: its rows cannot be changed (BAJAAPP-12)';

CREATE TRIGGER IF NOT EXISTS `role_audit_no_delete` BEFORE DELETE ON `role_audit`
    FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'role_audit is append-only: its rows cannot be deleted (BAJAAPP-12)';

INSERT INTO `propel_migration` (`execution_datetime`, `version`) VALUES
  (NULL, 1793100000);
