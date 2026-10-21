<?php
use Propel\Generator\Manager\MigrationManager;

/**
 * Hand-written from `propel sql:build` output rather than `migration:diff`,
 * for the reason PropelMigration_1787052969 and _1787075406 record: the dev
 * database has drifted from schema.xml in ways that predate this branch, and
 * diff emits all of it. One new table is the whole of this change.
 *
 * `caso` and `status` are declared ENUM in schema.xml and land as TINYINT
 * here, which is how Propel stores an enum — the column holds the index into
 * valueSet, not the label. Every other enum in this database is the same
 * (`evento`.`tipo` is a TINYINT on the live server). What follows from it:
 * the ORDER of a valueSet is permanent. A value appended to the end is free,
 * and a value inserted in the middle silently relabels every existing row.
 *
 * Data object containing the SQL and PHP code to migrate the database
 * up to version 1787507406.
 * Generated on 2026-08-23 17:50:06  */
class PropelMigration_1787507406{
    /**
     * @var string
     */
    public $comment = 'certificado_requerimento: participant correction requests';

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
        return array (
  'resultados' =>
  '
CREATE TABLE `certificado_requerimento`
(
    `requerimento_id` CHAR(22) CHARACTER SET \'ascii\' COLLATE \'ascii_bin\' NOT NULL,
    `caso` TINYINT NOT NULL,
    `token` CHAR(22) CHARACTER SET \'ascii\' COLLATE \'ascii_bin\',
    `evento` CHAR(4),
    `evento_texto` VARCHAR(160),
    `documento` VARCHAR(32) NOT NULL,
    `nome` VARCHAR(300) NOT NULL,
    `email` VARCHAR(254) NOT NULL,
    `telefone` VARCHAR(40),
    `descricao` VARCHAR(2000) NOT NULL,
    `criado_em` DATETIME NOT NULL,
    `avisado_em` DATETIME,
    `status` TINYINT DEFAULT 0 NOT NULL,
    `resolvido_por` INTEGER,
    `resolvido_em` DATETIME,
    `resolucao` VARCHAR(1000),
    PRIMARY KEY (`requerimento_id`),
    INDEX `certificado_requerimento_status_idx` (`status`, `criado_em`),
    INDEX `certificado_requerimento_token_idx` (`token`),
    INDEX `certificado_requerimento_resolvido_por_idx` (`resolvido_por`),
    CONSTRAINT `certificado_requerimento_resolvido_por`
        FOREIGN KEY (`resolvido_por`)
        REFERENCES `user` (`user_id`)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB CHARACTER SET=\'utf8mb4\' COLLATE=\'utf8mb4_unicode_ci\';
',
);
    }

    /**
     * Get the SQL statements for the Down migration
     *
     * @return array list of the SQL strings to execute for the Down migration
     *               the keys being the datasources
     */
    public function getDownSQL(): array
    {
        return array (
  'resultados' =>
  '
DROP TABLE IF EXISTS `certificado_requerimento`;
',
);
    }

}
