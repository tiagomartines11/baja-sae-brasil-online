-- Event result archives (BAJAAPP-67) for the dev database.
--
-- Runs once, on the first boot of the mysql container, after 06. Creates
-- evento_arquivamento as PropelMigration_1792929600 leaves it on an
-- existing database (schema.xml explains the table), and records that
-- migration as applied so `propel migration:migrate` on a fresh dev
-- database does not try to create it again. No rows: a request appears
-- when an admin marks an event finalizado in juiz/evento.php.

USE baja_resultados;

-- >>> DDL
CREATE TABLE IF NOT EXISTS `evento_arquivamento` (
  `arquivamento_id` bigint NOT NULL AUTO_INCREMENT,
  `evento_id` char(4) CHARACTER SET latin1 NOT NULL,
  `pedido_em_ms` bigint NOT NULL,
  `pedido_por` varchar(45) DEFAULT NULL,
  `estado` varchar(12) NOT NULL DEFAULT 'pendente',
  `concluido_em_ms` bigint DEFAULT NULL,
  `pasta` varchar(255) DEFAULT NULL,
  `tentativas` int NOT NULL DEFAULT '0',
  `ultimo_erro` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`arquivamento_id`),
  KEY `evento_arquivamento_estado_idx` (`estado`,`pedido_em_ms`),
  KEY `evento_arquivamento_evento_idx` (`evento_id`,`pedido_em_ms`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- <<< DDL

INSERT INTO `propel_migration` (`execution_datetime`, `version`) VALUES
  (NULL, 1792929600);
