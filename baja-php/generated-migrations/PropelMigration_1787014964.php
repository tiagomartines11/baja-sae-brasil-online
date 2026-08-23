<?php
use Propel\Generator\Manager\MigrationManager;

/**
 * Data object containing the SQL and PHP code to migrate the database
 * up to version 1787014964.
 * Generated on 2026-08-18 01:02:44
 *
 * Trimmed by hand after generation. migration:diff also emitted six unrelated
 * tables' worth of noise: integer display-width churn (INTEGER(4), BIGINT(11))
 * that MySQL 8.4 no longer stores and that is therefore a no-op, plus a CREATE
 * TABLE for `config`, which exists in schema.xml but not in the database the
 * diff ran against. None of that belongs in this work package's migration, and
 * the `config` gap is pre-existing drift that needs its own decision.
 */
class PropelMigration_1787014964{
    /**
     * @var string
     */
    public $comment = 'Add participantes.token: public certificate identifier';

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
# This is a fix for InnoDB in MySQL >= 4.1.x
# It "suspends judgement" for fkey relationships until are tables are set.
SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE `participantes`

  ADD `token` CHAR(22) CHARACTER SET 'ascii' COLLATE 'ascii_bin' AFTER `evento`;

CREATE UNIQUE INDEX `participantes_token_unq` ON `participantes` (`token`);

# This restores the fkey checks, after having unset them earlier
SET FOREIGN_KEY_CHECKS = 1;
EOT;

        return [
            'resultados' => $connection_resultados,
        ];
    }

    /**
     * Get the SQL statements for the Down migration
     *
     * @return array list of the SQL strings to execute for the Down migration
     *               the keys being the datasources
     */
    public function getDownSQL(): array
    {
        $connection_resultados = <<< 'EOT'
# This is a fix for InnoDB in MySQL >= 4.1.x
# It "suspends judgement" for fkey relationships until are tables are set.
SET FOREIGN_KEY_CHECKS = 0;

DROP INDEX `participantes_token_unq` ON `participantes`;

ALTER TABLE `participantes`

  DROP `token`;

# This restores the fkey checks, after having unset them earlier
SET FOREIGN_KEY_CHECKS = 1;
EOT;

        return [
            'resultados' => $connection_resultados,
        ];
    }

}
