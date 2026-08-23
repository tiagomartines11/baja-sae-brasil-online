<?php
use Propel\Generator\Manager\MigrationManager;

/**
 * Data object containing the SQL and PHP code to migrate the database
 * up to version 1787015631.
 * Generated on 2026-08-18 01:13:51  *
 * Trimmed by hand after generation, and extended with the data statements and
 * the CHECK constraint, neither of which migration:diff can produce. The diff
 * also emitted six unrelated tables of integer display-width churn that MySQL
 * 8.4 no longer stores, and a CREATE TABLE for `config`, which is in
 * schema.xml but not in the database; that drift is pre-existing.
 */
class PropelMigration_1787015631{
    /**
     * @var string
     */
    public $comment = 'participantes: cpf becomes CHAR(11), add documento_estrangeiro';

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

  ADD `documento_estrangeiro` VARCHAR(32) CHARACTER SET 'utf8mb4' COLLATE 'utf8mb4_unicode_ci' AFTER `cpf`;

# Values that cannot be a CPF, because they do not fit in eleven digits, are
# preserved before the column narrows to CHAR(11) and truncates them. This
# moves them; it does not classify them. "Not eleven digits, so not a CPF" is
# a fact about the value. Whether any given one is a passport is a question
# about a person, and that review is Joao's.
UPDATE `participantes`
   SET `documento_estrangeiro` = CAST(`cpf` AS CHAR)
 WHERE `cpf` IS NOT NULL AND (`cpf` < 0 OR `cpf` > 99999999999);

# 0 is what a document that could not survive the BIGINT column became. It has
# been serving as "no document recorded" while being indistinguishable from a
# real value, and every row holding it collapsed onto the single legacy URL
# /c/{evt}/0, where findOne() handed out whichever name it reached first.
UPDATE `participantes`
   SET `cpf` = NULL
 WHERE `cpf` IS NOT NULL AND (`cpf` <= 0 OR `cpf` > 99999999999);

ALTER TABLE `participantes`

  CHANGE `cpf` `cpf` CHAR(11) CHARACTER SET 'ascii';

# Restore the leading zeros the numeric column destroyed. This is exact rather
# than a guess: a CPF is eleven digits, so for any surviving value the padding
# is fully determined. It is not the data cleaning that is out of scope — no
# value is reclassified, none is repaired, and a row that was wrong before is
# equally wrong after, just eleven characters long.
UPDATE `participantes`
   SET `cpf` = LPAD(`cpf`, 11, '0')
 WHERE `cpf` IS NOT NULL;

# Format only, never the check digits. Historical rows include CPFs mistyped at
# registration, and a check-digit constraint would abort this migration on them
# and refuse the imported data later. Added here because Propel's schema.xml has
# no element for a CHECK, which also means migration:diff cannot see it: a later
# diff will not try to drop it, but will not recreate it either.
ALTER TABLE `participantes`

  ADD CONSTRAINT `participantes_cpf_formato`
  CHECK (`cpf` IS NULL OR `cpf` REGEXP '^[0-9]{11}$');

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

# Not symmetric, and cannot be: going back to a numeric column destroys the
# leading zeros again, and merges "no document recorded" back into 0. Down is
# here to unblock a rollback of the code, not to restore the data.
ALTER TABLE `participantes`

  DROP CONSTRAINT `participantes_cpf_formato`;

ALTER TABLE `participantes`

  CHANGE `cpf` `cpf` BIGINT,

  DROP `documento_estrangeiro`;

# This restores the fkey checks, after having unset them earlier
SET FOREIGN_KEY_CHECKS = 1;
EOT;

        return [
            'resultados' => $connection_resultados,
        ];
    }

}
