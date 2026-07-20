<?php
namespace Baja\Juiz;

use Baja\Fila\Fila;
use Baja\Model\EventoQuery;
use Baja\Model\FilaQuery;
use Baja\Model\UserQuery;
use Baja\Session;

Session::permissionCheck("admin");

$eventos = EventoQuery::create()->orderByEventoId('desc')->find();

$filasByEvent = [];
foreach (FilaQuery::create()->find() as $f) $filasByEvent[$f->getEventoId()][] = $f;

$eventsData = [];
foreach ($eventos as $ev) {
    $evId = $ev->getEventoId();
    $filas = [];
    foreach ($filasByEvent[$evId] ?? [] as $f) $filas[] = ['id' => (string)$f->getFilaId(), 'nome' => $f->getNome()];
    $eventsData[$evId] = ['nome' => $ev->getNome(), 'filas' => $filas];
}

/**
 * Parse one pasted line into a username and its team numbers.
 * Accepts "user<TAB|,|;>team[,team...]"; falls back to whitespace only when no
 * explicit separator is present, so usernames with spaces survive.
 */
function parseLine(string $line): ?array
{
    $line = trim($line);
    if ($line === '') return null;
    $tokens = preg_match('/[\t,;]/', $line) ? preg_split('/[\t,;]+/', $line) : preg_split('/\s+/', $line);
    $tokens = array_values(array_filter(array_map('trim', $tokens), fn($t) => $t !== ''));
    $userName = array_shift($tokens) ?? '';
    $teams = [];
    $bad = [];
    foreach ($tokens as $t) {
        if (preg_match('/^\d+$/', $t)) $teams[] = $t;
        else $bad[] = $t;
    }
    return ['user' => $userName, 'teams' => $teams, 'bad' => $bad];
}

$results = null;
if (@$_REQUEST['act'] == 'grant') {
    $ev = $_POST['evento'] ?? '';
    $filaSel = $_POST['fila'] ?? '';
    $filaIds = $filaSel === '*'
        ? array_column($eventsData[$ev]['filas'] ?? [], 'id')
        : [$filaSel];

    $results = [];
    foreach (preg_split("/\r\n|\r|\n/", (string)($_POST['lista'] ?? '')) as $line) {
        $parsed = parseLine($line);
        if ($parsed === null) continue;
        if ($parsed['user'] === '' || !$parsed['teams']) {
            $results[] = ['user' => $parsed['user'], 'team' => '', 'status' => 'erro: linha sem usuário ou equipe'];
            continue;
        }
        foreach ($parsed['teams'] as $team) {
            foreach ($filaIds as $fid) {
                $ok = Fila::addPermissaoFila($ev, $fid, $team, $parsed['user']);
                $results[] = [
                    'user' => $parsed['user'],
                    'team' => $team,
                    'status' => $ok ? "ok (fila $fid)" : "falhou (fila $fid)",
                ];
            }
        }
        foreach ($parsed['bad'] as $b) {
            $results[] = ['user' => $parsed['user'], 'team' => $b, 'status' => 'ignorado: equipe não numérica'];
        }
    }
}

$revokeResults = null;
if (@$_REQUEST['act'] == 'revoke') {
    $ev = $_POST['rm_evento'] ?? '';
    $filaSel = $_POST['rm_fila'] ?? '';
    // '*' nukes every fila of the event; otherwise a single fila. The trailing
    // underscore keeps fila "1" from also matching "10".
    $prefix = $filaSel === '*' ? $ev.'_FILA_' : $ev.'_FILA_'.$filaSel.'_';

    $revokeResults = [];
    foreach (UserQuery::create()->find() as $u) {
        $before = $u->getPermissions();
        $kept = array_values(array_filter($before, fn($p) => strpos($p, $prefix) !== 0));
        $removed = count($before) - count($kept);
        if ($removed === 0) continue;

        $u->setPermissions($kept);
        $deleted = false;
        if ($u->hasNoMeaningfulPermissions()) {
            $u->delete();
            $deleted = true;
        } else {
            $u->save();
        }
        $revokeResults[] = ['user' => $u->getUsername(), 'removed' => $removed, 'deleted' => $deleted];
    }
}

$h = fn($s) => htmlspecialchars((string)$s);

Template::printHeader("Acesso em massa às filas", false, false);

?>
    <div style="max-width: 720px; margin: 0 auto;">
        <form action="admin_fila_acesso.php?act=grant" method="POST">
            <table id="myTable" class="tablesorter" style="margin-bottom: 0;">
                <thead>
                <tr style="height: 50px">
                    <th style="vertical-align: middle;" class="sorter-false">
                        <span style="float:left"><a href="admin_usuarios.php" style="color: white; font-size: 12px;">&nbsp;Voltar</a></span>
                        <span style="font-size: 24px;">Acesso em massa às filas</span>
                    </th>
                </tr>
                </thead>
                <tbody>
                <tr><td style="text-align: left; line-height: 2.2;">
                    Evento:
                    <select id="evento" name="evento">
