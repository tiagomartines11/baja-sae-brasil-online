<?php
namespace Baja\Juiz;

use Baja\Model\EventoQuery;
use Baja\Model\EquipeQuery;
use Baja\Model\Equipe;
use Baja\Session;
use DateTimeZone;

if (!isset($_REQUEST['id']) && !isset($_REQUEST['nova'])) header("Location: admin_equipes.php");

$_page = $_REQUEST['id'];

Session::permissionCheck('admin');

$currentEventId = EventoQuery::getCurrentEvent()->getEventoId();

$novo = false;

if (isset($_REQUEST['nova']) && $_REQUEST['nova']=='true') {
    $novo = true;

    if (@$_REQUEST['act'] == 'Salvar') {
        if (!isset($_POST['equipe_id']) || $_POST['equipe_id'] == '') {
            header("Location: equipe.php?nova=true");
        } else {
            $equipe = EquipeQuery::create()->filterByEventoId($currentEventId)->findOneByEquipeId($_POST['equipe_id']);
            if ($equipe) header("Location: equipe.php?id=".$_POST['evento_id']);

            $equipe = new Equipe();
            
            $equipe->setEventoId($currentEventId);
            $equipe->setEquipeId($_POST['equipe_id']);
            $equipe->setEquipe($_POST['equipe']);
            $equipe->setEquipeCurto($_POST['equipe_curto']);
            $equipe->setEscola($_POST['escola']);
            $equipe->setEscolaCurto($_POST['escola_curto']);
            $equipe->setCidade($_POST['cidade']);
            $equipe->setEstado($_POST['estado']);
            
            if (isset($_POST['presente']) && $_POST['presente']=='1') {
                $equipe->setPresente(true);
            } else {
                $equipe->setPresente(false);
            }

            if (isset($_POST['desclassificado']) && $_POST['desclassificado']=='1') {
                $equipe->setDesclassificado(true);
            } else {
                $equipe->setDesclassificado(false);
            }

            $equipe->save();

            header("Location: admin_equipes.php");
        }
    }

} else {

    $equipe = EquipeQuery::create()->filterByEventoId($currentEventId)->findOneByEquipeId($_REQUEST['id']);
    if (!$equipe) header("Location: admin_equipes.php");

    if (@$_REQUEST['act'] == 'Atualizar') {
        
        $equipe->setEquipe($_POST['equipe']);
        $equipe->setEquipeCurto($_POST['equipe_curto']);
        $equipe->setEscola($_POST['escola']);
        $equipe->setEscolaCurto($_POST['escola_curto']);
        $equipe->setCidade($_POST['cidade']);
        $equipe->setEstado($_POST['estado']);
        
        if (isset($_POST['presente']) && $_POST['presente']=='1') {
            $equipe->setPresente(true);
        } else {
            $equipe->setPresente(false);
        }

        if (isset($_POST['desclassificado']) && $_POST['desclassificado']=='1') {
            $equipe->setDesclassificado(true);
        } else {
            $equipe->setDesclassificado(false);
        }

        $equipe->save();

        header("Location: equipe.php?id=".$_page);
    }

    if (@$_REQUEST['act'] == 'Deletar Equipe') {
        $equipe->delete();
        header("Location: admin_equipes.php");
    }
}

Template::printHeader("Detalhes do Evento", false);



