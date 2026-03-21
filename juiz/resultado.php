<?php
namespace Baja\Juiz;

use Baja\Model\EventoQuery;
use Baja\Model\LogQuery;
use Baja\Model\ResultadoQuery;
use Baja\Model\Resultado;
use Baja\Site\OneSignalClient;
use Baja\Session;
use DateTimeZone;

if (!isset($_REQUEST['id']) && !isset($_REQUEST['novo'])) header("Location: admin_resultados.php");

$_page = $_REQUEST['id'];

Session::permissionCheck('admin');

$currentEventId = EventoQuery::getCurrentEvent()->getEventoId();

$novo = false;

if (isset($_REQUEST['novo']) && $_REQUEST['novo']=='true') {
    $novo = true;

    if (@$_REQUEST['act'] == 'Salvar') {
        if (!isset($_POST['resultado_id']) || !isset($_POST['nome']) || !isset($_POST['inputs']) || !isset($_POST['colunas']) || $_POST['resultado_id'] == '' || $_POST['nome'] == '' || $_POST['inputs'] == '' || $_POST['colunas'] == '') {
            header("Location: resultado.php?novo=true");
        } else {
            $resultado = ResultadoQuery::create()->filterByEventoId($currentEventId)->findOneByResultadoId($_POST['resultado_id']);
            if ($resultado) header("Location: resultado.php?id=".$_POST['resultado_id']);

            $resultado = new Resultado();
            $resultado->setEventoId($currentEventId);
            $resultado->setResultadoId($_POST['resultado_id']);
            $resultado->setNome($_POST['nome']);
            $resultado->setInputs(explode(",",str_replace(" ","",$_POST['inputs'])));
            $resultado->setColunas(json_decode($_POST['colunas']));
            $resultado->save();

            header("Location: admin_resultados.php");
        }
    }

} else {

    $resultado = ResultadoQuery::create()->filterByEventoId($currentEventId)->findOneByResultadoId($_page);
    if (!$resultado) header("Location: admin_resultados.php");

    $backups = json_decode($resultado->getColunasBackup(), true);

    $bkNomeados = [];

    if ($backups && count($backups) > 0) {
        foreach($backups as $key => $bk) {
            if (!in_array($key, ['-1','-2','-3','-4','-5'] )) {
                $bkNomeados[$key] = $bk;
            }
        }
    }

    if (@$_REQUEST['act'] == '❌') {
        if (isset($_POST['nomeado']) && $_POST['nomeado'] != '' && isset($bkNomeados[$_POST['nomeado']])) {
            unset($backups[$_POST['nomeado']]);
            $resultado->setColunasBackup(json_encode($backups));
            $resultado->save();
        }
        header("Location: resultado.php?id=".$_page);
    }

    if (@$_REQUEST['act'] == '💾') {
        if (isset($_POST['colunas']) && $_POST['colunas'] != '' && isset($_POST['novoNomeadoNome']) && $_POST['novoNomeadoNome'] != '') {
            $backups[$_POST['novoNomeadoNome']] = json_decode($_POST['colunas']);
            $resultado->setColunasBackup(json_encode($backups));
            $resultado->save();
        }
        header("Location: resultado.php?id=".$_page);
    }

    $bk1 = $backups['-1'];
    $bk2 = $backups['-2'];
    $bk3 = $backups['-3'];
    $bk4 = $backups['-4'];
    $bk5 = $backups['-5'];

    if (@$_REQUEST['act'] == 'Atualizar') {
        if ($resultado->getColunas() != json_decode($_POST['colunas'])) {
            $backups['-5'] = $bk4;        
            $backups['-4'] = $bk3;
            $backups['-3'] = $bk2;
            $backups['-2'] = $bk1;
            $backups['-1'] = $resultado->getColunas();
            $resultado->setColunasBackup(json_encode($backups));
        }
        $resultado->setNome($_POST['nome']);
        $resultado->setInputs(explode(",",str_replace(" ","",$_POST['inputs'])));
        $resultado->setColunas(json_decode($_POST['colunas']));
        $resultado->save();

        header("Location: resultado.php?id=".$_page);
    }

    if (@$_REQUEST['act'] == 'Deletar Resultado') {
        $resultado->delete();
        header("Location: admin_resultados.php");
    }
}

Template::printHeader("Detalhes de Resultado", false);



