<?php
use Propel\Generator\Manager\MigrationManager;

/**
 * Written by hand rather than taken from migration:diff, for the same reason
 * as PropelMigration_1787052969: the diff also emits the drift that predates
 * this branch, and this migration has no business altering other tables.
 *
 * IF NOT EXISTS because the table was first created by hand on servers that
 * were not running migrations yet. latin1 because `evento` is latin1 in every
 * database descended from the production dump, and MySQL refuses the foreign
 * key (error 3780) when the two evento_id columns differ in charset.
 *
 * Data object containing the SQL and PHP code to migrate the database
 * up to version 1790819962.
 */
class PropelMigration_1790819962{
    /**
     * @var string
     */
    public $comment = 'timer: configurable timers per event';

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
CREATE TABLE IF NOT EXISTS `timer`
(
    `evento_id` CHAR(4) NOT NULL,
    `timer_id` INTEGER NOT NULL,
    `config` json,
    `config_backup` json,
    `state` json,
    `log` json,
    `access_key` CHAR(6) NOT NULL,
    PRIMARY KEY (`evento_id`,`timer_id`),
    UNIQUE INDEX `timer_access_key_UNIQUE` (`access_key`),
    CONSTRAINT `timer_evento_id`
        FOREIGN KEY (`evento_id`)
        REFERENCES `evento` (`evento_id`)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB CHARACTER SET=\'latin1\';
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
DROP TABLE IF EXISTS `timer`;
',
);
    }

}
