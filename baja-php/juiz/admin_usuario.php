<?php
namespace Baja\Juiz;

use Baja\Auth\Csrf;
use Baja\Contagem\Access\ContagemAccess;
use Baja\Contagem\Access\ContagemRoles;
use Baja\Fila\Fila;
use Baja\Model\ProvaQuery;
use Baja\Model\UserQuery;
use Baja\Rbac\Authorization;
use Baja\Rbac\Delegation\BatchRefused;
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
// they cover, and RoleGranting checks again. Several places may be ticked
// at once (BAJAAPP-91): RoleGranting::grantOnMany gives them all or none.
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
// A refusal is shown in the table of the form that asked: giving in the
// form at the bottom, taking away in the list of grants.
$erroDar = null;
$erroRemover = null;
$aviso = $_GET['ok'] ?? null;

// The places the viewer may give roles on, with their labels: the form's
// checkboxes, and the names of the places in its messages.
$lugares = GrantableNodes::forUser($granter);
$rotulos = [];
foreach ($lugares as $l) $rotulos[$l['node']->key()] = $l['label'];
$rotuloDe = fn(ScopeNode $node): string => $rotulos[$node->key()] ?? GrantableNodes::describe($node);

// What the form showed when it was sent, to show it again after a refusal:
// the role, the ticked places ("type:id" => true) and the end date.
$papelMarcado = '';
$marcados = [];
$fimDigitado = '';
// The places refused in the last batch, each with its reason (BAJAAPP-91).
$recusados = [];

if (@$_REQUEST['act'] == 'grant') {
    $papelMarcado = $roleCode = is_string($_POST['role'] ?? null) ? $_POST['role'] : '';

    // Each ticked place arrives as "type:id", as the checkboxes below write it.
    $nodes = [];
    $valoresInvalidos = false;
    foreach (is_array($_POST['nodes'] ?? null) ? $_POST['nodes'] : [] as $valor) {
        $parts = is_string($valor) ? explode(':', $valor, 2) : [];
        $node = count($parts) === 2 ? ScopeNode::fromStored($parts[0], $parts[1]) : null;
        if ($node === null) {
            $valoresInvalidos = true;
            continue;
        }
        $nodes[] = $node;
        $marcados[$node->key()] = true;
    }

    // Empty end date: the RBAC core decides (7 days after the event, never
    // after the granter's own right to grant there). A date: until 23:59:59
    // that day, on every place ticked.
    $fimDigitado = $fim = trim(is_string($_POST['valid_until'] ?? null) ? $_POST['valid_until'] : '');
    $validUntil = null;
    if ($fim !== '') {
        $dia = DateTimeImmutable::createFromFormat('!Y-m-d', $fim);
        $validUntil = $dia === false ? false : $dia->setTime(23, 59, 59);
    }

    if ($roleCode === '' || ($nodes === [] && !$valoresInvalidos)) {
        $erroDar = 'Escolha o papel e pelo menos um lugar.';
    } elseif ($valoresInvalidos) {
        $erroDar = 'Um dos lugares marcados não existe.';
    } elseif ($validUntil === false) {
        $erroDar = 'Data de término inválida.';
    } else {
        // All or nothing (RoleGranting::grantOnMany): one refused place and
        // no place is given; the page then lists each refused one.
        try {
            $batch = $rbac->granting->grantOnMany((int) $granter->getUserId(), (int) $user->getUserId(), $roleCode, $nodes, $validUntil);
            $cobertos = [];
            foreach ($batch->covered as $c) $cobertos[$c['node']->key()] = $c['by']->key();
            header('Location: admin_usuario.php?' . http_build_query(['id' => $user->getUserId(), 'ok' => 'dado', 'n' => count($batch->granted), 'cobertos' => $cobertos]));
            exit();
        } catch (BatchRefused $batchRefused) {
            foreach ($batchRefused->refusals as $r) {
                $recusados[] = [$rotuloDe($r['node']), Papeis::recusa($r['refused'])];
            }
            $erroDar = count($recusados) === 1
                ? 'um lugar foi recusado, e por isso nenhum foi dado. Desmarque-o ou corrija e envie de novo.'
                : count($recusados) . ' lugares foram recusados, e por isso nenhum foi dado. Desmarque-os ou corrija e envie de novo.';
        } catch (GrantRefused $refused) {
            $erroDar = Papeis::recusa($refused);
        }
    }
}

