<?php
use Propel\Generator\Manager\MigrationManager;
use Propel\Runtime\Connection\ConnectionInterface;

/**
 * Competition programs (BAJAAPP-11): the programa table, its six programs,
 * and evento.programa_id, filled in once for every existing event.
 *
 * Started from `./propel migration:diff` in the dev container, then cut
 * down to this change ONLY. On develop the diff also lists unrelated drift
 * between schema.xml and the database (equipe, fila, senha, log, config,
 * and the certificate tables on a database that has not run their
 * migrations yet); none of that is here, on purpose. Two things were added
 * by hand because the diff gets them wrong or cannot know them:
 *
 *   - the key columns are latin1, like evento's (schema.xml, programa,
 *     says why). Without it the foreign key from evento is refused;
 *   - the data: the six programs, and which program each existing event
 *     belongs to.
 *
 * WHICH PROGRAM AN EXISTING EVENT BELONGS TO
 *
 * Until now the only place that said it was the event code's last two
 * letters: 26BR is a Baja SAE event, 25AR an AeroDesign one. This
 * migration reads that spelling exactly once, with the table below, and
 * stores the answer in evento.programa_id. From then on the column is the
 * truth and no code reads the program from the code's spelling: a new
 * event gets its program chosen on juiz/evento.php.
 *
 * An event whose suffix is not in the table stops the migration BEFORE it
 * changes anything (preUp), with the list of such events. Guessing would
 * silently put an event, and so the people allowed to manage it, under the
 * wrong program. To go on, decide which program those events belong to and
 * add their suffix to PROGRAMA_BY_SUFFIX here.
 *
 * The same DDL and data are in baja-infra/mysql/init/08-programa.sql for
 * fresh dev databases, which also records this version as applied.
 *
 * Tests: tests/Unit/Programa/BackfillMappingTest.php (the table) and
 * tests/Programa/Db/BackfillTest.php (the check and the UPDATE, on a real
 * MySQL).
 */
class PropelMigration_1793070000
{
    public $comment = 'programa table and evento.programa_id, filled from the event code suffix once (BAJAAPP-11)';

    /**
     * Event code suffix (characters 3 and 4 of codes such as 26BR) => the
     * program those events belong to. Read by this migration only.
     */
    public const PROGRAMA_BY_SUFFIX = [
        'BR' => 'BAJA',
        'SE' => 'BAJA',
        'SU' => 'BAJA',
        'NE' => 'BAJA',
        'AR' => 'AERO',
        'FA' => 'AERO_FORUM',
        'FO' => 'FORMULA',
        'EQ' => 'ELETROQUAD',
        'H2' => 'H2',
    ];

    /**
     * The program for one existing event code, or null when its suffix is
     * not in the table. Case matters: event codes are upper case, and a
     * lower case one is not something to guess about either.
     */
    public static function programaForEventCode(string $eventoId): ?string
    {
        return self::PROGRAMA_BY_SUFFIX[self::suffixOf($eventoId)] ?? null;
    }

    /**
     * The codes, among the given ones, that this migration does not know
     * the program of.
     *
     * @param string[] $eventoIds
     * @return string[]
     */
    public static function eventCodesWithUnknownSuffix(array $eventoIds): array
    {
        return array_values(array_filter(
            $eventoIds,
            fn(string $eventoId) => self::programaForEventCode($eventoId) === null
        ));
    }

    /**
     * Refuses, with every offending code in the message, when any event in
     * the database has a suffix this migration does not know.
     *
     * @param PDO|ConnectionInterface $con a connection to baja_resultados
     * @throws RuntimeException
     */
    public static function assertEveryEventCodeIsKnown($con): void
    {
        $eventoIds = $con->query('SELECT evento_id FROM evento ORDER BY evento_id')->fetchAll(PDO::FETCH_COLUMN);
        $unknown = self::eventCodesWithUnknownSuffix(array_map('strval', $eventoIds));
        if ($unknown !== []) {
            throw new RuntimeException(
                'BAJAAPP-11: cannot tell which program these events belong to: ' . implode(', ', $unknown)
                . '. Nothing was changed. Add their code suffix to PROGRAMA_BY_SUFFIX in '
                . basename(__FILE__) . ' and run the migration again.'
            );
        }
    }

