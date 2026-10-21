<?php
use Propel\Generator\Manager\MigrationManager;

/**
 * Data object containing the SQL and PHP code to migrate the database
 * up to version 1787017208.
 * Generated on 2026-08-18 01:40:08  */
class PropelMigration_1787017208{
    /**
     * @var string
     */
    public $comment = 'participantes: token becomes the primary key, drop idparticipantes';

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

# Redundant once the column below becomes the primary key.
DROP INDEX `participantes_token_unq` ON `participantes`;

# One statement on purpose. `idparticipantes` is AUTO_INCREMENT, and InnoDB
# requires such a column to be the leading column of some key for as long as it
# exists — so dropping the old key first, in its own statement, would fail.
# Dropping the key, the column and adding the new one together leaves no
# intermediate state for that rule to reject.
#
# The foreign key on `evento` survives this: it has its own index
# (participantes_evento_id_idx), so it does not depend on the composite key
# being dropped here. Verified, not assumed.
#
# PRECONDITION: every row has a token. The NOT NULL below fails otherwise, and
# the migration aborts without having changed anything. Run
# scripts/backfill-participante-tokens.php first.
ALTER TABLE `participantes`

  DROP PRIMARY KEY,

  CHANGE `token` `token` CHAR(22) CHARACTER SET 'ascii' COLLATE 'ascii_bin' NOT NULL,

  DROP `idparticipantes`,

  ADD PRIMARY KEY (`token`);

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

# The restored idparticipantes values are fresh ones. The originals are gone,
# which is safe only because nothing outside the generated Propel classes ever
# referenced them — no foreign key, no export, no report. That was checked
# before the column was dropped and is the reason it could be.
ALTER TABLE `participantes`

  DROP PRIMARY KEY,

  CHANGE `token` `token` CHAR(22) CHARACTER SET 'ascii' COLLATE 'ascii_bin',

  ADD `idparticipantes` INTEGER NOT NULL AUTO_INCREMENT FIRST,

  ADD PRIMARY KEY (`idparticipantes`, `evento`);

CREATE UNIQUE INDEX `participantes_token_unq` ON `participantes` (`token`);

# This restores the fkey checks, after having unset them earlier
SET FOREIGN_KEY_CHECKS = 1;
EOT;

        return [
            'resultados' => $connection_resultados,
        ];
    }

}
