<?php
declare(strict_types=1);

namespace Baja\Rbac;

use Baja\Model\User;
use Baja\Rbac\Permission\Permission;
use Baja\Rbac\Scope\ScopeNode;
use Baja\Rbac\Scope\ScopeType;
use PDO;
use Propel\Runtime\Propel;

/**
 * The places a user may give roles on (role.grant on the node or above),
 * with a label each, for the form of juiz/admin_usuario.php (BAJAAPP-76).
 *
 * Only an offer: RoleGranting decides again when the form is sent.
 */
final class GrantableNodes
{
    /**
     * @return array<int, array{node: ScopeNode, label: string, group: string}>
     *         in the order a person reads them: platform, programs, then each
     *         event (newest first) with its provas and queues
     */
    public static function forUser(User $user): array
    {
        $con = Propel::getReadConnection('resultados');
        $candidates = [[ScopeNode::platform(), 'Plataforma (tudo)', 'Geral']];

        foreach ($con->query('SELECT programa_id, nome FROM programa ORDER BY ordem, programa_id')->fetchAll(PDO::FETCH_NUM) as [$id, $nome]) {
            $candidates[] = [ScopeNode::programa((string) $id), "Programa $id — $nome", 'Programas'];
        }

        $provas = [];
        foreach ($con->query('SELECT evento_id, prova_id, nome FROM prova ORDER BY prova_id')->fetchAll(PDO::FETCH_NUM) as [$ev, $id, $nome]) {
            $provas[(string) $ev][] = [(string) $id, (string) $nome];
        }
        $filas = [];
        foreach ($con->query('SELECT evento_id, fila_id, nome FROM fila ORDER BY fila_id')->fetchAll(PDO::FETCH_NUM) as [$ev, $id, $nome]) {
            $filas[(string) $ev][] = [(int) $id, (string) $nome];
        }
        foreach ($con->query('SELECT evento_id, nome FROM evento ORDER BY evento_id DESC')->fetchAll(PDO::FETCH_NUM) as [$ev, $nome]) {
            $ev = (string) $ev;
            $group = 'Evento ' . $ev;
            $candidates[] = [ScopeNode::evento($ev), "Evento $ev — " . html_entity_decode((string) $nome, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $group];
            foreach ($provas[$ev] ?? [] as [$id, $nomeProva]) {
                $candidates[] = [ScopeNode::prova($ev, $id), "$ev · prova " . strtoupper($id) . " — $nomeProva", $group];
            }
            foreach ($filas[$ev] ?? [] as [$id, $nomeFila]) {
                $candidates[] = [ScopeNode::fila($ev, $id), "$ev · fila $id — $nomeFila", $group];
            }
        }

        $offered = [];
        foreach ($candidates as [$node, $label, $group]) {
            if (Authorization::can($user, Permission::ROLE_GRANT, $node)) {
                $offered[] = ['node' => $node, 'label' => $label, 'group' => $group];
            }
        }
        return $offered;
    }

    /** "prova 26BR/END" and the like, for the list of grants. */
    public static function describe(ScopeNode $node): string
    {
        return match ($node->type) {
            ScopeType::PLATFORM => 'plataforma',
            ScopeType::PROGRAMA => 'programa ' . $node->id,
            ScopeType::EVENTO => 'evento ' . $node->id,
            ScopeType::PROVA => 'prova ' . $node->id,
            ScopeType::FILA => 'fila ' . $node->id,
        };
    }
}
