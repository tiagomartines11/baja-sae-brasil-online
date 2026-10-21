<?php
use Propel\Generator\Manager\MigrationManager;

/**
 * Trimmed to the `participantes` statements, for the same reason as
 * PropelMigration_1787052969: the dev database has drifted from schema.xml in
 * ways that predate this branch, and migration:diff emits all of it. This
 * migration has no business altering seven other tables.
 *
 * Data object containing the SQL and PHP code to migrate the database
 * up to version 1787075406.
 * Generated on 2026-08-18 17:50:06  */
class PropelMigration_1787075406{
    /**
     * @var string
     */
    public $comment = 'participantes: anulado_em, anulado_por, anulado_motivo';

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

# Voiding a certificate without destroying its row. A certificate issued in
# error stops verifying; the record of it, and of who withdrew it and why,
# stays — because this action reaches certificates issued years ago that
# somebody may already be holding, and destroying the row would take the audit
# trail with it.
#
# Every existing row gets NULL: nothing is voided by this migration.
ALTER TABLE `participantes`

  ADD `anulado_em` DATETIME AFTER `lote_id`,

  ADD `anulado_por` INTEGER AFTER `anulado_em`,

  ADD `anulado_motivo` VARCHAR(255) AFTER `anulado_por`;

# MySQL requires an index on a foreign key column. Named here rather than left
# to Propel, which invents `fi_ticipantes_anulado_por`.
CREATE INDEX `participantes_anulado_por_idx` ON `participantes` (`anulado_por`);

# SET NULL for the same reason criado_por uses it: admin_usuario.php deletes a
# user row once they hold no meaningful permission, and under CASCADE that
# would delete every certificate they had ever voided — which is to say, it
# would un-void them by deleting them.
ALTER TABLE `participantes` ADD CONSTRAINT `participantes_anulado_por`
    FOREIGN KEY (`anulado_por`)
    REFERENCES `user` (`user_id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL;

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

# Destructive in a quiet way: every voided certificate silently becomes valid
# again, and the record of who withdrew it and why is gone. Check for rows with
# anulado_em set before running this.
ALTER TABLE `participantes` DROP FOREIGN KEY `participantes_anulado_por`;

DROP INDEX `participantes_anulado_por_idx` ON `participantes`;

ALTER TABLE `participantes`

  DROP `anulado_em`,

  DROP `anulado_por`,

  DROP `anulado_motivo`;

# This restores the fkey checks, after having unset them earlier
SET FOREIGN_KEY_CHECKS = 1;
EOT;

        return [
            'resultados' => $connection_resultados,
        ];
    }

}
