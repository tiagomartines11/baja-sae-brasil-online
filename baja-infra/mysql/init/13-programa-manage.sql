-- programa.manage for platform_admin (BAJAAPP-90), for the dev database.
--
-- Runs once, on the first boot of the mysql container, after 12. The same
-- SQL as PropelMigration_1793120000 (which explains the permission), and
-- records that migration as applied so `propel migration:migrate` on a
-- fresh dev database does not run it again.

USE baja_resultados;

INSERT IGNORE INTO `role_permission` (`role_id`, `permission`)
SELECT `role_id`, 'programa.manage' FROM `role` WHERE `code` = 'platform_admin';

INSERT INTO `role_audit` (`occurred_at`, `actor_user_id`, `action`, `role_id`, `details`)
SELECT NOW(), NULL, 'role_update', `role_id`,
       JSON_OBJECT('code', `code`, 'added', JSON_ARRAY('programa.manage'), 'source', 'PropelMigration_1793120000')
FROM `role` WHERE `code` = 'platform_admin';

INSERT INTO `propel_migration` (`execution_datetime`, `version`) VALUES
  (NULL, 1793120000);
