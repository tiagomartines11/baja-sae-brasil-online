-- Lap counting (contagem de voltas) for the dev database.
--
-- Runs once, on the first boot of the mysql container, after
-- 05-seed-baja-resultados.sql. It does two things:
--
--   1. creates the contagem_* tables. The block between the two "DDL"
--      marker lines is a verbatim copy of the same block in
--      baja-infra/mysql/migrations/2026-contagem.sql, which is what an
--      existing database gets and where the choices are explained; keep
--      both in step with baja-php/schema.xml;
--   2. turns the Enduro of the 26BR dev event into a lap-counted prova with
--      three counters, and gives the seeded forum users what they need to
--      try it. All of them log in with the password 123456.
--
-- Times are BIGINT milliseconds since the Unix epoch (UTC).

USE baja_resultados;

-- The file is UTF-8 and the seed below contains accented text.
SET NAMES utf8mb4;

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
  `papel` varchar(12) NOT NULL DEFAULT 'votante',
  `papel_desde_ms` bigint DEFAULT NULL,
  `periodos_fora` text,
  `imagem_base_url` varchar(255) DEFAULT NULL,
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
  `equipe_numero` int DEFAULT NULL,
  `ts_ms` bigint NOT NULL,
  `qualidade_tempo` varchar(12) NOT NULL DEFAULT 'exato',
  `ts_dispositivo_ms` bigint NOT NULL,
  `ancora_servidor_ms` bigint DEFAULT NULL,
  `ancora_rtt_ms` int DEFAULT NULL,
  `ancora_idade_ms` bigint DEFAULT NULL,
  `digitacao_ms` int DEFAULT NULL,
  `modo_entrada` varchar(12) NOT NULL,
  `recebido_em_ms` bigint NOT NULL,
  `imagem_ref` varchar(64) CHARACTER SET ascii DEFAULT NULL,
  `confianca` decimal(4,3) DEFAULT NULL,
  PRIMARY KEY (`passagem_id`),
  UNIQUE KEY `contagem_passagem_fonte_uuid_UNIQUE` (`fonte_id`,`uuid_cliente`),
  KEY `contagem_passagem_fonte_ts_idx` (`fonte_id`,`ts_ms`),
  KEY `contagem_passagem_equipe_ts_idx` (`equipe_numero`,`ts_ms`),
  KEY `contagem_passagem_equipe_fonte_idx` (`equipe_numero`,`fonte_id`,`ts_ms`),
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
-- ---------------------------------------------------------------------
-- Dev seed
-- ---------------------------------------------------------------------

-- The Enduro of 26BR becomes a laps prova. Its existing inputs (VOLTAS,
-- DESCLAS), points formula and min_max stay exactly as they are; LAST and
-- BEST are added right after VOLTAS, and the type is set.
--
-- The lap totals already imported for this prova are left alone, and stay
-- on the results page: the publisher writes nothing until race control
-- starts a race here.
--
-- The WHERE guard makes a second run a no-op instead of adding the two
-- inputs again.
UPDATE `prova`
   SET `params` = JSON_SET(
           JSON_ARRAY_INSERT(
               JSON_ARRAY_INSERT(
                   `params`,
                   '$.inputs[1]',
                   JSON_OBJECT('code', 'LAST', 'name', 'Última passagem (s)', 'type', 'number', 'precision', 0)
               ),
               '$.inputs[2]',
               JSON_OBJECT('code', 'BEST', 'name', 'Melhor volta (s)', 'type', 'number', 'precision', 0)
           ),
           '$.type', 'laps'
       )
 WHERE `evento_id` = '26BR'
   AND `prova_id` = 'END'
   AND JSON_SEARCH(`params`, 'one', 'LAST', NULL, '$.inputs[*].code') IS NULL;

-- Three independent human counters. The ids are fixed because the links
-- below, and tests/smoke-test.sh, refer to them.
INSERT INTO `contagem_fonte`
    (`fonte_id`, `evento_id`, `prova_id`, `nome`, `tipo`, `qualidade_tempo`, `ativa`, `ordem`)
VALUES
    (1, '26BR', 'END', 'Contagem 1', 'manual', 'humana', 1, 1),
    (2, '26BR', 'END', 'Contagem 2', 'manual', 'humana', 1, 2),
    (3, '26BR', 'END', 'Contagem 3', 'manual', 'humana', 1, 3)
ON DUPLICATE KEY UPDATE `nome` = VALUES(`nome`);

-- The forum says who somebody is; this table says what they may do here.
-- `permissions` is a Propel ARRAY column, stored as "| code | code |".
--
--   juiz1                controller of the Enduro (review, race control, ...)
--   the other four       counters: 26BR_END_CONT lets them into the count
--                        area, and the links below say on which source
INSERT INTO `user` (`username`, `permissions`)
VALUES
    ('juiz1',    '| index | 26BR_END |'),
    ('equipe1',  '| index | 26BR_END_CONT |'),
    ('equipe2',  '| index | 26BR_END_CONT |'),
    ('capitao1', '| index | 26BR_END_CONT |'),
    ('capitao2', '| index | 26BR_END_CONT |')
ON DUPLICATE KEY UPDATE `permissions` = VALUES(`permissions`);

-- One counter per source, plus capitao2 as a second person on source 1 so
-- that a handover (two people on one source) can be tried out.
INSERT INTO `contagem_fonte_usuario` (`fonte_id`, `usuario`, `adicionado_por`, `criado_em_ms`)
VALUES
    (1, 'equipe1',  'seed', UNIX_TIMESTAMP() * 1000),
    (2, 'equipe2',  'seed', UNIX_TIMESTAMP() * 1000),
    (3, 'capitao1', 'seed', UNIX_TIMESTAMP() * 1000),
    (1, 'capitao2', 'seed', UNIX_TIMESTAMP() * 1000)
ON DUPLICATE KEY UPDATE `adicionado_por` = VALUES(`adicionado_por`);
