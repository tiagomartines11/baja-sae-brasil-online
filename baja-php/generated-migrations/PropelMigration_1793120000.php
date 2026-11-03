<?php
use Propel\Generator\Manager\MigrationManager;

/**
 * programa.manage, for the program admin page (BAJAAPP-90).
 *
 * Written by hand: no table changes, only rows.
 *
 * The new permission is given to platform_admin and to no other role. It
 * applies to the platform only (Baja\Rbac\Permission\Permission), because
 * a program's parent decides whose grants reach it: moving a program moves
 * who may do what in it. program_manager, which carries every other
 * permission, does not get it.
 *
 * One role_audit row (role_update) records the change to the role, as the
 * switch-over's migration did for premiacao.view.
 *
 * The same SQL is in baja-infra/mysql/init/13-programa-manage.sql for fresh
 * dev databases, and that file records this version as applied.
 *
 * Tests: tests/Rbac/Db/MigrationDataTest.php (the roles as planned),
 * tests/Programa/Db/ProgramaAdminTest.php (the page's rules).
 */
class PropelMigration_1793120000
{
    public $comment = 'programa.manage for platform_admin: the program admin page (BAJAAPP-90)';

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
INSERT IGNORE INTO `role_permission` (`role_id`, `permission`)
SELECT `role_id`, 'programa.manage' FROM `role` WHERE `code` = 'platform_admin';

INSERT INTO `role_audit` (`occurred_at`, `actor_user_id`, `action`, `role_id`, `details`)
SELECT NOW(), NULL, 'role_update', `role_id`,
       JSON_OBJECT('code', `code`, 'added', JSON_ARRAY('programa.manage'), 'source', 'PropelMigration_1793120000')
FROM `role` WHERE `code` = 'platform_admin';
EOT;

        return [
            'resultados' => $connection_resultados,
        ];
    }

    /**
     * Takes the permission away again. Its role_audit row stays (the
     * triggers keep it); a second one records the removal.
     */
    public function getDownSQL(): array
    {
        $connection_resultados = <<< 'EOT'
DELETE FROM `role_permission` WHERE `permission` = 'programa.manage';

INSERT INTO `role_audit` (`occurred_at`, `actor_user_id`, `action`, `role_id`, `details`)
SELECT NOW(), NULL, 'role_update', `role_id`,
       JSON_OBJECT('code', `code`, 'removed', JSON_ARRAY('programa.manage'), 'source', 'PropelMigration_1793120000 (down)')
FROM `role` WHERE `code` = 'platform_admin';
EOT;

        return [
            'resultados' => $connection_resultados,
        ];
    }
}
