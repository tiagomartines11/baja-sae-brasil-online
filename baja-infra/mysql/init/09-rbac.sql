-- Roles and grants (RBAC core, BAJAAPP-12) for the dev database.
--
-- Runs once, on the first boot of the mysql container, after 08. The same
-- SQL as PropelMigration_1793080000 (schema.xml and docs/rbac.md explain
-- the tables and the starting roles), and records that migration as
-- applied so `propel migration:migrate` on a fresh dev database does not
-- run it again.
--
-- The seed's only user with the legacy 'admin' code, superadmin, gets the
-- platform admin role on the platform node; nobody else gets anything.

USE baja_resultados;

-- The file is UTF-8 and the role names below contain accented text.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `role`
(
    `role_id` INTEGER NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(40) NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `description` VARCHAR(500),
    `is_system` TINYINT(1) DEFAULT 0 NOT NULL,
    `allowed_scope_types` VARCHAR(200) NOT NULL,
    PRIMARY KEY (`role_id`),
    UNIQUE INDEX `role_code_unique` (`code`)
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_unicode_ci';

CREATE TABLE IF NOT EXISTS `role_permission`
(
    `role_id` INTEGER NOT NULL,
    `permission` VARCHAR(64) NOT NULL,
    PRIMARY KEY (`role_id`,`permission`),
    CONSTRAINT `role_permission_role_id`
        FOREIGN KEY (`role_id`)
        REFERENCES `role` (`role_id`)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_unicode_ci';

CREATE TABLE IF NOT EXISTS `user_role`
(
    `grant_id` BIGINT NOT NULL AUTO_INCREMENT,
    `user_id` INTEGER NOT NULL,
    `role_id` INTEGER NOT NULL,
    `scope_type` VARCHAR(16) NOT NULL,
    `scope_id` VARCHAR(64) NOT NULL,
    `granted_by` INTEGER,
    `granted_at` DATETIME NOT NULL,
    `valid_until` DATETIME,
    `revoked_at` DATETIME,
    `revoked_by` INTEGER,
    PRIMARY KEY (`grant_id`),
    INDEX `user_role_user_idx` (`user_id`, `revoked_at`),
    INDEX `user_role_scope_idx` (`scope_type`, `scope_id`),
    INDEX `user_role_role_id_idx` (`role_id`),
    CONSTRAINT `user_role_role_id`
        FOREIGN KEY (`role_id`)
        REFERENCES `role` (`role_id`)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_unicode_ci';

CREATE TABLE IF NOT EXISTS `role_audit`
(
    `audit_id` BIGINT NOT NULL AUTO_INCREMENT,
    `occurred_at` DATETIME NOT NULL,
    `actor_user_id` INTEGER,
    `action` VARCHAR(20) NOT NULL,
    `grant_id` BIGINT,
    `role_id` INTEGER,
    `target_user_id` INTEGER,
    `scope_type` VARCHAR(16),
    `scope_id` VARCHAR(64),
    `details` json,
    PRIMARY KEY (`audit_id`),
    INDEX `role_audit_grant_idx` (`grant_id`),
    INDEX `role_audit_occurred_idx` (`occurred_at`)
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_unicode_ci';

INSERT IGNORE INTO `role` (`role_id`, `code`, `name`, `description`, `is_system`, `allowed_scope_types`) VALUES
    (1, 'platform_admin', 'Administrador da plataforma', 'Tudo, em todos os programas e eventos, incluindo editar papéis.', 1, 'platform'),
    (2, 'program_manager', 'Gestor de programa', 'Tudo nos eventos de um programa (e dos programas abaixo dele), menos editar papéis.', 0, 'programa'),
    (3, 'event_manager', 'Gestor de evento', 'Tudo num evento, menos certificados.', 0, 'evento'),
    (4, 'head_judge', 'Juiz chefe', 'Lança notas e controla a contagem numa prova, e pode dar o papel de juiz nela.', 0, 'prova'),
    (5, 'judge', 'Juiz', 'Lança notas e controla a contagem numa prova.', 0, 'prova'),
    (6, 'queue_manager', 'Gestor de fila', 'Opera uma fila.', 0, 'fila'),
    (7, 'timer_manager', 'Gestor de cronômetros', 'Configura e opera os cronômetros de um evento.', 0, 'evento'),
    (8, 'certificate_manager', 'Gestor de certificados', 'Certificados dos eventos de um programa.', 0, 'programa');

INSERT IGNORE INTO `role_permission` (`role_id`, `permission`) VALUES
    (1, 'evento.manage'), (1, 'prova.configure'), (1, 'prova.enter_scores'), (1, 'resultado.edit'),
    (1, 'fila.manage'), (1, 'timer.manage'), (1, 'timer.operate'), (1, 'certificado.manage'),
    (1, 'premiacao.manage'), (1, 'equipe.manage'), (1, 'notificacao.send'), (1, 'contagem.count'),
    (1, 'contagem.pit_log'), (1, 'contagem.control'), (1, 'role.grant'), (1, 'role.manage'),

    (2, 'evento.manage'), (2, 'prova.configure'), (2, 'prova.enter_scores'), (2, 'resultado.edit'),
    (2, 'fila.manage'), (2, 'timer.manage'), (2, 'timer.operate'), (2, 'certificado.manage'),
    (2, 'premiacao.manage'), (2, 'equipe.manage'), (2, 'notificacao.send'), (2, 'contagem.count'),
    (2, 'contagem.pit_log'), (2, 'contagem.control'), (2, 'role.grant'),

    (3, 'evento.manage'), (3, 'prova.configure'), (3, 'prova.enter_scores'), (3, 'resultado.edit'),
    (3, 'fila.manage'), (3, 'timer.manage'), (3, 'timer.operate'),
    (3, 'premiacao.manage'), (3, 'equipe.manage'), (3, 'notificacao.send'), (3, 'contagem.count'),
    (3, 'contagem.pit_log'), (3, 'contagem.control'), (3, 'role.grant'),

    (4, 'prova.enter_scores'), (4, 'contagem.control'), (4, 'role.grant'),

    (5, 'prova.enter_scores'), (5, 'contagem.control'),

    (6, 'fila.manage'),

    (7, 'timer.manage'), (7, 'timer.operate'),

    (8, 'certificado.manage');

START TRANSACTION;

INSERT INTO `user_role` (`user_id`, `role_id`, `scope_type`, `scope_id`, `granted_by`, `granted_at`)
SELECT u.`user_id`, 1, 'platform', '', NULL, NOW()
FROM `user` u
WHERE u.`permissions` LIKE '%| admin |%'
  AND NOT EXISTS (
      SELECT 1 FROM `user_role` g
      WHERE g.`user_id` = u.`user_id` AND g.`role_id` = 1 AND g.`scope_type` = 'platform' AND g.`revoked_at` IS NULL
  );

INSERT INTO `role_audit` (`occurred_at`, `actor_user_id`, `action`, `grant_id`, `role_id`, `target_user_id`, `scope_type`, `scope_id`, `details`)
SELECT g.`granted_at`, NULL, 'grant', g.`grant_id`, g.`role_id`, g.`user_id`, g.`scope_type`, g.`scope_id`,
       JSON_OBJECT('role', 'platform_admin', 'valid_until', NULL, 'source', 'PropelMigration_1793080000: legacy admin code')
FROM `user_role` g
WHERE g.`granted_by` IS NULL AND g.`role_id` = 1
  AND NOT EXISTS (SELECT 1 FROM `role_audit` a WHERE a.`grant_id` = g.`grant_id`);

COMMIT;

INSERT INTO `propel_migration` (`execution_datetime`, `version`) VALUES
  (NULL, 1793080000);