    /**
     * The UPDATE that stores each event's program, built from the table
     * above. BINARY so that the comparison is case-sensitive like
     * programaForEventCode(); evento's latin1 collation is not.
     */
    public static function backfillSql(): string
    {
        $cases = '';
        foreach (self::PROGRAMA_BY_SUFFIX as $suffix => $programaId) {
            $cases .= "    WHEN '$suffix' THEN '$programaId'\n";
        }
        return "UPDATE `evento` SET `programa_id` = CASE BINARY SUBSTRING(`evento_id`, 3, 2)\n"
            . $cases
            . "END\nWHERE `programa_id` IS NULL;";
    }

    private static function suffixOf(string $eventoId): string
    {
        return substr($eventoId, 2, 2);
    }

    /**
     * Runs before any SQL below. Throwing here stops `migration:migrate`
     * with the message and leaves the database as it was; MySQL cannot roll
     * back DDL, so this is the only point where stopping is clean.
     */
    public function preUp(MigrationManager $manager)
    {
        self::assertEveryEventCodeIsKnown($manager->getAdapterConnection('resultados'));
    }

    /**
     * Nothing here on purpose: Propel records the migration as applied
     * before it calls postUp(), so a data step that failed here would be
     * left half done and never retried.
     */
    public function postUp(MigrationManager $manager)
    {
    }

    public function preDown(MigrationManager $manager)
    {
    }

    public function postDown(MigrationManager $manager)
    {
    }

    /**
     * In order: the table and its programs, then the column (nullable
     * while it is filled), the backfill, and only then NOT NULL and the
     * foreign key. If the backfill missed an event, NOT NULL fails and says
     * so; preUp makes that unreachable, it is the second lock.
     *
     * INSERT IGNORE and IF NOT EXISTS: a server where an admin created the
     * table by hand ahead of the deploy still takes this migration.
     */
    public function getUpSQL(): array
    {
        $connection_resultados = <<< 'EOT'
CREATE TABLE IF NOT EXISTS `programa`
(
    `programa_id` VARCHAR(16) CHARACTER SET latin1 NOT NULL,
    `parent_id` VARCHAR(16) CHARACTER SET latin1,
    `nome` VARCHAR(100) NOT NULL,
    `ordem` INTEGER DEFAULT 0 NOT NULL,
    `ativo` TINYINT(1) DEFAULT 1 NOT NULL,
    PRIMARY KEY (`programa_id`),
    INDEX `programa_parent_id_idx` (`parent_id`),
    CONSTRAINT `programa_parent_id`
        FOREIGN KEY (`parent_id`)
        REFERENCES `programa` (`programa_id`)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_unicode_ci';

INSERT IGNORE INTO `programa` (`programa_id`, `parent_id`, `nome`, `ordem`, `ativo`) VALUES
    ('BAJA', NULL, 'Baja SAE', 10, 1),
    ('AERO', NULL, 'AeroDesign', 20, 1),
    ('AERO_FORUM', 'AERO', 'Fórum AeroDesign', 30, 1),
    ('FORMULA', NULL, 'Fórmula SAE', 40, 1),
    ('ELETROQUAD', NULL, 'Eletroquad', 50, 1),
    ('H2', NULL, 'H2 Challenge', 60, 1);

ALTER TABLE `evento`
    ADD `programa_id` VARCHAR(16) CHARACTER SET latin1 NULL AFTER `carga_horaria`;

EOT;
        $connection_resultados .= self::backfillSql() . "\n\n";
        $connection_resultados .= <<< 'EOT'
ALTER TABLE `evento`
    MODIFY `programa_id` VARCHAR(16) CHARACTER SET latin1 NOT NULL;

CREATE INDEX `evento_programa_id_idx` ON `evento` (`programa_id`);

ALTER TABLE `evento` ADD CONSTRAINT `evento_programa_id`
    FOREIGN KEY (`programa_id`)
    REFERENCES `programa` (`programa_id`)
    ON UPDATE CASCADE
    ON DELETE RESTRICT;
EOT;

        return [
            'resultados' => $connection_resultados,
        ];
    }

    /**
     * Drops the column and the table. The program of each event is lost;
     * running up again works it out from the codes once more.
     */
    public function getDownSQL(): array
    {
        $connection_resultados = <<< 'EOT'
ALTER TABLE `evento` DROP FOREIGN KEY `evento_programa_id`;

ALTER TABLE `evento` DROP INDEX `evento_programa_id_idx`;

ALTER TABLE `evento` DROP COLUMN `programa_id`;

DROP TABLE IF EXISTS `programa`;
EOT;

        return [
            'resultados' => $connection_resultados,
        ];
    }
}
