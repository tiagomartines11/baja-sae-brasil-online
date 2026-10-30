<?php
use Baja\Rbac\Legacy\LegacyGrantPlan;
use Propel\Generator\Manager\MigrationManager;

/**
 * The switch-over to RBAC (BAJAAPP-76, phase 3 of "Plan: programs and
 * RBAC"): every legacy code in user.permissions becomes a grant, once.
 *
 * Written by hand: no table changes, only rows.
 *
 * NEW PERMISSION, NEW ROLES
 *
 * The legacy codes that no starting role matched get a role of their own,
 * each carrying exactly what the code allowed:
 *
 *     lap_counter      prova    contagem.count     {EVT}_{PROVA}_CONT
 *     pit_logger       prova    contagem.pit_log   {EVT}_{PROVA}_BOX
 *     timer_operator   evento   timer.operate      {EVT}_TIMER_{id}
 *     awards_viewer    evento   premiacao.view     {EVT}_PREMIACAO
 *
 * premiacao.view is new: {EVT}_PREMIACAO let judges read the published
 * prizes, while only 'admin' saw the drafts and edited them. premiacao.manage
 * stays the second. The roles that carried premiacao.manage (platform_admin,
 * program_manager, event_manager) also get premiacao.view, so they keep
 * seeing what they saw.
 *
 * Every role change writes a role_audit row (role_create, role_update).
 *
 * THE GRANTS
 *
 * Baja\Rbac\Legacy\LegacyGrantPlan works them out in preUp(), before any
 * SQL runs, and prints what it found: the codes that map to nothing, the
 * events with no data_fim (whose grants get NO end until someone sets the
 * date), and the grants that are born ended. The INSERTs are in getUpSQL():
 * Propel records a migration as applied BEFORE it calls postUp(), so a data
 * step there could fail and never be retried.
 *
 * Each grant: granted_by NULL (the migration), granted_at now, valid_until
 * as GrantExpiry would give it, one role_audit row naming the legacy code.
 * The mapping, code by code, is in Baja\Rbac\Legacy\LegacyCode and in
 * docs/rbac.md.
 *
 * user.permissions IS NOT TOUCHED. It stays as it was for one release,
 * read by nothing but this migration (and the teams' own queue codes,
 * which are not roles), so that rolling the code back gives everyone the
 * access they had. docs/rbac.md, "Rolling back".
 *
 * The same roles are in baja-infra/mysql/init/12-rbac-switchover.sql for
 * fresh dev databases, with the seed users' grants, and that file records
 * this version as applied.
 *
 * Tests: tests/Unit/Rbac/LegacyCodeTest.php (the mapping),
 * tests/Rbac/Db/LegacyMigrationTest.php (the plan and its SQL on MySQL).
 */
class PropelMigration_1793110000
{
    public $comment = 'RBAC switch-over: four roles and premiacao.view; every legacy permission code becomes a grant (BAJAAPP-76)';

    /** @var string[] the INSERTs preUp() worked out */
    private array $grantStatements = [];

    public function preUp(MigrationManager $manager)
    {
        $con = $manager->getAdapterConnection('resultados');
        $now = new DateTimeImmutable('now');
        // The roles must exist before the grants name them, and getUpSQL()
        // creates them; the plan only needs their codes, which it has.
        $plan = LegacyGrantPlan::build($con, $now);
        $this->grantStatements = $plan->statements(fn(string $v) => $con->quote($v), $now);
        echo $plan->report();
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
        $roles = <<< 'EOT'
INSERT IGNORE INTO `role` (`code`, `name`, `description`, `is_system`, `allowed_scope_types`) VALUES
    ('lap_counter', 'Contador de voltas', 'Conta as voltas de uma prova com contagem de voltas, na fonte a que está vinculado.', 0, 'prova'),
    ('pit_logger', 'Registro de box', 'Registra as entradas e saídas do box (e o abastecimento) de uma prova com contagem de voltas.', 0, 'prova'),
    ('timer_operator', 'Operador de cronômetros', 'Opera os cronômetros de um evento.', 0, 'evento'),
    ('awards_viewer', 'Premiações (consulta)', 'Vê as premiações publicadas de um evento.', 0, 'evento');

INSERT IGNORE INTO `role_permission` (`role_id`, `permission`)
SELECT `role_id`, 'contagem.count' FROM `role` WHERE `code` = 'lap_counter'
UNION ALL SELECT `role_id`, 'contagem.pit_log' FROM `role` WHERE `code` = 'pit_logger'
UNION ALL SELECT `role_id`, 'timer.operate' FROM `role` WHERE `code` = 'timer_operator'
UNION ALL SELECT `role_id`, 'premiacao.view' FROM `role` WHERE `code` IN ('awards_viewer', 'platform_admin', 'program_manager', 'event_manager');

INSERT INTO `role_audit` (`occurred_at`, `actor_user_id`, `action`, `role_id`, `details`)
SELECT NOW(), NULL, 'role_create', `role_id`,
       JSON_OBJECT('code', `code`, 'source', 'PropelMigration_1793110000')
FROM `role` WHERE `code` IN ('lap_counter', 'pit_logger', 'timer_operator', 'awards_viewer');

INSERT INTO `role_audit` (`occurred_at`, `actor_user_id`, `action`, `role_id`, `details`)
SELECT NOW(), NULL, 'role_update', `role_id`,
       JSON_OBJECT('code', `code`, 'added', JSON_ARRAY('premiacao.view'), 'source', 'PropelMigration_1793110000')
FROM `role` WHERE `code` IN ('platform_admin', 'program_manager', 'event_manager');
EOT;

        return [
            'resultados' => $roles . "\n\nSTART TRANSACTION;\n\n"
                . implode(";\n\n", $this->grantStatements) . ($this->grantStatements === [] ? '' : ";\n\n")
                . "COMMIT;\n",
        ];
    }

    /**
     * Takes the migration's grants away and removes the four roles. Not
     * needed to roll the code back (the previous release reads
     * user.permissions, which this migration never changed), only to undo
     * it completely.
     *
     * Grants are never deleted by the application; this is the one place
     * that does, and only the migration's own (granted_by NULL, audit row
     * naming this migration). Their role_audit rows stay (the triggers keep
     * them).
     */
    public function getDownSQL(): array
    {
        $connection_resultados = <<< 'EOT'
DELETE g FROM `user_role` g
JOIN `role_audit` a ON a.`grant_id` = g.`grant_id` AND a.`action` = 'grant'
WHERE g.`granted_by` IS NULL
  AND JSON_UNQUOTE(JSON_EXTRACT(a.`details`, '$.source')) = 'PropelMigration_1793110000: legacy code';

DELETE FROM `role_permission` WHERE `permission` = 'premiacao.view';

DELETE r FROM `role` r
WHERE r.`code` IN ('lap_counter', 'pit_logger', 'timer_operator', 'awards_viewer')
  AND NOT EXISTS (SELECT 1 FROM `user_role` g WHERE g.`role_id` = r.`role_id`);
EOT;

        return [
            'resultados' => $connection_resultados,
        ];
    }
}
