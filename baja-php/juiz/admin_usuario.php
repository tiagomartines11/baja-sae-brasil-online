<?php
namespace Baja\Juiz;

use Baja\Model\EventoQuery;
use Baja\Model\FilaQuery;
use Baja\Model\ProvaQuery;
use Baja\Model\UserQuery;
use Baja\Session;

Session::permissionCheck("admin");

if (!isset($_REQUEST['id'])) { header("Location: admin_usuarios.php"); exit(); }

$user = UserQuery::create()->findOneByUserId($_REQUEST['id']);
if (!$user) { header("Location: admin_usuarios.php"); exit(); }

if (@$_REQUEST['act'] == 'save') {
    // This page controls the user's FULL permission set across every scope, so a
    // plain overwrite is correct here. Empty strings and duplicates are dropped.
    $posted = array_values(array_unique(array_filter(
        (array)@$_POST['j'],
        fn($v) => trim($v) !== ''
    )));

    $user->setPermissions($posted);

    if ($user->hasNoMeaningfulPermissions()) {
        $user->delete();
        header("Location: admin_usuarios.php");
    } else {
        if (!in_array('index', $posted, true)) {
            $posted[] = 'index';
            $user->setPermissions($posted);
        }
        $user->save();
        header("Location: admin_usuario.php?id=".$user->getUserId());
    }
    exit();
}

$perms = $user->getPermissions();

$eventos = EventoQuery::create()->orderByEventoId('desc')->find();

$provasByEvent = [];
foreach (ProvaQuery::create()->find() as $p) $provasByEvent[$p->getEventoId()][] = $p;

$filasByEvent = [];
foreach (FilaQuery::create()->find() as $f) $filasByEvent[$f->getEventoId()][] = $f;

// Compact data model handed to the client so it can build event sections and the
// fila helper without a round-trip.
$eventsData = [];
foreach ($eventos as $ev) {
    $evId = $ev->getEventoId();
    $provas = [];
    foreach ($provasByEvent[$evId] ?? [] as $p) $provas[] = ['code' => $p->getFullCode(), 'label' => strtoupper($p->getProvaId())];
    $filas = [];
    foreach ($filasByEvent[$evId] ?? [] as $f) $filas[] = ['id' => (string)$f->getFilaId(), 'nome' => $f->getNome()];
    $eventsData[$evId] = ['nome' => $ev->getNome(), 'provas' => $provas, 'filas' => $filas];
}

// Split the user's permissions: global sentinels/flags, per-event buckets, and
// anything that doesn't map to a known event (surfaced raw so it's never hidden).
$globalPerms = [];
$permsByEvent = [];
$otherPerms = [];
foreach ($perms as $p) {
    if (in_array($p, ['admin', 'index', 'certificados'], true)) { $globalPerms[] = $p; continue; }
    $matched = false;
    foreach ($eventsData as $evId => $_d) {
        if (strpos($p, $evId.'_') === 0) { $permsByEvent[$evId][] = $p; $matched = true; break; }
    }
    if (!$matched) $otherPerms[] = $p;
}

$h = fn($s) => htmlspecialchars((string)$s);
$cb = function ($code, $label, $checked) use ($h) {
    return '<label style="display:inline-block; margin: 2px 12px 2px 0; white-space: nowrap;">'
        . '<input type="checkbox" name="j[]" value="'.$h($code).'" '.($checked ? 'checked' : '').'> '
        . $h($label) . '</label>';
};

Template::printHeader("Permissões de Usuário", false, false);

echo '<style>.muted-note { color: #666; font-size: 12px; margin-top: 6px; line-height: 1.4; }</style>';