?>
<div style="max-width: 1000px; margin: 0 auto; height:100vh;">
<?php if ($novo) { ?>
    <form action="equipe.php?nova=true" method="POST">
<?php } else { ?>
    <form action="equipe.php?id=<?= $equipe->getEquipeId() ?>" method="POST">
<?php } ?>    
        <table id="myTable" class="tablesorter" style="margin-bottom: 0;">
            <thead>
                <tr style="height: 50px">
                    <th colspan="2" style="vertical-align: middle;" class="sorter-false">
                        <span style="float:left"><a href="admin_equipes.php" style="color: white; font-size: 12px;">&nbsp;Voltar</a></span>
                        <span style="font-size: 28px;"><?= $novo?'Nova equipe':('Equipe '.$equipe->getEquipeId()); ?> (<?= $currentEventId ?>)</span> <br />
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Carro</td>
                    <td><input style="width:700px;" type="number" min="1" max="999" name="equipe_id" <?= $novo?'':'disabled' ?> value="<?= $novo?'':$equipe->getEquipeId() ?>"/></td>
                </tr>
                <tr>
                    <td>Equipe</td>
                    <td><input style="width:700px;" type="text" name="equipe" value="<?= $novo?'':$equipe->getEquipe() ?>" /></td>
                </tr>
                <tr>
                    <td>Equipe Curto<br/><small>(Para overlays de transmissão ao vivo)</small></td>
                    <td><input style="width:700px;" type="text" name="equipe_curto" value="<?= $novo?'':$equipe->getEquipeCurto() ?>" /></td>
                </tr>
                <tr>
                    <td>Escola</td>
                    <td><input style="width:700px;" type="text" name="escola" value="<?= $novo?'':$equipe->getEscola() ?>" /></td>
                </tr>
                <tr>
                    <td>Escola Curto<br/><small>(Para overlays de transmissão ao vivo)</small></td>
                    <td><input style="width:700px;" type="text" name="escola_curto" value="<?= $novo?'':$equipe->getEscolaCurto() ?>" /></td>
                </tr>
                <tr>
                    <td>Cidade</td>
                    <td><input style="width:700px;" type="text" name="cidade" value="<?= $novo?'':$equipe->getCidade() ?>" /></td>
                </tr>
                <tr>
                    <td>Estado</td>
                    <td>
                        <select name="estado">
                            <option disabled <?= ($novo || $equipe->getEstado()=='')?'selected':'' ?> value> -- Selecione -- </option>    
                            <option value="AC" <?= (!$novo && $equipe->getEstado()=='AC')?'selected':'' ?>>Acre</option>
                            <option value="AL" <?= (!$novo && $equipe->getEstado()=='AL')?'selected':'' ?>>Alagoas</option>
                            <option value="AP" <?= (!$novo && $equipe->getEstado()=='AP')?'selected':'' ?>>Amapá</option>
                            <option value="AM" <?= (!$novo && $equipe->getEstado()=='AM')?'selected':'' ?>>Amazonas</option>
                            <option value="BA" <?= (!$novo && $equipe->getEstado()=='BA')?'selected':'' ?>>Bahia</option>
                            <option value="CE" <?= (!$novo && $equipe->getEstado()=='CE')?'selected':'' ?>>Ceará</option>
                            <option value="DF" <?= (!$novo && $equipe->getEstado()=='DF')?'selected':'' ?>>Distrito Federal</option>
                            <option value="ES" <?= (!$novo && $equipe->getEstado()=='ES')?'selected':'' ?>>Espírito Santo</option>
                            <option value="GO" <?= (!$novo && $equipe->getEstado()=='GO')?'selected':'' ?>>Goiás</option>
                            <option value="MA" <?= (!$novo && $equipe->getEstado()=='MA')?'selected':'' ?>>Maranhão</option>
                            <option value="MT" <?= (!$novo && $equipe->getEstado()=='MT')?'selected':'' ?>>Mato Grosso</option>
                            <option value="MS" <?= (!$novo && $equipe->getEstado()=='MS')?'selected':'' ?>>Mato Grosso do Sul</option>
                            <option value="MG" <?= (!$novo && $equipe->getEstado()=='MG')?'selected':'' ?>>Minas Gerais</option>
                            <option value="PA" <?= (!$novo && $equipe->getEstado()=='PA')?'selected':'' ?>>Pará</option>
                            <option value="PB" <?= (!$novo && $equipe->getEstado()=='PB')?'selected':'' ?>>Paraíba</option>
                            <option value="PR" <?= (!$novo && $equipe->getEstado()=='PR')?'selected':'' ?>>Paraná</option>
                            <option value="PE" <?= (!$novo && $equipe->getEstado()=='PE')?'selected':'' ?>>Pernambuco</option>
                            <option value="PI" <?= (!$novo && $equipe->getEstado()=='PI')?'selected':'' ?>>Piauí</option>
                            <option value="RJ" <?= (!$novo && $equipe->getEstado()=='RJ')?'selected':'' ?>>Rio de Janeiro</option>
                            <option value="RN" <?= (!$novo && $equipe->getEstado()=='RN')?'selected':'' ?>>Rio Grande do Norte</option>
                            <option value="RS" <?= (!$novo && $equipe->getEstado()=='RS')?'selected':'' ?>>Rio Grande do Sul</option>
                            <option value="RO" <?= (!$novo && $equipe->getEstado()=='RO')?'selected':'' ?>>Rondônia</option>
                            <option value="RR" <?= (!$novo && $equipe->getEstado()=='RR')?'selected':'' ?>>Roraima</option>
                            <option value="SC" <?= (!$novo && $equipe->getEstado()=='SC')?'selected':'' ?>>Santa Catarina</option>
                            <option value="SP" <?= (!$novo && $equipe->getEstado()=='SP')?'selected':'' ?>>São Paulo</option>
                            <option value="SE" <?= (!$novo && $equipe->getEstado()=='SE')?'selected':'' ?>>Sergipe</option>
                            <option value="TO" <?= (!$novo && $equipe->getEstado()=='TO')?'selected':'' ?>>Tocantins</option>
                            <option value="EX" <?= (!$novo && $equipe->getEstado()=='EX')?'selected':'' ?>>Exterior</option>


                        </select>
                    </td>
                </tr>
                <tr>
                    <td>Presente?</td>
                    <td><input type="checkbox" name="presente" value="1" <?= $novo?'checked':($equipe->isPresente()?'checked':'') ?> /></td>
                </tr>
                <tr>
                    <td>Desclassificada?</td>
                    <td><input type="checkbox" name="desclassificado" value="1" <?= $novo?'disabled':($equipe->isDesclassificado()?'checked':'') ?> /></td>
                </tr>                              
            </tbody>
            <tfoot>
                <?php if (!$novo) { ?>
                <tr>
                    <th style="height: 30px;" colspan="2"><input type="submit" name="act" value="Atualizar" />
                </tr>
                <tr >
                    <th style="height: 100px;" colspan="2">
                        <input id="deleteBtn" type="button" value="Deletar Equipe" onclick="confirmDelete();"/>
                        <div id="deleteConfirmBtn" style="display:none">
                            <strong>Tem certeza que deseja apagar a equipe? Essa operação não pode ser desfeita e causará a deleção de todos os outros registros ligados a esta equipes, INCLUINDO RESULTADOS DE PROVA.</strong><br/><br/>
                            <input type="submit" name="act" value="Deletar Equipe" style="background:red;color:white;"/>&nbsp;&nbsp;&nbsp;&nbsp;<input type="button" value="Cancelar" onclick="cancelDelete();"/>
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