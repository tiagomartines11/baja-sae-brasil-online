<?php
use Propel\Generator\Manager\MigrationManager;

/**
 * Written by hand, like PropelMigration_1792929600, for the reason
 * PropelMigration_1787507406 records: the dev database has drifted from
 * schema.xml in ways that predate this branch, and `migration:diff` emits
 * all of it.
 *
 * Adds certificado_requerimento.duplicado_de and the two indexes the
 * duplicate check reads (BAJAAPP-89). schema.xml explains the column;
 * Baja\Certificado\Requerimento\Duplicata explains what counts as a
 * duplicate.
 *
 * No row is changed. The column is NULL for every existing report, which is
 * the right answer: the reports that were already filed twice stay as they
 * are, and closing them together is BAJAAPP-88.
 *
 * The foreign key points at its own table, ON DELETE SET NULL, so the
 * retention script (scripts/purge-certificado-requerimentos.php) can delete
 * a closed original without touching an open duplicate of it.
 *
 * Data object containing the SQL and PHP code to migrate the database
 * up to version 1793669096.
 */
class PropelMigration_1793669096{
    /**
     * @var string
     */
    public $comment = 'certificado_requerimento.duplicado_de: repeat reports point at the open original (BAJAAPP-89)';

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
     * The column has the same character set and collation as
     * requerimento_id, which it references. MySQL refuses a foreign key
     * between columns that differ in either.
     *
     * @return array list of the SQL strings to execute for the Up migration
     *               the keys being the datasources
     */
    public function getUpSQL(): array
    {
        $connection_resultados = <<< 'EOT'
ALTER TABLE `certificado_requerimento`
    ADD COLUMN `duplicado_de` CHAR(22) CHARACTER SET 'ascii' COLLATE 'ascii_bin' NULL AFTER `resolucao`,
    ADD INDEX `certificado_requerimento_duplicata_idx` (`documento`, `status`),
    ADD INDEX `certificado_requerimento_duplicado_de_idx` (`duplicado_de`),
    ADD CONSTRAINT `certificado_requerimento_duplicado_de`
        FOREIGN KEY (`duplicado_de`)
        REFERENCES `certificado_requerimento` (`requerimento_id`)
        ON UPDATE CASCADE
        ON DELETE SET NULL;
EOT;

        return [
            'resultados' => $connection_resultados,
        ];
    }

    /**
     * Get the SQL statements for the Down migration
     *
     * Drops the links between duplicates and their originals. The rows stay;
     * they just stop saying which report they repeat.
     *
     * @return array list of the SQL strings to execute for the Down migration
     *               the keys being the datasources
     */
    public function getDownSQL(): array
    {
        $connection_resultados = <<< 'EOT'
ALTER TABLE `certificado_requerimento`
    DROP FOREIGN KEY `certificado_requerimento_duplicado_de`;
ALTER TABLE `certificado_requerimento`
    DROP INDEX `certificado_requerimento_duplicado_de_idx`,
    DROP INDEX `certificado_requerimento_duplicata_idx`,
    DROP COLUMN `duplicado_de`;
EOT;

        return [
            'resultados' => $connection_resultados,
        ];
    }

}
