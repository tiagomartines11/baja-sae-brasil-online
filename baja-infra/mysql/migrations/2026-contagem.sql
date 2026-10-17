-- Lap counting (contagem de voltas): creates the contagem_* tables.
--
-- Apply once to an existing baja_resultados database:
--     mysql -u <user> -p baja_resultados < 2026-contagem.sql
--
-- This file ONLY CREATES TABLES. Safe to run again: every statement is
-- CREATE TABLE IF NOT EXISTS, and nothing here touches an existing table or
-- row. For the same reason it does not alter a contagem_* table that
-- already exists: a database that got these tables from an earlier version
-- of this file (only development machines did) must drop them first.
--
-- The definitions mirror baja-php/schema.xml, which stays the source of
-- truth for the Propel classes. If one changes, change the other.
-- baja-infra/mysql/init/06-contagem.sql carries the same statements for a
-- fresh dev database (everything between the two "DDL" marker lines is
-- copied there verbatim).
--
-- Character sets. The tables are utf8mb4, like the connection. Two kinds of
-- column are deliberately something else:
--   * evento_id and prova_id are latin1, because they are foreign keys to
--     `prova`, whose columns are latin1, and MySQL refuses a foreign key
--     between columns of different character sets. CHECK THIS before
--     applying to production: `SHOW CREATE TABLE prova`. If prova is not
--     latin1 there, change "CHARACTER SET latin1" below to match it.
--   * tokens and uuids are ascii: they are hex and dashes, compared byte by
--     byte, and an ascii index is a quarter of the size.
--
-- Deleting a prova. The raw record of a race (sources, entries, race-control
-- events, decisions) is ON DELETE RESTRICT towards `prova`: deleting a prova
-- that has lap counting data fails instead of silently erasing the evidence
-- a protest would need. Remove the lap counting data on purpose first.
--
-- Times are BIGINT milliseconds since the Unix epoch (UTC).

