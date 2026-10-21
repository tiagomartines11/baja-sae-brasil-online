<?php
use Propel\Generator\Manager\MigrationManager;

/**
 * Written by hand, not taken from migration:diff, for the reason
 * PropelMigration_1787052969 records: the diff also emits the drift that
 * predates this branch, and this migration has no business altering other
 * tables.
 *
 * The lap counting tables exactly as they first shipped (commit 90ead59, the
 * hand-run baja-infra/mysql/migrations/2026-contagem.sql of that day). Every
 * column added since is PropelMigration_1792553082, so that one pair of
 * migrations brings either kind of server to the same schema:
 *   - a database that already has these tables from that file (staging):
 *     every statement here is CREATE TABLE IF NOT EXISTS and does nothing,
 *     and _1792553082 adds the newer columns;
 *   - a database that has none (production): this creates them, and
 *     _1792553082 then alters them like any other.
 * A database whose tables are NEWER than 90ead59 (one that ran a later copy
 * of that file, with its "Columns added later" block) must record
 * _1792553082 as applied instead of running it: see docs/contagem.md,
 * Deploy notes.
 *
 * Character sets. The tables are utf8mb4, like the connection. evento_id and
 * prova_id are latin1 because they are foreign keys to `prova`, whose
 * columns are latin1 in every database descended from the production dump,
 * and MySQL refuses a foreign key between columns of different character
 * sets (error 3780). Tokens and uuids are ascii: hex and dashes, compared
 * byte by byte.
 *
 * The raw record of a race (sources, entries, race-control events,
 * decisions) is ON DELETE RESTRICT towards `prova`, so deleting a prova that
 * has lap counting data fails instead of erasing the evidence a protest
 * would need. contagem_arquivo is where "Zerar corrida" keeps the rows it
 * deletes, as JSON, and has no foreign keys on purpose.
 *
 * The SQL below carries no comments: Propel splits it on semicolons and
 * tracks quotes while doing so, and an apostrophe in a comment would throw
 * the split off.
 *
 * Data object containing the SQL and PHP code to migrate the database
 * up to version 1792553081.
 */
class PropelMigration_1792553081{
    /**
     * @var string
     */
    public $comment = 'contagem: the lap counting tables as first shipped (90ead59)';

    /**
     * @param \Propel\Generator\Manager\MigrationManager $manager
     *
     * @return null|false|void
     */
    public function preUp(MigrationManager $manager)
    {
        // add the pre-migration code here
    }

    /**
     * @param \Propel\Generator\Manager\MigrationManager $manager
     *
     * @return null|false|void
     */
    public function postUp(MigrationManager $manager)
    {
        // add the post-migration code here
    }

    /**
     * @param \Propel\Generator\Manager\MigrationManager $manager
     *
     * @return null|false|void
     */
    public function preDown(MigrationManager $manager)
    {
        // add the pre-migration code here
    }

    /**
     * @param \Propel\Generator\Manager\MigrationManager $manager
     *
     * @return null|false|void
     */
    public function postDown(MigrationManager $manager)
    {
        // add the post-migration code here
    }

    /**
     * Get the SQL statements for the Up migration
     *
     * @return array list of the SQL strings to execute for the Up migration
     *               the keys being the datasources
     */
    public function getUpSQL(): array
    {
        $connection_resultados = <<< 'EOT'
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
EOT;

        return [
            'resultados' => $connection_resultados,
        ];
    }

    /**
     * Get the SQL statements for the Down migration
     *
     * DESTROYS DATA. Going down drops every lap counting table, with every
     * source, entry, decision and counted lap in it, and nothing keeps a
     * copy (contagem_arquivo goes too). It exists to undo a deploy on a
     * server that has not counted a race yet. On one that has, dump the
     * contagem_* tables first.
     *
     * Children before parents, so that no foreign key is in the way.
     *
     * @return array list of the SQL strings to execute for the Down migration
     *               the keys being the datasources
     */
    public function getDownSQL(): array
    {
        $connection_resultados = <<< 'EOT'
DROP TABLE IF EXISTS `contagem_total`;
DROP TABLE IF EXISTS `contagem_passagem`;
DROP TABLE IF EXISTS `contagem_dispositivo`;
DROP TABLE IF EXISTS `contagem_fonte_usuario`;
DROP TABLE IF EXISTS `contagem_fonte`;
DROP TABLE IF EXISTS `contagem_ocorrencia`;
DROP TABLE IF EXISTS `contagem_decisao`;
DROP TABLE IF EXISTS `contagem_volta`;
DROP TABLE IF EXISTS `contagem_arquivo`;
EOT;

        return [
            'resultados' => $connection_resultados,
        ];
    }

}
