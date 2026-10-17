-- Lap counting (contagem de voltas): adds contagem_decisao.passagem_alvo_id,
-- the other entry of a "usar_tempo" decision (a crossing typed late).
--
-- Apply once to a baja_resultados database whose contagem_* tables came
-- from an earlier 2026-contagem.sql (for example the dev VPS):
--     mysql -u <user> -p baja_resultados < 2026-contagem-passagem-alvo.sql
--
-- Safe to run again: the column is added only when information_schema says
-- it is missing. Nothing else is touched, and no row changes. A database
-- created from the current 2026-contagem.sql already has the column, and
-- this file then does nothing.
--
-- Every engine input load reads this column, so a database without it fails
-- every recount and review call: apply this before deploying the code.

SET @falta := (
  SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'contagem_decisao'
    AND COLUMN_NAME = 'passagem_alvo_id'
);
SET @ddl := IF(@falta,
  'ALTER TABLE `contagem_decisao` ADD COLUMN `passagem_alvo_id` bigint DEFAULT NULL AFTER `passagem_id`',
  'DO 0');
PREPARE contagem_passagem_alvo FROM @ddl;
EXECUTE contagem_passagem_alvo;
DEALLOCATE PREPARE contagem_passagem_alvo;
