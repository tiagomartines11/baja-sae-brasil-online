<?php
namespace Baja\Juiz;

use Baja\Model\EventoQuery;
use Baja\Model\PremiacaoQuery;
use Baja\Model\Premiacao;
use Baja\Session;

if (!isset($_REQUEST['id']) && !isset($_REQUEST['nova'])) header("Location: admin_premiacoes.php");

$_page = @$_REQUEST['id'];

Session::permissionCheck('admin');

$currentEventId = EventoQuery::getCurrentEvent()->getEventoId();

$nova = false;

if (isset($_REQUEST['nova']) && $_REQUEST['nova'] == 'true') {
    $nova = true;

    if (@$_REQUEST['act'] == 'Salvar') {
        if (!isset($_POST['premiacao_id']) || !isset($_POST['nome']) || !isset($_POST['categorias']) || $_POST['premiacao_id'] == '' || $_POST['nome'] == '' || $_POST['categorias'] == '') {
            header("Location: premiacao_admin.php?nova=true");
        } else {
            $exists = PremiacaoQuery::create()->filterByEventoId($currentEventId)->findOneByPremiacaoId($_POST['premiacao_id']);
            if ($exists) header("Location: premiacao_admin.php?id=" . urlencode($_POST['premiacao_id']));

            $premiacao = new Premiacao();
            $premiacao->setPremiacaoId($_POST['premiacao_id']);
            $premiacao->setEventoId($currentEventId);
            $premiacao->setNome($_POST['nome']);
            $premiacao->setStatus(!empty($_POST['status']));
            $premiacao->setCategorias(json_decode($_POST['categorias']));
            $premiacao->save();

            header("Location: admin_premiacoes.php");
        }
    }

} else {

    $premiacao = PremiacaoQuery::create()->filterByEventoId($currentEventId)->findOneByPremiacaoId($_page);
    if (!$premiacao) header("Location: admin_premiacoes.php");

    $backups = json_decode($premiacao->getCategoriasBackup(), true);
    if (!$backups) $backups = array();

    $bkNomeados = array();
    if (count($backups) > 0) {
        foreach ($backups as $key => $bk) {
            if (!in_array($key, array('-1', '-2', '-3', '-4', '-5'))) {
                $bkNomeados[$key] = $bk;
            }
        }
    }

    if (@$_REQUEST['act'] == '❌') {
        if (isset($_POST['nomeado']) && $_POST['nomeado'] != '' && isset($bkNomeados[$_POST['nomeado']])) {
            unset($backups[$_POST['nomeado']]);
            $premiacao->setCategoriasBackup(json_encode($backups));
            $premiacao->save();
        }
        header("Location: premiacao_admin.php?id=" . urlencode($_page));
    }

    if (@$_REQUEST['act'] == '💾') {
        if (isset($_POST['categorias']) && $_POST['categorias'] != '' && isset($_POST['novoNomeadoNome']) && $_POST['novoNomeadoNome'] != '') {
            $backups[$_POST['novoNomeadoNome']] = json_decode($_POST['categorias']);
            $premiacao->setCategoriasBackup(json_encode($backups));
            $premiacao->save();
        }
        header("Location: premiacao_admin.php?id=" . urlencode($_page));
    }

    $bk1 = isset($backups['-1']) ? $backups['-1'] : null;
    $bk2 = isset($backups['-2']) ? $backups['-2'] : null;
    $bk3 = isset($backups['-3']) ? $backups['-3'] : null;
    $bk4 = isset($backups['-4']) ? $backups['-4'] : null;
    $bk5 = isset($backups['-5']) ? $backups['-5'] : null;

    if (@$_REQUEST['act'] == 'Atualizar') {
        $current = $premiacao->getCategorias();
        $incoming = json_decode($_POST['categorias']);
        if (json_encode($current) !== json_encode($incoming)) {
            $backups['-5'] = $bk4;
            $backups['-4'] = $bk3;
            $backups['-3'] = $bk2;
            $backups['-2'] = $bk1;
            $backups['-1'] = $current;
            $premiacao->setCategoriasBackup(json_encode($backups));
        }
        $premiacao->setNome($_POST['nome']);
        $premiacao->setStatus(!empty($_POST['status']));
        $premiacao->setCategorias($incoming);
        $premiacao->save();

        header("Location: premiacao_admin.php?id=" . urlencode($_page));
    }

    if (@$_REQUEST['act'] == 'Deletar Premiação') {
        $premiacao->delete();
        header("Location: admin_premiacoes.php");
    }
}