?>
<div style="max-width: 1000px; margin: 0 auto; height:100vh;">
<?php if ($novo) { ?>
    <form action="resultado.php?novo=true" method="POST">
<?php } else { ?>
    <form action="resultado.php?id=<?= $resultado->getResultadoId() ?>" method="POST">
<?php } ?>    
        <table id="myTable" class="tablesorter" style="margin-bottom: 0;">
            <thead>
                <tr style="height: 50px">
                    <th colspan="2" style="vertical-align: middle;" class="sorter-false">
                        <span style="float:left"><a href="admin_resultados.php" style="color: white; font-size: 12px;">&nbsp;Voltar</a></span>
                        <span style="font-size: 28px;"><?= $novo?'Novo Resultado':$resultado->getNome(); ?></span> <br />
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Evento</td>
                    <td><input style="width:700px;" type="text" name="evento_id" disabled value="<?= $currentEventId ?>"/></td>
                </tr>
                <tr>
                    <td>ID Resultado</td>
                    <td><input style="width:700px;" type="text" name="resultado_id" <?= $novo?'':'disabled' ?> value="<?= $novo?'':$resultado->getResultadoId() ?>" /></td>
                </tr>
                <tr>
                    <td>Título Resultado</td>
                    <td><input style="width:700px;" type="text" name="nome" value="<?= $novo?'':$resultado->getNome() ?>" /></td>
                </tr>
                <tr>
                    <td>Inputs</td>
                    <td><input style="width:700px;" type="text" name="inputs" value="<?= $novo?'':implode(", ",$resultado->getInputs()) ?>" /></td>
                </tr>
                <tr>
                    <td rowspan="2">Colunas</td>
                    <td>
                        <?php if (!$novo) { ?>
                        <button type="button" class="bkBotao bkSelected" id="bkAtual" onclick="restoreAtual()">Código Atual</button><br/><br/>
                        
                        <strong>Backups Últimas Alterações:</strong>
                        <button type="button" class="bkBotao" id="bk-1" onclick="loadNumbered('-1')" <?= (!$bk1)?'disabled':'' ?>>-1</button>
                        <button type="button" class="bkBotao" id="bk-2" onclick="loadNumbered('-2')" <?= (!$bk2)?'disabled':'' ?>>-2</button>
                        <button type="button" class="bkBotao" id="bk-3" onclick="loadNumbered('-3')" <?= (!$bk3)?'disabled':'' ?>>-3</button>
                        <button type="button" class="bkBotao" id="bk-4" onclick="loadNumbered('-4')" <?= (!$bk4)?'disabled':'' ?>>-4</button>
                        <button type="button" class="bkBotao" id="bk-5" onclick="loadNumbered('-5')" <?= (!$bk5)?'disabled':'' ?>>-5</button><br/><br/>
                        
                        <strong>Backups Nomeados:</strong>
                        <select name="nomeado" id="nomeado" onchange="loadNamed()">
                            <option disabled selected value> -- selecione -- </option>
                            <?php
                                foreach($bkNomeados as $k=>$bk) {
                            ?>
                                <option value="<?= $k ?>"><?= $k ?></option>
                            <?php
                                }
                            ?>
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
                <td><textarea style="width:700px;min-height:600px;" name="colunas" id="colunas" <?= $nova?'':'oninput="resetStyles();"' ?>><?= $novo?'':json_encode($resultado->getColunas(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></textarea></td>
                </tr>
            </tbody>
            <tfoot>
                <?php if (!$novo) { ?>
                <tr>
                    <th style="height: 30px;" colspan="2"><input type="submit" name="act" value="Atualizar" />
                </tr>
                <tr >
                    <th style="height: 100px;" colspan="2">
                        <input id="deleteBtn" type="button" value="Deletar Resultado" onclick="confirmDelete();"/>
                        <div id="deleteConfirmBtn" style="display:none">
                            <strong>Tem certeza que deseja apagar o resultado? Essa operação não pode ser desfeita.</strong><br/><br/>
                            <input type="submit" name="act" value="Deletar Resultado" style="background:red;color:white;"/>&nbsp;&nbsp;&nbsp;&nbsp;<input type="button" value="Cancelar" onclick="cancelDelete();"/>
                        </div>
                    </th>
                </tr>
                <?php } else { ?>
                <tr>
                    <th style="height: 30px;" colspan="2"><input type="submit" name="act" value="Salvar" />
                </tr>
                <?php } ?>
            </tfoot>
        </table>
    </form>

    <script type="text/javascript">
        const atual = <?= $novo?'[]':json_encode($resultado->getColunas()) ?>;
        const backs = <?= (!$novo && $resultado->getColunasBackup())?$resultado->getColunasBackup():'{}' ?>;

        const colunasEl = document.getElementById("colunas");
        const selectEl = document.getElementById("nomeado");

        const novoNomeadoNome = document.getElementById("novoNomeadoNome");
        const novoNomeadoBtn = document.getElementById("salvaNovoNomeado");

        function resetStyles() {
            let els = document.querySelectorAll(".bkBotao")
            
            els.forEach((e) => {
                e.classList.remove('bkSelected');
            })
            
            selectEl.classList.remove('bkSelected');

        }
        
        function loadNumbered(i) {
            if (backs[i]) {
                resetStyles()
                colunasEl.value = JSON.stringify(backs[i], null, 2);           
                document.getElementById('bk'+i).classList.add('bkSelected');                
            }
        }

        function loadNamed() {
            try {
                if (backs[selectEl.value]) {
                    resetStyles()
                    colunasEl.value = JSON.stringify(backs[selectEl.value], null, 2);
                    selectEl.classList.add('bkSelected');
                }                
            } catch (e) {

            }            
        }

        function restoreAtual() {
            resetStyles()
            colunasEl.value = JSON.stringify(atual, null, 2);
            document.getElementById("bkAtual").classList.add('bkSelected');
        }

        function checkNovoNomeado() {
            if (novoNomeadoNome.value != '' && !backs[novoNomeadoNome.value]) {
                novoNomeadoBtn.disabled = false;
            } else {
                novoNomeadoBtn.disabled = true;
            }
        }

        function confirmDelete(){
            document.getElementById("deleteBtn").style.display='none';
            document.getElementById("deleteConfirmBtn").style.display='';
        }

        function cancelDelete(){
            document.getElementById("deleteBtn").style.display='';
            document.getElementById("deleteConfirmBtn").style.display='none';
        }
    </script>
    
</div>


<?php
Template::printFooter();