<?php
namespace Baja\Juiz;

use Baja\Auth\Csrf;
use Baja\Contagem\Access\ContagemAccess;
use Baja\Contagem\Access\ContagemRoles;
use Baja\Fila\Fila;
use Baja\Model\ProvaQuery;
use Baja\Model\UserQuery;
use Baja\Rbac\Authorization;
use Baja\Rbac\Delegation\GrantRefused;
use Baja\Rbac\GrantableNodes;
use Baja\Rbac\Grant\GrantRepository;
use Baja\Rbac\Permission\Permission;
use Baja\Rbac\Scope\ScopeNode;
use Baja\Session;
use DateTimeImmutable;

// One user's roles (BAJAAPP-76): their active grants, each with its place
// and end, and a form to give a role or take one away. Every change goes
// through RoleGranting, so the delegation rules, the expiry cap and the
// audit trail apply (docs/rbac.md). A stopgap until the role screens of the
// new site (BAJAAPP-78).
//
// For anyone who may give roles somewhere (role.grant): a platform admin
// everywhere, a head judge on their prova. The form offers only the places
// they cover, and RoleGranting checks again.
//
// The legacy permission list (user.permissions) is not edited here any
// more: it is frozen for one release, for rollback. Teams' own queue codes,
// which are not roles, are shown read-only and managed in
// admin_fila_acesso.php.

$granter = Session::getCurrentUser();
if (!Authorization::canAnywhere($granter, Permission::ROLE_GRANT)) {
    die(Authorization::REFUSAL);
}

// Giving and taking roles: refused without the form's token (Baja\Auth\Csrf).
if (isset($_REQUEST['act'])) Csrf::enforce();

if (!isset($_REQUEST['id'])) { header("Location: admin_usuarios.php"); exit(); }

$user = UserQuery::create()->findOneByUserId($_REQUEST['id']);
if (!$user) { header("Location: admin_usuarios.php"); exit(); }

$rbac = Authorization::services();
$h = fn($s) => htmlspecialchars((string)$s);
$erro = null;
$aviso = $_GET['ok'] ?? null;

if (@$_REQUEST['act'] == 'grant') {
    // The node arrives as "type:id", as the select below writes it.
    $nodeValue = is_string($_POST['node'] ?? null) ? $_POST['node'] : '';
    $parts = explode(':', $nodeValue, 2);
    $node = count($parts) === 2 ? ScopeNode::fromStored($parts[0], $parts[1]) : null;
    $roleCode = is_string($_POST['role'] ?? null) ? $_POST['role'] : '';

    // Empty end date: the RBAC core decides (7 days after the event, never
    // after the granter's own right to grant there). A date: until 23:59:59
    // that day.
    $fim = trim((string) ($_POST['valid_until'] ?? ''));
    $validUntil = null;
    if ($fim !== '') {
        $dia = DateTimeImmutable::createFromFormat('!Y-m-d', $fim);
        $validUntil = $dia === false ? false : $dia->setTime(23, 59, 59);
    }

    if ($node === null || $roleCode === '') {
        $erro = 'Escolha o papel e o lugar.';
    } elseif ($validUntil === false) {
        $erro = 'Data de término inválida.';
    } else {
        try {
            $rbac->granting->grant((int) $granter->getUserId(), (int) $user->getUserId(), $roleCode, $node, $validUntil);
            header("Location: admin_usuario.php?id=" . $user->getUserId() . "&ok=dado"); exit();
        } catch (GrantRefused $refused) {
            $erro = Papeis::recusa($refused);
        }
    }
}

if (@$_REQUEST['act'] == 'revoke') {
    try {
        $rbac->granting->revoke((int) $granter->getUserId(), (int) ($_POST['grant_id'] ?? 0));
        header("Location: admin_usuario.php?id=" . $user->getUserId() . "&ok=removido"); exit();
    } catch (GrantRefused $refused) {
        $erro = Papeis::recusa($refused);
    }
}

$grants = $rbac->authorizer->activeGrants((int) $user->getUserId());
$nomes = [];
$nomeDe = function (?int $userId) use (&$nomes): string {
    if ($userId === null) return 'migração';
    return $nomes[$userId] ??= (string) (UserQuery::create()->findPk($userId)?->getUsername() ?? "#$userId");
};

$papeis = array_filter($rbac->roles->all(), fn($r) => !$r->isSystem
    || Authorization::can($granter, Permission::ROLE_MANAGE, ScopeNode::platform()));
usort($papeis, fn($a, $b) => strcmp($a->name, $b->name));

$lugares = GrantableNodes::forUser($granter);
$codigosDeEquipe = Fila::codigosDeEquipe($user);

Template::printHeader("Papéis de Usuário", false, false);

echo '<style>.muted-note { color: #666; font-size: 12px; margin-top: 6px; line-height: 1.4; } .erro { color: #b00; } .ok { color: #070; }</style>';

?>
    <div style="max-width: 820px; margin: 0 auto;">
        <table id="grants" class="tablesorter" style="margin-bottom: 0;">
            <thead>
            <tr style="height: 50px">
                <th colspan="5" style="vertical-align: middle;" class="sorter-false">
                    <span style="float:left"><a href="admin_usuarios.php" style="color: white; font-size: 12px;">&nbsp;Voltar</a></span>
                    <span style="font-size: 28px;"><?= $h($user->getUsername()) ?></span>
                </th>
            </tr>