?>
    <div style="max-width: 720px; margin: 0 auto;">
        <form action="admin_usuario.php?id=<?= $user->getUserId() ?>&amp;act=save" method="POST">
            <table id="myTable" class="tablesorter" style="margin-bottom: 0;">
                <thead>
                <tr style="height: 50px">
                    <th style="vertical-align: middle;" class="sorter-false">
                        <span style="float:left"><a href="admin_usuarios.php" style="color: white; font-size: 12px;">&nbsp;Voltar</a></span>
                        <span style="font-size: 28px;"><?= $h($user->getUsername()) ?></span>
                    </th>
                </tr>
                </thead>
                <tbody>
                <tr><td style="text-align: left;">
                    <strong>Global</strong><br />
                    <?= $cb('admin', 'admin', in_array('admin', $perms, true)) ?>
                    <?= $cb('index', 'index', in_array('index', $perms, true)) ?>
                    <?= $cb('certificados', 'certificados', in_array('certificados', $perms, true)) ?>
                    <div class="muted-note">
                        <strong>certificados</strong> permite emitir certificados para
                        qualquer evento. Não é a mesma coisa que ser juiz.
                    </div>
                </td></tr>

                <tr><td style="text-align: left;">
                    <strong>Adicionar permissões de fila</strong><br />
                    <div style="margin-top: 6px; line-height: 2;">
                        Evento:
                        <select id="fhEvent">
<?php foreach ($eventsData as $evId => $d) { ?>
                            <option value="<?= $h($evId) ?>"><?= $h($evId) ?> — <?= $h($d['nome']) ?></option>
<?php } ?>
                        </select>
                        Fila: <select id="fhFila"></select><br />
                        <label><input type="radio" name="fhType" value="ADMIN" checked> Admin da fila</label>&emsp;
                        <label><input type="radio" name="fhType" value="TEAM"> Equipe nº</label>
                        <input type="number" id="fhTeam" min="1" step="1" style="width: 90px;">
                        <input type="button" id="fhAdd" value="Adicionar" />
                    </div>
                </td></tr>

                <tr><td style="text-align: left;">
                    <strong>Adicionar evento</strong>
                    <select id="addEvent">
                        <option value="">— escolher evento —</option>
<?php foreach ($eventsData as $evId => $d) { ?>
                        <option value="<?= $h($evId) ?>"><?= $h($evId) ?> — <?= $h($d['nome']) ?></option>
<?php } ?>
                    </select>
                    <input type="button" id="addEventBtn" value="Adicionar" />
                </td></tr>

                <tr><td style="text-align: left; padding: 0;">
                    <div id="eventSections">
<?php foreach ($eventsData as $evId => $d) {
        if (!isset($permsByEvent[$evId])) continue;
        $provaCodes = array_column($d['provas'], 'code');
        $premiacao = $evId.'_PREMIACAO';
?>
                        <div class="event-section" data-ev="<?= $h($evId) ?>" style="padding: 8px 8px 12px; border-top: 1px solid #ddd;">
                            <strong><?= $h($evId) ?></strong> &mdash; <?= $h($d['nome']) ?><br />
<?php foreach ($d['provas'] as $pr) echo $cb($pr['code'], $pr['label'], in_array($pr['code'], $perms, true)); ?>
                            <?= $cb($premiacao, 'PREMIACAO', in_array($premiacao, $perms, true)) ?>
                            <div class="outras">
<?php foreach ($permsByEvent[$evId] as $p) {
          if (in_array($p, $provaCodes, true) || $p === $premiacao) continue;
          echo '<div>'.$cb($p, $p, true).'</div>';
      } ?>
                            </div>
                        </div>
<?php } ?>
                    </div>
                </td></tr>

<?php if ($otherPerms) { ?>
                <tr><td style="text-align: left;">
                    <strong>Outras permissões</strong>
                    <div id="others">
<?php foreach ($otherPerms as $code) echo '<div>'.$cb($code, $code, true).'</div>'; ?>
                    </div>
                </td></tr>
<?php } ?>
                </tbody>
                <tfoot>
                <tr>
                    <th style="height: 30px">
                        <input type="button" id="clearAll" value="Limpar tudo" style="color: #b00; margin-right: 12px;" />
                        <input type="submit" name="submit" value="Salvar" />
                    </th>
                </tr>
                </tfoot>
            </table>
        </form>
    </div>

