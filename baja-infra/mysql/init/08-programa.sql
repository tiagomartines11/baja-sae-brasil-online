-- Competition programs (BAJAAPP-11) for the dev database.
--
-- Runs once, on the first boot of the mysql container, after 07. Leaves the
-- database as PropelMigration_1793070000 leaves an existing one (schema.xml,
-- programa, explains the table), and records that migration as applied so
-- `propel migration:migrate` on a fresh dev database does not run it again.
--
-- Every event in the seed (05) is a Baja SAE one, and they are listed by
-- name rather than picked by their code: no code works out a program from
-- an event code's spelling, and the migration did it once, for databases
-- that existed before. An event added to the seed later without a line here
-- makes the NOT NULL below fail, which is the point.

USE baja_resultados;

-- The file is UTF-8 and the program names below contain accented text.
SET NAMES utf8mb4;

-- >>> DDL
CREATE TABLE IF NOT EXISTS `programa`
(
    `programa_id` VARCHAR(16) CHARACTER SET latin1 NOT NULL,
    `parent_id` VARCHAR(16) CHARACTER SET latin1,
    `nome` VARCHAR(100) NOT NULL,
    `ordem` INTEGER DEFAULT 0 NOT NULL,
    `ativo` TINYINT(1) DEFAULT 1 NOT NULL,
    PRIMARY KEY (`programa_id`),
    INDEX `programa_parent_id_idx` (`parent_id`),
    CONSTRAINT `programa_parent_id`
        FOREIGN KEY (`parent_id`)
        REFERENCES `programa` (`programa_id`)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_unicode_ci';

ALTER TABLE `evento`
    ADD `programa_id` VARCHAR(16) CHARACTER SET latin1 NULL AFTER `carga_horaria`;
-- <<< DDL

INSERT INTO `programa` (`programa_id`, `parent_id`, `nome`, `ordem`, `ativo`) VALUES
    ('BAJA', NULL, 'Baja SAE', 10, 1),
    ('AERO', NULL, 'AeroDesign', 20, 1),
    ('AERO_FORUM', 'AERO', 'Fórum AeroDesign', 30, 1),
    ('FORMULA', NULL, 'Fórmula SAE', 40, 1),
    ('ELETROQUAD', NULL, 'Eletroquad', 50, 1),
    ('H2', NULL, 'H2 Challenge', 60, 1);

UPDATE `evento` SET `programa_id` = 'BAJA' WHERE `evento_id` IN (
    '22BR', '22NE', '22SE', '22SU',
    '23BR', '23NE', '23SE', '23SU',
    '24BR', '24NE', '24SE', '24SU',
    '25BR', '25NE', '25SE', '25SU',
    '26BR'
);

-- >>> DDL
ALTER TABLE `evento`
    MODIFY `programa_id` VARCHAR(16) CHARACTER SET latin1 NOT NULL;

CREATE INDEX `evento_programa_id_idx` ON `evento` (`programa_id`);

ALTER TABLE `evento` ADD CONSTRAINT `evento_programa_id`
    FOREIGN KEY (`programa_id`)
    REFERENCES `programa` (`programa_id`)
    ON UPDATE CASCADE
    ON DELETE RESTRICT;
-- <<< DDL

INSERT INTO `propel_migration` (`execution_datetime`, `version`) VALUES
  (NULL, 1793070000);
