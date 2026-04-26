<?php
namespace Baja\Juiz;

use Baja\Model\EventoQuery;
use Baja\Model\LogQuery;
use Baja\Model\ProvaQuery;
use Baja\Model\Prova;
use Baja\Site\OneSignalClient;
use Baja\Session;
use DateTimeZone;

if (!isset($_REQUEST['id']) && !isset($_REQUEST['nova'])) header("Location: admin_provas.php");

$_page = $_REQUEST['id'];

Session::permissionCheck('admin');

$currentEventId = EventoQuery::getCurrentEvent()->getEventoId();

$nova = false;

if (isset($_REQUEST['nova']) && $_REQUEST['nova']=='true') {
    $nova = true;

    if (@$_REQUEST['act'] == 'Salvar') {
        if (!isset($_POST['prova_id']) || !isset($_POST['nome']) || !isset($_POST['params']) || $_POST['prova_id'] == '' || $_POST['nome'] == '' || $_POST['params'] == '') {
            header("Location: prova.php?nova=true");
        } else {
            $prova = ProvaQuery::create()->filterByEventoId($currentEventId)->findOneByProvaId($_POST['prova_id']);
            if ($prova) header("Location: prova.php?id=".$_POST['prova_id']);

            $prova = new Prova();
            $prova->setEventoId($currentEventId);
            $prova->setProvaId($_POST['prova_id']);
            $prova->setNome($_POST['nome']);
            $prova->setParams(json_decode($_POST['params']));
            $prova->save();

            header("Location: admin_provas.php");
        }
    }

} else {

    $prova = ProvaQuery::create()->filterByEventoId($currentEventId)->findOneByProvaId($_page);
    if (!$prova) header("Location: admin_provas.php");

    $backups = json_decode($prova->getParamsBackup(), true);

    if (@$_REQUEST['act'] == 'Refresh Pontos') {
        $prova->refreshVarsAndPontos();
        header("Location: prova.php?id=".$_page);
    }

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
            $prova->setParamsBackup(json_encode($backups));
            $prova->save();
        }
        header("Location: prova.php?id=".$_page);
    }

    if (@$_REQUEST['act'] == '💾') {
        if (isset($_POST['params']) && $_POST['params'] != '' && isset($_POST['novoNomeadoNome']) && $_POST['novoNomeadoNome'] != '') {
            $backups[$_POST['novoNomeadoNome']] = json_decode($_POST['params']);
            $prova->setParamsBackup(json_encode($backups));
            $prova->save();
        }
        header("Location: prova.php?id=".$_page);
    }

    $bk1 = $backups['-1'];
    $bk2 = $backups['-2'];
    $bk3 = $backups['-3'];
    $bk4 = $backups['-4'];
    $bk5 = $backups['-5'];

    if (@$_REQUEST['act'] == 'Atualizar') {
        if ($prova->getParams() != json_decode($_POST['params'])) {
            $backups['-5'] = $bk4;        
            $backups['-4'] = $bk3;
            $backups['-3'] = $bk2;
            $backups['-2'] = $bk1;
            $backups['-1'] = $prova->getParams();
            $prova->setParamsBackup(json_encode($backups));
        }
        $prova->setNome($_POST['nome']);
        $prova->setParams(json_decode($_POST['params']));
        $prova->save();

        if (isset($_POST['refresh']) && $_POST['refresh']=='1') {
            $prova->refreshVarsAndPontos();
        }

        header("Location: prova.php?id=".$_page);
    }

    if (@$_REQUEST['act'] == 'Deletar Prova') {
        $prova->delete();
        header("Location: admin_provas.php");
    }
}

Template::printHeader("Detalhes de Prova", false);




?>

