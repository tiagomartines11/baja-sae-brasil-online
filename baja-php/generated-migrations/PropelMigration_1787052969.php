<?php
use Propel\Generator\Manager\MigrationManager;

/**
 * Trimmed from what migration:diff produced. The dev database has drifted
 * from schema.xml in ways that predate this branch — a missing `config`
 * table, integer display widths, a couple of TIMESTAMP/DATETIME
 * disagreements — and diff emitted all of it. Shipping those alongside an
 * audit-trail migration would mean this branch silently altering seven tables
 * it has no business touching. Only the `participantes` statements are kept.
 *
 * Data object containing the SQL and PHP code to migrate the database
 * up to version 1787052969.
 * Generated on 2026-08-18 11:36:09  */
class PropelMigration_1787052969{
    /**
     * @var string
     */
    public $comment = 'participantes: audit trail — criado_por, criado_em, lote_id';

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

# Audit trail for the certificate insertion pages: who asserted this record,
# when, and as part of which paste. All three are nullable and every existing
# row keeps NULL — there is nothing to backfill them from, and a fabricated
# value would be worse than an absent one.

ALTER TABLE `participantes`

  ADD `criado_por` INTEGER AFTER `token`,

  ADD `criado_em` DATETIME AFTER `criado_por`,

  ADD `lote_id` CHAR(22) CHARACTER SET 'ascii' COLLATE 'ascii_bin' AFTER `criado_em`;

# lote_id is what makes a bad paste identifiable, and deletable, afterwards.
CREATE INDEX `participantes_lote_id_idx` ON `participantes` (`lote_id`);

CREATE INDEX `participantes_criado_por_idx` ON `participantes` (`criado_por`);

# ON DELETE SET NULL, and the choice matters. admin_usuario.php deletes the
# user row outright when a user is left holding no meaningful permission, so
# under CASCADE, de-permissioning somebody would delete every certificate they
# had ever issued. Losing the attribution is bad; losing the certificates is
# unrecoverable.
ALTER TABLE `participantes` ADD CONSTRAINT `participantes_criado_por`
    FOREIGN KEY (`criado_por`)
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

# Destructive: the audit trail for every row created through the insertion
# pages is gone after this, and unlike the columns it cannot be rebuilt.
ALTER TABLE `participantes` DROP FOREIGN KEY `participantes_criado_por`;

DROP INDEX `participantes_lote_id_idx` ON `participantes`;

DROP INDEX `participantes_criado_por_idx` ON `participantes`;

ALTER TABLE `participantes`

  DROP `criado_por`,

  DROP `criado_em`,

  DROP `lote_id`;

# This restores the fkey checks, after having unset them earlier
SET FOREIGN_KEY_CHECKS = 1;
EOT;

        return [
            'resultados' => $connection_resultados,
        ];
    }

}