Template::printHeader("Premiação", false);

?>

<div style="max-width: 1000px; margin: 0 auto; height:100vh;">
<?php if ($nova) { ?>
    <form action="premiacao_admin.php?nova=true" method="POST">
<?php } else { ?>
    <form action="premiacao_admin.php?id=<?= urlencode($premiacao->getPremiacaoId()) ?>" method="POST">
<?php } ?>
        <table id="myTable" class="tablesorter" style="margin-bottom: 0;">
            <thead>
                <tr style="height: 50px">
                    <th colspan="2" style="vertical-align: middle;" class="sorter-false">
                        <span style="float:left"><a href="admin_premiacoes.php" style="color: white; font-size: 12px;">&nbsp;Voltar</a></span>
                        <span style="font-size: 28px;"><?= $nova ? 'Nova Premiação' : htmlspecialchars($premiacao->getNome()) ?></span>
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Evento</td>
                    <td><input style="width:700px;" type="text" name="evento_id" disabled value="<?= htmlspecialchars($currentEventId) ?>"/></td>
                </tr>
                <tr>
                    <td>ID Premiação</td>
                    <td><input style="width:700px;" type="text" name="premiacao_id" <?= $nova ? '' : 'disabled' ?> value="<?= $nova ? '' : htmlspecialchars($premiacao->getPremiacaoId()) ?>" /></td>
                </tr>
                <tr>
                    <td>Nome</td>
                    <td><input style="width:700px;" type="text" name="nome" value="<?= $nova ? '' : htmlspecialchars($premiacao->getNome()) ?>" /></td>
                </tr>
                <tr>
                    <td>Status</td>
                    <td><input type="checkbox" name="status" value="1" <?= (!$nova && $premiacao->getStatus()) ? 'checked' : '' ?>> Ativa</td>
                </tr>
                <tr>
                    <td rowspan="2">Categorias</td>
                    <td>
                        <?php if (!$nova) { ?>
                        <button type="button" class="bkBotao bkSelected" id="bkAtual" onclick="restoreAtual()">Código Atual</button><br/><br/>

                        <strong>Backups Últimas Alterações:</strong>
                        <button type="button" class="bkBotao" id="bk-1" onclick="loadNumbered('-1')" <?= (!$bk1) ? 'disabled' : '' ?>>-1</button>
                        <button type="button" class="bkBotao" id="bk-2" onclick="loadNumbered('-2')" <?= (!$bk2) ? 'disabled' : '' ?>>-2</button>
                        <button type="button" class="bkBotao" id="bk-3" onclick="loadNumbered('-3')" <?= (!$bk3) ? 'disabled' : '' ?>>-3</button>
                        <button type="button" class="bkBotao" id="bk-4" onclick="loadNumbered('-4')" <?= (!$bk4) ? 'disabled' : '' ?>>-4</button>
                        <button type="button" class="bkBotao" id="bk-5" onclick="loadNumbered('-5')" <?= (!$bk5) ? 'disabled' : '' ?>>-5</button><br/><br/>

                        <strong>Backups Nomeados:</strong>
                        <select name="nomeado" id="nomeado" onchange="loadNamed()">
                            <option disabled selected value> -- selecione -- </option>
                            <?php foreach ($bkNomeados as $k => $bk) { ?>
                                <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($k) ?></option>
                            <?php } ?>
                        </select>
                        <input type="submit" name="act" value="❌"/>
                        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
                        <strong>Salvar Backup com Nome:</strong>
                        <input type="text" name="novoNomeadoNome" id="novoNomeadoNome" oninput="checkNovoNomeado()"/>
                        <input type="submit" name="act" value="💾" id="salvaNovoNomeado" disabled/>
                        <?php } ?>
                    </td>
                </tr>
                <tr>
                    <td><textarea style="width:700px;min-height:600px;" name="categorias" id="categorias" <?= $nova ? '' : 'oninput="resetStyles();"' ?>><?= $nova ? '' : json_encode($premiacao->getCategorias(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></textarea></td>
                </tr>
            </tbody>
            <tfoot>
                <?php if (!$nova) { ?>
                <tr>
                    <th style="height: 30px;" colspan="2"><input type="submit" name="act" value="Atualizar" /></th>
                </tr>
                <tr>
                    <th style="height: 100px;" colspan="2">
                        <input id="deleteBtn" type="button" value="Deletar Premiação" onclick="confirmDelete();"/>
                        <div id="deleteConfirmBtn" style="display:none">
                            <strong>Tem certeza que deseja apagar esta premiação? Essa operação não pode ser desfeita.</strong><br/><br/>
                            <input type="submit" name="act" value="Deletar Premiação" style="background:red;color:white;"/>&nbsp;&nbsp;&nbsp;&nbsp;<input type="button" value="Cancelar" onclick="cancelDelete();"/>
                        </div>
                    </th>
                </tr>
                <?php } else { ?>
                <tr>
                    <th style="height: 30px;" colspan="2"><input type="submit" name="act" value="Salvar" /></th>
                </tr>
                <?php } ?>
            </tfoot>
        </table>
    </form>

    <script type="text/javascript">
        const atual = <?= $nova ? 'null' : json_encode($premiacao->getCategorias()) ?>;
        const backs = <?= (!$nova && $premiacao->getCategoriasBackup()) ? $premiacao->getCategoriasBackup() : '{}' ?>;

        const categoriasEl = document.getElementById("categorias");
        const selectEl = document.getElementById("nomeado");

        const novoNomeadoNome = document.getElementById("novoNomeadoNome");
        const novoNomeadoBtn = document.getElementById("salvaNovoNomeado");

        function resetStyles() {
            let els = document.querySelectorAll(".bkBotao");
            els.forEach((e) => { e.classList.remove('bkSelected'); });
            if (selectEl) selectEl.classList.remove('bkSelected');
        }

        function loadNumbered(i) {
            if (backs[i]) {
                resetStyles();
                categoriasEl.value = JSON.stringify(backs[i], null, 2);
                document.getElementById('bk' + i).classList.add('bkSelected');
            }
        }

        function loadNamed() {
            try {
                if (backs[selectEl.value]) {
                    resetStyles();
                    categoriasEl.value = JSON.stringify(backs[selectEl.value], null, 2);
                    selectEl.classList.add('bkSelected');
                }
            } catch (e) {}
        }

        function restoreAtual() {
            resetStyles();
            categoriasEl.value = JSON.stringify(atual, null, 2);
            document.getElementById("bkAtual").classList.add('bkSelected');
        }

        function checkNovoNomeado() {
            if (novoNomeadoNome.value != '' && !backs[novoNomeadoNome.value]) {
                novoNomeadoBtn.disabled = false;
            } else {
                novoNomeadoBtn.disabled = true;
            }
        }

        function confirmDelete() {
            document.getElementById("deleteBtn").style.display = 'none';
            document.getElementById("deleteConfirmBtn").style.display = '';
        }

        function cancelDelete() {
            document.getElementById("deleteBtn").style.display = '';
            document.getElementById("deleteConfirmBtn").style.display = 'none';
        }
    </script>

</div>

<?php
Template::printFooter();