<?php foreach ($eventsData as $evId => $d) { ?>
                        <option value="<?= $h($evId) ?>"><?= $h($evId) ?> — <?= $h($d['nome']) ?></option>
<?php } ?>
                    </select>
                    &emsp;Fila: <select id="fila" name="fila"></select>
                </td></tr>
                <tr><td style="text-align: left;">
                    <p style="margin: 4px 0; color: #555;">
                        Cole uma linha por acesso: <strong>usuário</strong> e o <strong>número da equipe</strong>,
                        separados por tabulação, vírgula ou ponto-e-vírgula.
                        Ex.: <code>joaosilva, 42</code> &nbsp;ou colar duas colunas de uma planilha.
                        Vários números na mesma linha concedem acesso a várias equipes.
                    </p>
                    <textarea name="lista" rows="12" style="width: 100%; box-sizing: border-box; font-family: monospace;" placeholder="usuario1&#9;12&#10;usuario2, 34&#10;usuario3; 5"><?= $h($_POST['lista'] ?? '') ?></textarea>
                </td></tr>
                </tbody>
                <tfoot>
                <tr>
                    <th style="height: 30px">
                        <input type="submit" value="Conceder acessos" />
                    </th>
                </tr>
                </tfoot>
            </table>
        </form>

<?php if ($results !== null) { ?>
        <br />
        <table id="myTable2" class="tablesorter">
            <thead>
                <tr><th colspan="3" class="sorter-false" style="height: 40px;">Resultado (<?= count($results) ?>)</th></tr>
                <tr><th>Usuário</th><th>Equipe</th><th class="sorter-false">Status</th></tr>
            </thead>
            <tbody>
<?php foreach ($results as $r) { ?>
                <tr>
                    <td><?= $h($r['user']) ?></td>
                    <td><?= $h($r['team']) ?></td>
                    <td style="<?= strpos($r['status'], 'ok') === 0 ? '' : 'color:#b00;' ?>"><?= $h($r['status']) ?></td>
                </tr>
<?php } ?>
            </tbody>
        </table>
<?php } ?>

        <br /><br />

        <form action="admin_fila_acesso.php?act=revoke" method="POST" onsubmit="return confirm('Remover TODAS as permissões de acesso à(s) fila(s) selecionada(s) de TODOS os usuários? Esta ação não pode ser desfeita.');">
            <table id="myTable3" class="tablesorter" style="margin-bottom: 0;">
                <thead>
                <tr style="height: 50px">
                    <th style="vertical-align: middle;" class="sorter-false">
                        <span style="font-size: 24px; color: #b00;">Remoção de acesso em massa às filas</span>
                    </th>
                </tr>
                </thead>
                <tbody>
                <tr><td style="text-align: left; line-height: 2.2;">
                    Evento:
                    <select id="rm_evento" name="rm_evento">
<?php foreach ($eventsData as $evId => $d) { ?>
                        <option value="<?= $h($evId) ?>"><?= $h($evId) ?> — <?= $h($d['nome']) ?></option>
<?php } ?>
                    </select>
                    &emsp;Fila: <select id="rm_fila" name="rm_fila"></select>
                </td></tr>
                <tr><td style="text-align: left;">
                    <p style="margin: 4px 0; color: #555;">
                        Remove de <strong>todos os usuários</strong> as permissões de acesso à fila escolhida
                        (equipes e admins). Selecione <code>* (todas as filas)</code> para limpar o evento inteiro.
                        Usuários que ficarem sem nenhuma permissão são excluídos.
                    </p>
                </td></tr>
                </tbody>
                <tfoot>
                <tr>
                    <th style="height: 30px">
                        <input type="submit" value="Remover acessos" style="color: #b00;" />
                    </th>
                </tr>
                </tfoot>
            </table>
        </form>

<?php if ($revokeResults !== null) { ?>
        <br />
        <table id="myTable4" class="tablesorter">
            <thead>
                <tr><th colspan="2" class="sorter-false" style="height: 40px;">Removidos (<?= count($revokeResults) ?> usuários)</th></tr>
                <tr><th>Usuário</th><th class="sorter-false">Permissões removidas</th></tr>
            </thead>
            <tbody>
<?php if (!$revokeResults) { ?>
                <tr><td colspan="2" style="color:#555;">Nenhuma permissão correspondente encontrada.</td></tr>
<?php } foreach ($revokeResults as $r) { ?>
                <tr>
                    <td><?= $h($r['user']) ?></td>
                    <td><?= $h($r['removed']) ?><?= $r['deleted'] ? ' — usuário excluído (sem permissões restantes)' : '' ?></td>
                </tr>
<?php } ?>
            </tbody>
        </table>
<?php } ?>
    </div>

<script type="text/javascript">
    var EVENTS = <?= json_encode($eventsData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;

    // Wire an event <select> to its fila <select>, keeping a submitted value selected.
    function wireFilas(eventSel, filaSel, preselect) {
        function refresh() {
            var d = EVENTS[$(eventSel).val()] || { filas: [] };
            var $f = $(filaSel).empty();
            $f.append($('<option></option>').val('*').text('* (todas as filas)'));
            d.filas.forEach(function (fl) { $f.append($('<option></option>').val(fl.id).text(fl.id + ' — ' + fl.nome)); });
            if (preselect !== null && preselect !== undefined) { $f.val(preselect); preselect = null; }
        }
        $(eventSel).on('change', refresh);
        refresh();
    }

    <?php if (!empty($_POST['evento'])) { ?>$('#evento').val(<?= json_encode($_POST['evento']) ?>);<?php } ?>
    wireFilas('#evento', '#fila', <?= json_encode($_POST['fila'] ?? null) ?>);

    <?php if (!empty($_POST['rm_evento'])) { ?>$('#rm_evento').val(<?= json_encode($_POST['rm_evento']) ?>);<?php } ?>
    wireFilas('#rm_evento', '#rm_fila', <?= json_encode($_POST['rm_fila'] ?? null) ?>);
</script>

<?php
Template::printFooter();
