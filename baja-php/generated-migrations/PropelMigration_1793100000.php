<?php
use Propel\Generator\Manager\MigrationManager;

/**
 * role_audit is append-only in MySQL itself (BAJAAPP-12): two triggers
 * that refuse every UPDATE and every DELETE on it.
 *
 * Written by hand: schema.xml cannot describe triggers, so
 * `./propel migration:diff` never produces them.
 *
 * role_audit is the record of who gave or took away which role, and when.
 * Until now only the code kept it append-only (and a test that reads the
 * code, tests/Unit/Rbac/AuditIsAppendOnlyTest.php). With these, a bug, a
 * hand-typed query or an injected statement that tries to change or remove
 * a row gets an error from MySQL instead, and the row stays.
 *
 * What they do NOT stop: TRUNCATE and DROP TABLE (they fire no triggers),
 * or someone dropping the triggers first. All three need privileges the
 * application's MySQL account has today (ALL on baja_resultados); taking
 * them away from it is a change of its own.
 *
 * Each trigger is one statement with no BEGIN ... END, so there is no ';'
 * inside it and no DELIMITER is needed, here or in the dev seed.
 *
 * Creating a trigger while binary logging is on (MySQL's default) needs
 * the account running the migration to have SUPER, or the server to have
 * log_bin_trust_function_creators=1; otherwise MySQL refuses with error
 * 1419 and the migration stops before it is recorded.
 *
 * The same SQL is in baja-infra/mysql/init/11-role-audit-triggers.sql for
 * fresh dev databases. Tested in tests/Rbac/Db/AuditIsAppendOnlyInMysqlTest.php.
 */
class PropelMigration_1793100000
{
    public $comment = 'role_audit append-only: triggers refusing UPDATE and DELETE (BAJAAPP-12)';

    public function preUp(MigrationManager $manager)
    {
    }

    public function postUp(MigrationManager $manager)
    {
    }

    public function preDown(MigrationManager $manager)
    {
    }

    public function postDown(MigrationManager $manager)
    {
    }

    public function getUpSQL(): array
    {
        $connection_resultados = <<< 'EOT'
CREATE TRIGGER IF NOT EXISTS `role_audit_no_update` BEFORE UPDATE ON `role_audit`
    FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'role_audit is append-only: its rows cannot be changed (BAJAAPP-12)';

CREATE TRIGGER IF NOT EXISTS `role_audit_no_delete` BEFORE DELETE ON `role_audit`
    FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'role_audit is append-only: its rows cannot be deleted (BAJAAPP-12)';
EOT;

        return [
            'resultados' => $connection_resultados,
        ];
    }

    public function getDownSQL(): array
    {
        $connection_resultados = <<< 'EOT'
DROP TRIGGER IF EXISTS `role_audit_no_update`;

DROP TRIGGER IF EXISTS `role_audit_no_delete`;
EOT;

        return [
            'resultados' => $connection_resultados,
        ];
    }
}