-- >>> DDL
CREATE TABLE IF NOT EXISTS `contagem_fonte` (
  `fonte_id` int NOT NULL AUTO_INCREMENT,
  `evento_id` char(4) CHARACTER SET latin1 NOT NULL,
  `prova_id` char(3) CHARACTER SET latin1 NOT NULL,
  `nome` varchar(45) NOT NULL,
  `tipo` varchar(12) NOT NULL DEFAULT 'manual',
  `qualidade_tempo` varchar(8) NOT NULL DEFAULT 'humana',
  `ativa` tinyint(1) NOT NULL DEFAULT '1',
  `ordem` int NOT NULL DEFAULT '0',
  `desativada_em_ms` bigint DEFAULT NULL,
  PRIMARY KEY (`fonte_id`),
  KEY `contagem_fonte_prova_idx` (`evento_id`,`prova_id`),
  CONSTRAINT `contagem_fonte_prova` FOREIGN KEY (`evento_id`, `prova_id`) REFERENCES `prova` (`evento_id`, `prova_id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `contagem_fonte_usuario` (
  `fonte_id` int NOT NULL,
  `usuario` varchar(45) NOT NULL,
  `adicionado_por` varchar(45) DEFAULT NULL,
  `criado_em_ms` bigint NOT NULL,
  `liberado_em_ms` bigint DEFAULT NULL,
  PRIMARY KEY (`fonte_id`,`usuario`),
  KEY `contagem_fonte_usuario_usuario_idx` (`usuario`),
  CONSTRAINT `contagem_fonte_usuario_fonte` FOREIGN KEY (`fonte_id`) REFERENCES `contagem_fonte` (`fonte_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `contagem_dispositivo` (
  `dispositivo_id` int NOT NULL AUTO_INCREMENT,
  `fonte_id` int NOT NULL,
  `token_hash` char(64) CHARACTER SET ascii NOT NULL,
  `usuario` varchar(45) DEFAULT NULL,
  `rotulo` varchar(255) DEFAULT NULL,
  `criado_em_ms` bigint NOT NULL,
  `revogado_em_ms` bigint DEFAULT NULL,
  `fechado_em_ms` bigint DEFAULT NULL,
  `visto_em_ms` bigint DEFAULT NULL,
  `sincronizado_ate_ms` bigint DEFAULT NULL,
  PRIMARY KEY (`dispositivo_id`),
  UNIQUE KEY `contagem_dispositivo_token_hash_UNIQUE` (`token_hash`),
  KEY `contagem_dispositivo_fonte_idx` (`fonte_id`),
  CONSTRAINT `contagem_dispositivo_fonte` FOREIGN KEY (`fonte_id`) REFERENCES `contagem_fonte` (`fonte_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `contagem_passagem` (
  `passagem_id` bigint NOT NULL AUTO_INCREMENT,
  `fonte_id` int NOT NULL,
  `dispositivo_id` int NOT NULL,
  `uuid_cliente` char(36) CHARACTER SET ascii NOT NULL,
  `equipe_numero` int NOT NULL,
  `ts_ms` bigint NOT NULL,
  `qualidade_tempo` varchar(12) NOT NULL DEFAULT 'exato',
  `ts_dispositivo_ms` bigint NOT NULL,
  `ancora_servidor_ms` bigint DEFAULT NULL,
  `ancora_rtt_ms` int DEFAULT NULL,
  `ancora_idade_ms` bigint DEFAULT NULL,
  `digitacao_ms` int DEFAULT NULL,
  `modo_entrada` varchar(12) NOT NULL,
  `recebido_em_ms` bigint NOT NULL,
  PRIMARY KEY (`passagem_id`),
  UNIQUE KEY `contagem_passagem_fonte_uuid_UNIQUE` (`fonte_id`,`uuid_cliente`),
  KEY `contagem_passagem_fonte_ts_idx` (`fonte_id`,`ts_ms`),
  KEY `contagem_passagem_equipe_ts_idx` (`equipe_numero`,`ts_ms`),
  KEY `contagem_passagem_dispositivo_idx` (`dispositivo_id`),
  CONSTRAINT `contagem_passagem_fonte` FOREIGN KEY (`fonte_id`) REFERENCES `contagem_fonte` (`fonte_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `contagem_passagem_dispositivo` FOREIGN KEY (`dispositivo_id`) REFERENCES `contagem_dispositivo` (`dispositivo_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `contagem_ocorrencia` (
  `ocorrencia_id` bigint NOT NULL AUTO_INCREMENT,
  `evento_id` char(4) CHARACTER SET latin1 NOT NULL,
  `prova_id` char(3) CHARACTER SET latin1 NOT NULL,
  `tipo` varchar(24) NOT NULL,
  `equipe_numero` int DEFAULT NULL,
  `ts_ms` bigint NOT NULL,
  `usuario` varchar(45) DEFAULT NULL,
  `criado_em_ms` bigint NOT NULL,
  `substitui_id` bigint DEFAULT NULL,
  `uuid_cliente` char(36) CHARACTER SET ascii DEFAULT NULL,
  `qualidade_tempo` varchar(12) NOT NULL DEFAULT 'exato',
  `ts_dispositivo_ms` bigint DEFAULT NULL,
  `modo_entrada` varchar(16) DEFAULT NULL,
  `origem` varchar(16) DEFAULT NULL,
  PRIMARY KEY (`ocorrencia_id`),
  UNIQUE KEY `contagem_ocorrencia_uuid_UNIQUE` (`evento_id`,`prova_id`,`uuid_cliente`),
  KEY `contagem_ocorrencia_prova_ts_idx` (`evento_id`,`prova_id`,`ts_ms`),
  CONSTRAINT `contagem_ocorrencia_prova` FOREIGN KEY (`evento_id`, `prova_id`) REFERENCES `prova` (`evento_id`, `prova_id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `contagem_decisao` (
  `decisao_id` bigint NOT NULL AUTO_INCREMENT,
  `evento_id` char(4) CHARACTER SET latin1 NOT NULL,
  `prova_id` char(3) CHARACTER SET latin1 NOT NULL,
  `tipo` varchar(16) NOT NULL,
  `passagem_id` bigint DEFAULT NULL,
  `passagem_alvo_id` bigint DEFAULT NULL,
  `fonte_id` int DEFAULT NULL,
  `equipe_numero` int DEFAULT NULL,
  `ts_ms` bigint DEFAULT NULL,
  `ts_fim_ms` bigint DEFAULT NULL,
  `desfaz_decisao_id` bigint DEFAULT NULL,
  `origem` varchar(10) NOT NULL DEFAULT 'revisor',
  `usuario` varchar(45) DEFAULT NULL,
  `motivo` text,
  `criado_em_ms` bigint NOT NULL,
  PRIMARY KEY (`decisao_id`),
  KEY `contagem_decisao_prova_idx` (`evento_id`,`prova_id`),
  KEY `contagem_decisao_passagem_idx` (`passagem_id`),
  CONSTRAINT `contagem_decisao_prova` FOREIGN KEY (`evento_id`, `prova_id`) REFERENCES `prova` (`evento_id`, `prova_id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `contagem_volta` (
  `evento_id` char(4) CHARACTER SET latin1 NOT NULL,
  `prova_id` char(3) CHARACTER SET latin1 NOT NULL,
  `equipe_numero` int NOT NULL,
  `numero_volta` int NOT NULL,
  `ts_ms` bigint NOT NULL,
  `dispersao_ms` int NOT NULL DEFAULT '0',
  `fontes` int NOT NULL DEFAULT '0',
  `tempo_volta_ms` bigint DEFAULT NULL,
  `elegivel_melhor_volta` tinyint(1) NOT NULL DEFAULT '0',
  `estado` varchar(10) NOT NULL DEFAULT 'contada',
  PRIMARY KEY (`evento_id`,`prova_id`,`equipe_numero`,`numero_volta`),
  CONSTRAINT `contagem_volta_prova` FOREIGN KEY (`evento_id`, `prova_id`) REFERENCES `prova` (`evento_id`, `prova_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `contagem_total` (
  `fonte_id` int NOT NULL,
  `equipe_numero` int NOT NULL,
  `voltas` int NOT NULL,
  PRIMARY KEY (`fonte_id`,`equipe_numero`),
  CONSTRAINT `contagem_total_fonte` FOREIGN KEY (`fonte_id`) REFERENCES `contagem_fonte` (`fonte_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Where "Zerar corrida" puts the raw rows before deleting them: one row per
-- deleted row, kept as JSON. No foreign keys on purpose, so that nothing
-- can cascade into it. Nothing reads it; it exists to be queried by hand.
CREATE TABLE IF NOT EXISTS `contagem_arquivo` (
  `arquivo_id` bigint NOT NULL AUTO_INCREMENT,
  `evento_id` char(4) CHARACTER SET latin1 NOT NULL,
  `prova_id` char(3) CHARACTER SET latin1 NOT NULL,
  `arquivado_em_ms` bigint NOT NULL,
  `arquivado_por` varchar(45) DEFAULT NULL,
  `tabela` varchar(32) NOT NULL,
  `dados` json NOT NULL,
  PRIMARY KEY (`arquivo_id`),
  KEY `contagem_arquivo_prova_idx` (`evento_id`,`prova_id`,`arquivado_em_ms`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- <<< DDL
