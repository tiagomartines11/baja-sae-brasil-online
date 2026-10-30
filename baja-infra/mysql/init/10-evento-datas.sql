-- An event's first and last day as dates (BAJAAPP-12) for the dev database.
--
-- Runs once, on the first boot of the mysql container, after 09. Leaves the
-- database as PropelMigration_1793090000 leaves an existing one (schema.xml,
-- evento.data_inicio, explains the columns), and records that migration as
-- applied so `propel migration:migrate` on a fresh dev database does not
-- run it again.
--
-- The dates are what that migration reads from each seed event's `data`
-- text; all 17 can be read. They are written out here instead of parsed,
-- like 08 lists the programs: only the migration reads the text, once.

USE baja_resultados;

-- >>> DDL
ALTER TABLE `evento`

  ADD `data_inicio` DATE AFTER `data`,

  ADD `data_fim` DATE AFTER `data_inicio`;
-- <<< DDL

UPDATE `evento` SET `data_inicio` = '2022-04-20', `data_fim` = '2022-04-24' WHERE `evento_id` = '22BR';
UPDATE `evento` SET `data_inicio` = '2022-11-04', `data_fim` = '2022-11-06' WHERE `evento_id` = '22NE';
UPDATE `evento` SET `data_inicio` = '2022-11-18', `data_fim` = '2022-11-19' WHERE `evento_id` = '22SE';
UPDATE `evento` SET `data_inicio` = '2022-11-12', `data_fim` = '2022-11-14' WHERE `evento_id` = '22SU';
UPDATE `evento` SET `data_inicio` = '2023-03-01', `data_fim` = '2023-03-05' WHERE `evento_id` = '23BR';
UPDATE `evento` SET `data_inicio` = '2023-10-27', `data_fim` = '2023-10-29' WHERE `evento_id` = '23NE';
UPDATE `evento` SET `data_inicio` = '2023-10-19', `data_fim` = '2023-10-22' WHERE `evento_id` = '23SE';
UPDATE `evento` SET `data_inicio` = '2023-11-03', `data_fim` = '2023-11-05' WHERE `evento_id` = '23SU';
UPDATE `evento` SET `data_inicio` = '2024-03-20', `data_fim` = '2024-03-24' WHERE `evento_id` = '24BR';
UPDATE `evento` SET `data_inicio` = '2024-10-11', `data_fim` = '2024-10-13' WHERE `evento_id` = '24NE';
UPDATE `evento` SET `data_inicio` = '2024-09-12', `data_fim` = '2024-09-15' WHERE `evento_id` = '24SE';
UPDATE `evento` SET `data_inicio` = '2024-11-15', `data_fim` = '2024-11-17' WHERE `evento_id` = '24SU';
UPDATE `evento` SET `data_inicio` = '2025-03-26', `data_fim` = '2025-03-30' WHERE `evento_id` = '25BR';
UPDATE `evento` SET `data_inicio` = '2025-10-17', `data_fim` = '2025-10-19' WHERE `evento_id` = '25NE';
UPDATE `evento` SET `data_inicio` = '2025-09-25', `data_fim` = '2025-09-28' WHERE `evento_id` = '25SE';
UPDATE `evento` SET `data_inicio` = '2025-11-20', `data_fim` = '2025-11-23' WHERE `evento_id` = '25SU';
UPDATE `evento` SET `data_inicio` = '2026-03-25', `data_fim` = '2026-03-29' WHERE `evento_id` = '26BR';

INSERT INTO `propel_migration` (`execution_datetime`, `version`) VALUES
  (NULL, 1793090000);
