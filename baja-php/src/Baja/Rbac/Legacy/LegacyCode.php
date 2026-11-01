<?php
declare(strict_types=1);

namespace Baja\Rbac\Legacy;

use Baja\Rbac\Scope\ScopeNode;

/**
 * Reads one legacy code from user.permissions and says which grant it
 * becomes (BAJAAPP-76). The table, with the call sites that checked each
 * code, is in docs/rbac.md, "From the legacy codes".
 *
 *     admin                      platform_admin       on the platform
 *     certificados               certificate_manager  on every top-level program
 *     index                      nothing (DROPPED)
 *     {EVT}_PREMIACAO            awards_viewer        on the event
 *     {EVT}_TIMER_ADMIN          timer_manager        on the event
 *     {EVT}_TIMER_{id}           timer_operator       on the event (timers are not nodes)
 *     {EVT}_FILA_{n}_ADMIN       queue_manager        on queue n
 *     {EVT}_FILA_{n}_{team}      nothing: KEPT, a team's code (next package)
 *     {EVT}_{PROVA}_CONT         lap_counter          on the prova
 *     {EVT}_{PROVA}_BOX          pit_logger           on the prova
 *     {EVT}_{PROVA}              judge                on the prova
 *     anything else              UNMAPPED, listed
 *
 * {EVT}_{PROVA} was the score entry code AND, on a lap counted prova, the
 * race controller's. The judge role carries both (prova.enter_scores and
 * contagem.control), as the code did.
 *
 * Pure: everything it needs to know about the tables comes from a
 * LegacyWorld, so it is tested without a database.
 */
final class LegacyCode
{
    public const PLATFORM_ADMIN = 'platform_admin';
    public const CERTIFICATE_MANAGER = 'certificate_manager';
    public const AWARDS_VIEWER = 'awards_viewer';
    public const TIMER_MANAGER = 'timer_manager';
    public const TIMER_OPERATOR = 'timer_operator';
    public const QUEUE_MANAGER = 'queue_manager';
    public const LAP_COUNTER = 'lap_counter';
    public const PIT_LOGGER = 'pit_logger';
    public const JUDGE = 'judge';

    public static function map(string $code, LegacyWorld $world): LegacyMapping
    {
        if ($code === 'admin') {
            return LegacyMapping::grant($code, self::PLATFORM_ADMIN, ScopeNode::platform());
        }
        if ($code === 'index') {
            return LegacyMapping::dropped($code, 'only opened the judge start page, which now follows any grant in the event');
        }
        if ($code === 'certificados') {
            $programas = $world->topLevelProgramas();
            if ($programas === []) {
                return LegacyMapping::unmapped($code, 'there is no program to give certificate_manager on');
            }
            return LegacyMapping::grant($code, self::CERTIFICATE_MANAGER, ...array_map(fn(string $p) => ScopeNode::programa($p), $programas));
        }

        $separator = strpos($code, '_');
        if ($separator === false || $separator === 0) {
            return LegacyMapping::unmapped($code, 'not a code this site ever checked');
        }
        $evento = substr($code, 0, $separator);
        $rest = substr($code, $separator + 1);
        if (!$world->eventExists($evento)) {
            return LegacyMapping::unmapped($code, "there is no event $evento");
        }

        if ($rest === 'PREMIACAO') {
            return LegacyMapping::grant($code, self::AWARDS_VIEWER, ScopeNode::evento($evento));
        }
        if ($rest === 'TIMER_ADMIN') {
            return LegacyMapping::grant($code, self::TIMER_MANAGER, ScopeNode::evento($evento));
        }
        if (preg_match('/^TIMER_(\d+)$/', $rest, $m) === 1) {
            return $world->timerExists($evento, (int) $m[1])
                ? LegacyMapping::grant($code, self::TIMER_OPERATOR, ScopeNode::evento($evento))
                : LegacyMapping::unmapped($code, "event $evento has no timer {$m[1]}");
        }
        if (preg_match('/^FILA_([^_]+)_(.+)$/', $rest, $m) === 1) {
            if ($m[2] !== 'ADMIN') {
                return LegacyMapping::kept($code, "a team's own queue code: stays in user.permissions (teams package)");
            }
            if (preg_match('/^\d+$/', $m[1]) !== 1 || !$world->filaExists($evento, (int) $m[1])) {
                return LegacyMapping::unmapped($code, "event $evento has no queue {$m[1]}");
            }
            return LegacyMapping::grant($code, self::QUEUE_MANAGER, ScopeNode::fila($evento, (int) $m[1]));
        }

        if ($world->provaExists($evento, $rest)) {
            return LegacyMapping::grant($code, self::JUDGE, ScopeNode::prova($evento, $rest));
        }
        foreach (['_CONT' => self::LAP_COUNTER, '_BOX' => self::PIT_LOGGER] as $suffix => $role) {
            if (str_ends_with($rest, $suffix)) {
                $prova = substr($rest, 0, -strlen($suffix));
                return $prova !== '' && $world->provaExists($evento, $prova)
                    ? LegacyMapping::grant($code, $role, ScopeNode::prova($evento, $prova))
                    : LegacyMapping::unmapped($code, "event $evento has no prova '$prova'");
            }
        }
        return LegacyMapping::unmapped($code, "event $evento has no prova '$rest'");
    }
}