// After a batch: how many places got the role, and which ticked places
// were left out because a ticked place above them already reaches them.
// Read back from the redirect's query string; only labels are shown.
$dadosEm = (int) ($_GET['n'] ?? 0);
$cobertosAviso = [];
foreach (is_array($_GET['cobertos'] ?? null) ? $_GET['cobertos'] : [] as $de => $por) {
    $partsDe = explode(':', (string) $de, 2);
    $partsPor = explode(':', (string) $por, 2);
    $nodeDe = count($partsDe) === 2 ? ScopeNode::fromStored(...$partsDe) : null;
    $nodePor = count($partsPor) === 2 ? ScopeNode::fromStored(...$partsPor) : null;
    if ($nodeDe !== null && $nodePor !== null) $cobertosAviso[] = [$rotuloDe($nodeDe), $rotuloDe($nodePor)];
}

if (@$_REQUEST['act'] == 'revoke') {
    try {
        $rbac->granting->revoke((int) $granter->getUserId(), (int) ($_POST['grant_id'] ?? 0));
        header("Location: admin_usuario.php?id=" . $user->getUserId() . "&ok=removido"); exit();
    } catch (GrantRefused $refused) {
        $erroRemover = Papeis::recusa($refused);
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

$codigosDeEquipe = Fila::codigosDeEquipe($user);

Template::printHeader("Papéis de Usuário", false, false);

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
            <tr>
                <th>Papel</th>
                <th>Onde</th>
                <th>Até</th>
                <th>Dado por</th>
                <th class="sorter-false"></th>
            </tr>
            </thead>
            <tbody>
<?php if ($erroRemover !== null) { ?>
            <tr><td colspan="5" id="erro" style="color: red; font-weight: bold;"><?= $h($erroRemover) ?></td></tr>
<?php } elseif ($aviso === 'removido') { ?>
            <tr><td colspan="5" id="ok" style="color: green; font-weight: bold;">Papel removido.</td></tr>
<?php } ?>
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
                <td><?= $h($nomePapel) ?> <span style="font-size: 12px;">(<?= $h($role?->code ?? $g->roleId) ?>)</span></td>
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
            <?php // Laid out as the other admin forms (evento.php): a label cell and a control cell per field. ?>
            <table id="dar" class="tablesorter" style="margin-top: 0;">
                <thead>
                <tr>
                    <th colspan="2" class="sorter-false">Dar um papel</th>
                </tr>
                </thead>
                <tbody>
<?php if ($erroDar !== null) { ?>
                <tr>
                    <td colspan="2" id="erro" style="color: red; font-weight: bold;">Papel não dado: <?= $h($erroDar) ?>
<?php if ($recusados) { ?>
                        <ul id="recusados">
<?php foreach ($recusados as [$rotulo, $motivo]) { ?>
                            <li><?= $h($rotulo) ?>: <?= $h($motivo) ?></li>
<?php } ?>
                        </ul>
<?php } ?>
                    </td>
                </tr>
<?php } elseif ($aviso === 'dado') { ?>
                <tr>
                    <td colspan="2" id="ok" style="color: green; font-weight: bold;"><?= $dadosEm > 1 ? 'Papel dado em ' . $dadosEm . ' lugares.' : 'Papel dado.' ?>
<?php if ($cobertosAviso) { ?>
                        Não foi dado à parte em, porque o lugar marcado acima deles já os inclui:
                        <ul id="cobertos">
<?php foreach ($cobertosAviso as [$de, $por]) { ?>
                            <li><?= $h($de) ?> (incluído em <?= $h($por) ?>)</li>
<?php } ?>
                        </ul>
<?php } ?>
                    </td>
                </tr>
<?php } ?>
                <tr>
                    <td>Papel</td>
                    <td><select name="role" id="role" required>
                        <option value="">— escolher —</option>
<?php foreach ($papeis as $r) { ?>
                        <option value="<?= $h($r->code) ?>" data-types="<?= $h(implode(',', $r->allowedScopeTypeCodes())) ?>"<?= $r->code === $papelMarcado ? ' selected' : '' ?>><?= $h($r->name) ?> (<?= $h(implode(', ', $r->allowedScopeTypeCodes())) ?>)</option>
<?php } ?>
                    </select></td>
                </tr>
                <tr>
                    <td>Onde</td>
                    <td id="lugares">
                        <?php // Shown by the script below: without JavaScript there is no filter, and every group is open. ?>
                        <input type="search" id="filtro" placeholder="Filtrar lugares" hidden />
<?php // One group per heading of GrantableNodes (Geral, Programas, one per event), open as sent; the script closes the events'.
      $grupos = [];
      foreach ($lugares as $l) $grupos[$l['group']][] = $l;
      foreach ($grupos as $grupo => $doGrupo) {
          $ehEvento = $grupo !== 'Geral' && $grupo !== 'Programas'; ?>
                        <details open data-grupo="<?= $h($grupo) ?>"<?= $ehEvento ? ' data-evento' : '' ?>>
                            <summary><?= $h($grupo) ?></summary>
                            <button type="button" data-marcar-todos hidden>marcar todos</button><br />
<?php     foreach ($doGrupo as $l) {
              $valor = $l['node']->key(); ?>
                            <label data-type="<?= $h($l['node']->type->value) ?>"><input type="checkbox" name="nodes[]" value="<?= $h($valor) ?>"<?= isset($marcados[$valor]) ? ' checked' : '' ?> /> <?= $h($l['label']) ?></label><br />
<?php     } ?>
                        </details>
<?php } ?>
                        <span style="font-size: 12px;">Um evento inclui todas as suas provas e filas, inclusive as criadas depois. Marque o evento em vez de cada prova quando for esse o caso.</span>
                    </td>
                </tr>
                <tr>
                    <td>Até (opcional)</td>
                    <td><input type="date" name="valid_until" id="valid_until" value="<?= $h($fimDigitado) ?>" />
                        <span style="font-size: 12px; margin-left: 8px;">Sem data, um papel num evento (ou numa prova ou fila dele) termina
                        <?= $h(\Baja\Rbac\Expiry\GrantExpiry::configuredDays()) ?> dias depois do fim do evento,
                        e nunca depois do seu próprio acesso. Um papel num programa ou na
                        plataforma não tem fim, a não ser que o seu tenha.</span></td>
                </tr>
                </tbody>
                <tfoot>
                <tr>
                    <th style="height: 30px;" colspan="2"><input type="submit" value="Dar papel" /></th>
                </tr>
                </tfoot>
            </table>
        </form>

<?php if ($codigosDeEquipe) { ?>
        <table class="tablesorter">
            <thead><tr><th>Acesso de equipe às filas (não é um papel)</th></tr></thead>
            <tbody>
            <tr><td style="text-align: left;">
<?php foreach ($codigosDeEquipe as $c) echo '<div>' . $h($c) . '</div>'; ?>
                <div style="font-size: 12px;">Gerenciado em <a href="admin_fila_acesso.php">Acesso em massa às filas</a>.</div>
            </td></tr>
            </tbody>
        </table>
<?php } ?>
    </div>

<script type="text/javascript">
    // The list of places (BAJAAPP-91). Without this script every group stays
    // open and there is no filter; the form works the same.
    $(function () {
        var $lugares = $('#lugares');

        // Only the places where the chosen role may be given (data-types).
        // The server checks again, place by place.
        function permitidos() {
            var types = ($('#role').find(':selected').data('types') || '').split(',');
            $lugares.find('label[data-type]').each(function () {
                var ok = $('#role').val() === '' || types.indexOf($(this).data('type')) !== -1;
                this.dataset.foraDoPapel = ok ? '' : '1';
                if (!ok) $(this).find('input').prop('checked', false);
            });
        }

        // The text filter narrows the list by label, ignoring case and accents.
        function semAcento(texto) {
            return texto.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
        }

        function mostrar() {
            var busca = semAcento($('#filtro').val() || '');
            $lugares.find('details').each(function () {
                var algum = false;
                $(this).find('label[data-type]').each(function () {
                    var visivel = this.dataset.foraDoPapel !== '1' && (busca === '' || semAcento($(this).text()).indexOf(busca) !== -1);
                    $(this).toggle(visivel).next('br').toggle(visivel);
                    algum = algum || visivel;
                });
                $(this).toggle(algum);
                if (busca !== '' && algum) this.open = true;
            });
        }

        // "marcar todos": every place of the group still shown.
        $lugares.on('click', '[data-marcar-todos]', function () {
            $(this).closest('details').find('label[data-type]:visible input').prop('checked', true);
        });

        $('#role').on('change', function () { permitidos(); mostrar(); });
        $('#filtro').on('input', mostrar);

        // Events start closed, unless something in them is ticked (the
        // form came back after a refusal).
        $lugares.find('details[data-evento]').each(function () {
            if ($(this).find('input:checked').length === 0) this.open = false;
        });
        $('#filtro').prop('hidden', false);
        $lugares.find('[data-marcar-todos]').prop('hidden', false);
        permitidos();
        mostrar();
    });
</script>

<?php
Template::printFooter();