<?php if ($erro !== null) { ?>
            <tr><th colspan="5" class="erro" id="erro" style="background: #fee;"><?= $h($erro) ?></th></tr>
<?php } elseif ($aviso === 'dado' || $aviso === 'removido') { ?>
            <tr><th colspan="5" class="ok" id="ok" style="background: #efe;"><?= $aviso === 'dado' ? 'Papel dado.' : 'Papel removido.' ?></th></tr>
<?php } ?>
            <tr>
                <th>Papel</th>
                <th>Onde</th>
                <th>Até</th>
                <th>Dado por</th>
                <th class="sorter-false"></th>
            </tr>
            </thead>
            <tbody>
<?php if (!$grants) { ?>
            <tr><td colspan="5">Nenhum papel ativo.</td></tr>
<?php } ?>
<?php foreach ($grants as $g) {
        $role = $rbac->roles->byId($g->roleId);
        $node = $g->scope();
        $nomePapel = $role?->name ?? '?';
        // The pit log is named as its prova names it: "Registro de box", or
        // "... e abastecimento" when the prova logs the fueling station.
        if ($role?->code === ContagemRoles::PIT_LOGGER && $node !== null && $node->type->value === 'prova') {
            [$ev, $pv] = $node->eventAndLocalId();
            $provaDoPapel = ProvaQuery::create()->filterByEventoId($ev)->findOneByProvaId($pv);
            if ($provaDoPapel !== null) $nomePapel = ContagemAccess::pitLogLabel($provaDoPapel);
        }
?>
            <tr class="grant" data-grant="<?= (int) $g->id ?>">
                <td><?= $h($nomePapel) ?> <span class="muted-note">(<?= $h($role?->code ?? $g->roleId) ?>)</span></td>
                <td><?= $h($node === null ? $g->scopeType . ':' . $g->scopeId : GrantableNodes::describe($node)) ?></td>
                <td><?= $g->validUntil === null ? 'sem fim' : $h(GrantRepository::format($g->validUntil)) ?></td>
                <td><?= $h($nomeDe($g->grantedBy)) ?></td>
                <td>
<?php if ($node !== null && Authorization::can($granter, Permission::ROLE_GRANT, $node)) { ?>
                    <form action="admin_usuario.php?id=<?= $h($user->getUserId()) ?>&amp;act=revoke" method="POST" style="margin: 0;"
                          onsubmit="return confirm('Remover este papel?');">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="grant_id" value="<?= (int) $g->id ?>">
                        <input type="submit" value="Remover" style="color: #b00;">
                    </form>
<?php } ?>
                </td>
            </tr>
<?php } ?>
            </tbody>
        </table>

        <form action="admin_usuario.php?id=<?= $h($user->getUserId()) ?>&amp;act=grant" method="POST">
            <?= Csrf::field() ?>
            <table class="tablesorter" style="margin-top: 0;">
                <tbody>
                <tr><td style="text-align: left; line-height: 2;">
                    <strong>Dar um papel</strong><br />
                    Papel:
                    <select name="role" id="role" required>
                        <option value="">— escolher —</option>
<?php foreach ($papeis as $r) { ?>
                        <option value="<?= $h($r->code) ?>" data-types="<?= $h(implode(',', $r->allowedScopeTypeCodes())) ?>"><?= $h($r->name) ?> (<?= $h(implode(', ', $r->allowedScopeTypeCodes())) ?>)</option>
<?php } ?>
                    </select><br />
                    Onde:
                    <select name="node" id="node" required style="max-width: 100%;">
                        <option value="">— escolher —</option>
<?php $grupo = null;
      foreach ($lugares as $l) {
          if ($l['group'] !== $grupo) {
              if ($grupo !== null) echo '</optgroup>';
              $grupo = $l['group'];
              echo '<optgroup label="' . $h($grupo) . '">';
          } ?>
                        <option value="<?= $h($l['node']->type->value . ':' . $l['node']->id) ?>" data-type="<?= $h($l['node']->type->value) ?>"><?= $h($l['label']) ?></option>
<?php } if ($grupo !== null) echo '</optgroup>'; ?>
                    </select><br />
                    Até (opcional): <input type="date" name="valid_until" id="valid_until">
                    <div class="muted-note">
                        Sem data, um papel num evento (ou numa prova ou fila dele) termina
                        <?= $h(\Baja\Rbac\Expiry\GrantExpiry::configuredDays()) ?> dias depois do fim do evento,
                        e nunca depois do seu próprio acesso. Um papel num programa ou na
                        plataforma não tem fim, a não ser que o seu tenha.
                    </div>
                </td></tr>
                </tbody>
                <tfoot>
                <tr><th style="height: 30px"><input type="submit" value="Dar papel" /></th></tr>
                </tfoot>
            </table>
        </form>

<?php if ($codigosDeEquipe) { ?>
        <table class="tablesorter">
            <thead><tr><th>Acesso de equipe às filas (não é um papel)</th></tr></thead>
            <tbody>
            <tr><td style="text-align: left;">
<?php foreach ($codigosDeEquipe as $c) echo '<div>' . $h($c) . '</div>'; ?>
                <div class="muted-note">Gerenciado em <a href="admin_fila_acesso.php">Acesso em massa às filas</a>.</div>
            </td></tr>
            </tbody>
        </table>
<?php } ?>
    </div>

<script type="text/javascript">
    // Only the places where the chosen role may be given. The server checks again.
    $('#role').on('change', function () {
        var types = ($(this).find(':selected').data('types') || '').split(',');
        $('#node option[data-type]').each(function () {
            var ok = types.indexOf($(this).data('type')) !== -1;
            $(this).prop('disabled', !ok).toggle(ok);
        });
        if ($('#node option:selected').prop('disabled')) $('#node').val('');
    });
</script>

<?php
Template::printFooter();