<div style="max-width: 1000px; margin: 0 auto; height:100vh;">
<?php if ($nova) { ?>
    <form action="prova.php?nova=true" method="POST">
<?php } else { ?>
    <form action="prova.php?id=<?= $prova->getProvaId() ?>" method="POST">
<?php } ?>
        <table id="myTable" class="tablesorter" style="margin-bottom: 0;">
            <thead>
                <tr style="height: 50px">
                    <th colspan="2" style="vertical-align: middle;" class="sorter-false">
                        <span style="float:left"><a href="admin_provas.php" style="color: white; font-size: 12px;">&nbsp;Voltar</a></span>
                        <span style="font-size: 28px;"><?= $nova?'Nova Prova':$prova->getNome() ?></span> <br />
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Evento</td>
                    <td><input style="width:700px;" type="text" name="evento_id" disabled value="<?= $currentEventId ?>"/></td>
                </tr>
                <tr>
                    <td>ID Prova</td>
                    <td><input style="width:700px;" type="text" name="prova_id" <?= $nova?'':'disabled' ?> value="<?= $nova?'':$prova->getProvaId() ?>" /></td>
                </tr>
                <tr>
                    <td>Nome Prova</td>
                    <td><input style="width:700px;" type="text" name="nome" value="<?= $nova?'':$prova->getNome() ?>" /></td>
                </tr>
                <tr>
                    <td rowspan="2">Parâmetros</td>
                    <td>
                        <?php if (!$nova) { ?>
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
                    <td><textarea style="width:700px;min-height:600px;" name="params" id="params" <?= $nova?'':'oninput="resetStyles();"' ?>><?= $nova?'':json_encode($prova->getParams(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></textarea></td>
                </tr>                
                <?php if (!$nova) { ?>
                <tr>
                    <td>Totais</td>
                    <td><textarea style="width:700px;min-height:200px;" disabled><?= json_encode($prova->getTotals(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></textarea></td>
                </tr>
                <?php } ?>
            </tbody>
            <tfoot>
                <?php if (!$nova) { ?>
                <tr>
                    <td colspan="2"><input type="checkbox" checked name="refresh" value="1">Refresh Pontos ao salvar</input></td>
                </tr>                
                <tr>
                    <th style="height: 30px;" colspan="2"><input type="submit" name="act" value="Atualizar" />&nbsp;&nbsp;<input type="submit" name="act" value="Refresh Pontos" /></th>
                </tr>
                <tr >
                    <th style="height: 100px;" colspan="2">
                        <input id="deleteBtn" type="button" value="Deletar Prova" onclick="confirmDelete();"/>
                        <div id="deleteConfirmBtn" style="display:none">
                            <strong>Tem certeza que deseja apagar a prova? Essa operação não pode ser desfeita.</strong><br/><br/>
                            <input type="submit" name="act" value="Deletar Prova" style="background:red;color:white;"/>&nbsp;&nbsp;&nbsp;&nbsp;<input type="button" value="Cancelar" onclick="cancelDelete();"/>
                            </div>
                    </th>
                </tr>
                <?php } else { ?>
                <tr>
                    <th style="height: 30px;" colspan="2"><input type="submit" name="act" value="Salvar" />
                </tr>
                <?php } 

                    if (!$nova && isset($prova->getParams()->type) && $prova->getParams()->type == "tournament") {
                    
                ?>
                
                <tr>
                    <th style="height: 30px;" colspan="2"><a style="color:white;" href="torneio.php?id=<?= $prova->getProvaId() ?>" target="_blank">Administrar Chaveamento</a></th>
                </tr>
                <?php } ?>
            </tfoot>
        </table>
    </form>

    

    <script type="text/javascript">
        const atual = <?= $nova?'[]':json_encode($prova->getParams()) ?>;
        const backs = <?= (!$nova && $prova->getParamsBackup())?$prova->getParamsBackup():'{}' ?>;

        const paramsEl = document.getElementById("params");
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
                paramsEl.value = JSON.stringify(backs[i], null, 2);           
                document.getElementById('bk'+i).classList.add('bkSelected');                
            }
        }

        function loadNamed() {
            try {
                if (backs[selectEl.value]) {
                    resetStyles()
                    paramsEl.value = JSON.stringify(backs[selectEl.value], null, 2);
                    selectEl.classList.add('bkSelected');
                }                
            } catch (e) {

            }            
        }

        function restoreAtual() {
            resetStyles()
            paramsEl.value = JSON.stringify(atual, null, 2);
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