<script type="text/javascript">
    var EVENTS = <?= json_encode($eventsData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;

    function cb(code, label, checked) {
        var $l = $('<label style="display:inline-block; margin: 2px 12px 2px 0; white-space: nowrap;"></label>');
        var $i = $('<input type="checkbox" name="j[]">').val(code);
        if (checked) $i.prop('checked', true);
        $l.append($i).append(' ').append(document.createTextNode(label));
        return $l;
    }

    // Ensures an event section exists in the DOM, building it (all provas
    // unchecked + PREMIACAO + empty "outras" bucket) if needed. Returns the section.
    function ensureSection(evId) {
        var $s = $('.event-section[data-ev="' + evId + '"]');
        if ($s.length) return $s;
        var d = EVENTS[evId];
        if (!d) return $();
        $s = $('<div class="event-section" style="padding: 8px 8px 12px; border-top: 1px solid #ddd;"></div>').attr('data-ev', evId);
        $s.append($('<strong></strong>').text(evId)).append(document.createTextNode(' — ' + d.nome)).append('<br />');
        d.provas.forEach(function (p) { $s.append(cb(p.code, p.label, false)); });
        $s.append(cb(evId + '_PREMIACAO', 'PREMIACAO', false));
        $s.append('<div class="outras"></div>');
        $('#eventSections').append($s);
        return $s;
    }

    // Add a raw permission code as a removable checkbox inside its event's "outras"
    // bucket (creating the event section on demand). No-op if it already exists.
    function addCode(code) {
        var exists = false;
        $('input[name="j[]"]').each(function () { if ($(this).val() === code) exists = true; });
        if (exists) return;
        var evId = null;
        for (var k in EVENTS) { if (code.indexOf(k + '_') === 0) { evId = k; break; } }
        var $target = evId ? ensureSection(evId).find('.outras').first() : $('#others');
        if (!$target.length) { // no "others" block rendered yet — create one
            $target = $('<div id="others"></div>');
            $('#eventSections').before($('<div style="text-align:left; padding:8px;"><strong>Outras permissões</strong></div>').append($target));
        }
        $target.append($('<div></div>').append(cb(code, code, true)));
    }

    // ---- Fila helper ----
    function refreshFilaOptions() {
        var d = EVENTS[$('#fhEvent').val()] || { filas: [] };
        var $f = $('#fhFila').empty();
        $f.append($('<option></option>').val('*').text('* (todas as filas)'));
        d.filas.forEach(function (fl) {
            $f.append($('<option></option>').val(fl.id).text(fl.id + ' — ' + fl.nome));
        });
    }
    $('#fhEvent').on('change', refreshFilaOptions);
    refreshFilaOptions();

    $('#fhAdd').on('click', function () {
        var ev = $('#fhEvent').val();
        var fila = $('#fhFila').val();
        var isAdmin = $('input[name="fhType"]:checked').val() === 'ADMIN';
        var team = $.trim($('#fhTeam').val());
        if (!isAdmin && !/^\d+$/.test(team)) { alert('Informe o número da equipe.'); return; }
        var suffix = isAdmin ? 'ADMIN' : team;
        var filaIds = [];
        if (fila === '*') { (EVENTS[ev] || { filas: [] }).filas.forEach(function (fl) { filaIds.push(fl.id); }); }
        else { filaIds = [fila]; }
        if (!filaIds.length) { alert('Este evento não possui filas.'); return; }
        filaIds.forEach(function (fid) { addCode(ev + '_FILA_' + fid + '_' + suffix); });
    });

    // ---- Add event ----
    $('#addEventBtn').on('click', function () {
        var ev = $('#addEvent').val();
        if (!ev) return;
        var $s = ensureSection(ev);
        if ($s.length) $s[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
    });

    // ---- Limpar tudo ----
    $('#clearAll').on('click', function () {
        if (!confirm('Remover TODAS as permissões deste usuário? (o usuário será excluído ao salvar)')) return;
        $('input[name="j[]"]').prop('checked', false);
        $('.event-section .outras').empty();
        $('#others').empty();
    });
</script>

<?php
Template::printFooter();
