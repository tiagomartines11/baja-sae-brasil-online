<?php
use Propel\Generator\Manager\MigrationManager;

/**
 * Written by hand, like PropelMigration_1792553082.
 *
 * Adds evento_arquivamento, the queue of requests to archive a finished
 * event's published results in the backups (BAJAAPP-67). schema.xml
 * explains the table; baja-infra/docs/backup-restore.md, "Event archives",
 * explains what reads it. The same DDL is in
 * baja-infra/mysql/init/07-evento-arquivamento.sql for fresh dev
 * databases, which also records this version as applied.
 *
 * CREATE TABLE IF NOT EXISTS, so a server where the table was created by
 * hand ahead of the deploy takes this migration without failing.
 *
 * Data object containing the SQL and PHP code to migrate the database
 * up to version 1792929600.
 */
class PropelMigration_1792929600{
    /**
     * @var string
     */
    public $comment = 'evento_arquivamento: pending archives of finished events (BAJAAPP-67)';

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
EOT;

        return [
            'resultados' => $connection_resultados,
        ];
    }

    /**
     * Get the SQL statements for the Down migration
     *
     * Drops the queue and with it the record of which Shared Drive folder
     * holds each archive. The archives themselves stay on the Drive, where
     * `rclone lsf gdrive:events/` still lists them.
     *
     * @return array list of the SQL strings to execute for the Down migration
     *               the keys being the datasources
     */
    public function getDownSQL(): array
    {
        $connection_resultados = <<< 'EOT'
DROP TABLE IF EXISTS `evento_arquivamento`;
EOT;

        return [
            'resultados' => $connection_resultados,
        ];
    }

}
