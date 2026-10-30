-- The switch-over to RBAC (BAJAAPP-76) for the dev database.
--
-- Runs once, on the first boot of the mysql container, after 11. The same
-- roles and permission as PropelMigration_1793110000, and the grants that
-- migration gives the seed's users from their legacy codes (05 and 06),
-- and records that migration as applied so `propel migration:migrate` on a
-- fresh dev database does not run it again.
--
--   superadmin   admin                       platform_admin (already, from 09)
--   juiz1        26BR_END _MAN _ABS _VLT _PEN judge on those five provas of 26BR
--   juiz2        26BR_END _SPP _GRI _PEN      judge on those four
--   equipe1, equipe2,
--   capitao1, capitao2   26BR_END_CONT         lap_counter on 26BR/END
--   everyone     index                       nothing (the start page follows any grant in the event)
--
-- ONE DIFFERENCE from the migration: these grants have NO END. The
-- migration would end them 7 days after 26BR's data_fim (29 March 2026),
-- which has passed, and the dev stack's judges would be locked out of the
-- seed event. A real database gets real ends; see docs/rbac.md.
--
-- user.permissions is left as it is, as the migration leaves it.

USE baja_resultados;

-- The file is UTF-8 and the role names below contain accented text.
SET NAMES utf8mb4;

INSERT IGNORE INTO `role` (`code`, `name`, `description`, `is_system`, `allowed_scope_types`) VALUES
    ('lap_counter', 'Contador de voltas', 'Conta as voltas de uma prova com contagem de voltas, na fonte a que está vinculado.', 0, 'prova'),
    ('pit_logger', 'Registro de box', 'Registra as entradas e saídas do box (e o abastecimento) de uma prova com contagem de voltas.', 0, 'prova'),
    ('timer_operator', 'Operador de cronômetros', 'Opera os cronômetros de um evento.', 0, 'evento'),
    ('awards_viewer', 'Premiações (consulta)', 'Vê as premiações publicadas de um evento.', 0, 'evento');

INSERT IGNORE INTO `role_permission` (`role_id`, `permission`)
SELECT `role_id`, 'contagem.count' FROM `role` WHERE `code` = 'lap_counter'
UNION ALL SELECT `role_id`, 'contagem.pit_log' FROM `role` WHERE `code` = 'pit_logger'
UNION ALL SELECT `role_id`, 'timer.operate' FROM `role` WHERE `code` = 'timer_operator'
UNION ALL SELECT `role_id`, 'premiacao.view' FROM `role` WHERE `code` IN ('awards_viewer', 'platform_admin', 'program_manager', 'event_manager');

INSERT INTO `role_audit` (`occurred_at`, `actor_user_id`, `action`, `role_id`, `details`)
SELECT NOW(), NULL, 'role_create', `role_id`,
       JSON_OBJECT('code', `code`, 'source', 'PropelMigration_1793110000')
FROM `role` WHERE `code` IN ('lap_counter', 'pit_logger', 'timer_operator', 'awards_viewer');

INSERT INTO `role_audit` (`occurred_at`, `actor_user_id`, `action`, `role_id`, `details`)
SELECT NOW(), NULL, 'role_update', `role_id`,
       JSON_OBJECT('code', `code`, 'added', JSON_ARRAY('premiacao.view'), 'source', 'PropelMigration_1793110000')
FROM `role` WHERE `code` IN ('platform_admin', 'program_manager', 'event_manager');

START TRANSACTION;

-- (username, legacy code, role, node type, node id)
CREATE TEMPORARY TABLE `seed_grant` (
    `username` VARCHAR(45), `legacy_code` VARCHAR(64), `role` VARCHAR(40), `scope_type` VARCHAR(16), `scope_id` VARCHAR(64)
);
INSERT INTO `seed_grant` VALUES
    ('juiz1', '26BR_END', 'judge', 'prova', '26BR/END'),
    ('juiz1', '26BR_MAN', 'judge', 'prova', '26BR/MAN'),
    ('juiz1', '26BR_ABS', 'judge', 'prova', '26BR/ABS'),
    ('juiz1', '26BR_VLT', 'judge', 'prova', '26BR/VLT'),
    ('juiz1', '26BR_PEN', 'judge', 'prova', '26BR/PEN'),
    ('juiz2', '26BR_END', 'judge', 'prova', '26BR/END'),
    ('juiz2', '26BR_SPP', 'judge', 'prova', '26BR/SPP'),
    ('juiz2', '26BR_GRI', 'judge', 'prova', '26BR/GRI'),
    ('juiz2', '26BR_PEN', 'judge', 'prova', '26BR/PEN'),
    ('equipe1', '26BR_END_CONT', 'lap_counter', 'prova', '26BR/END'),
    ('equipe2', '26BR_END_CONT', 'lap_counter', 'prova', '26BR/END'),
    ('capitao1', '26BR_END_CONT', 'lap_counter', 'prova', '26BR/END'),
    ('capitao2', '26BR_END_CONT', 'lap_counter', 'prova', '26BR/END');

INSERT INTO `user_role` (`user_id`, `role_id`, `scope_type`, `scope_id`, `granted_by`, `granted_at`, `valid_until`)
SELECT u.`user_id`, r.`role_id`, s.`scope_type`, s.`scope_id`, NULL, NOW(), NULL
FROM `seed_grant` s
JOIN `user` u ON u.`username` = s.`username`
JOIN `role` r ON r.`code` = s.`role`;

INSERT INTO `role_audit` (`occurred_at`, `actor_user_id`, `action`, `grant_id`, `role_id`, `target_user_id`, `scope_type`, `scope_id`, `details`)
SELECT g.`granted_at`, NULL, 'grant', g.`grant_id`, g.`role_id`, g.`user_id`, g.`scope_type`, g.`scope_id`,
       JSON_OBJECT('role', s.`role`, 'valid_until', NULL, 'source', 'PropelMigration_1793110000: legacy code', 'legacy_code', s.`legacy_code`)
FROM `user_role` g
JOIN `user` u ON u.`user_id` = g.`user_id`
JOIN `role` r ON r.`role_id` = g.`role_id`
JOIN `seed_grant` s ON s.`username` = u.`username` AND s.`role` = r.`code` AND s.`scope_type` = g.`scope_type` AND s.`scope_id` = g.`scope_id`
WHERE g.`granted_by` IS NULL
  AND NOT EXISTS (SELECT 1 FROM `role_audit` a WHERE a.`grant_id` = g.`grant_id`);

DROP TEMPORARY TABLE `seed_grant`;

COMMIT;

INSERT INTO `propel_migration` (`execution_datetime`, `version`) VALUES
  (NULL, 1793110000);
