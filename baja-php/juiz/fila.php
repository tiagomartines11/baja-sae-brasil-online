<?php
namespace Baja\Juiz;

use Baja\Model\EventoQuery;
use Baja\Model\FilaQuery;
use Baja\Model\Fila;
use Baja\Session;
use DateTimeZone;
use Baja\Fila\Fila as FilaAdmin;

if (!isset($_REQUEST['id']) && !isset($_REQUEST['nova'])) header("Location: admin_filas.php");

$_fila_id = $_REQUEST['id'];

Session::permissionCheck('admin');

$currentEventId = EventoQuery::getCurrentEvent()->getEventoId();

$novo = false;

if (isset($_REQUEST['nova']) && $_REQUEST['nova']=='true') {
    $novo = true;

    if (@$_REQUEST['act'] == 'Salvar') {
        if (!isset($_POST['evento_id']) || $_POST['evento_id'] == '' || !isset($_POST['fila_id']) || $_POST['fila_id'] == '') {
            header("Location: fila.php?nova=true");
        } else {
            $fila = FilaQuery::create()->filterByEventoId($_POST['evento_id'])->findOneByFilaId($_POST['fila_id']);
            if ($fila) header("Location: fila.php?evento=".$_POST['evento_id']."&id=".$_POST['fila_id']);

            $fila = new Fila();
            
            $fila->setEventoId($_POST['evento_id']);
            $fila->setFilaId($_POST['fila_id']);
            $fila->setNome($_POST['nome']);
            $fila->setTempoEspera($_POST['tempo_espera']);

            if (isset($_POST['abertura_programada'])) {
                $fila->setAberturaProgramada(\DateTime::createFromFormat('Y-m-d\TH:i', $_POST['abertura_programada']));
            } else {
                $fila->setAberturaProgramada(null);
            }
            if (isset($_POST['fechamento_programado'])) {
                $fila->setFechamentoProgramado(\DateTime::createFromFormat('Y-m-d\TH:i', $_POST['fechamento_programado']));
            } else {
                $fila->setFechamentoProgramado(null);
            }
            
            if (isset($_POST['permite_troca']) && $_POST['permite_troca']=='1') {
                $fila->setPermiteTroca(true);
            } else {
                $fila->setPermiteTroca(false);
            }

            if (isset($_POST['permite_multiplas']) && $_POST['permite_multiplas']=='1') {
                $fila->setPermiteMultiplas(true);
            } else {
                $fila->setPermiteMultiplas(false);
            }

            if (isset($_POST['permite_chamada_espera']) && $_POST['permite_chamada_espera']=='1') {
                $fila->setPermiteChamadaEspera(true);
            } else {
                $fila->setPermiteChamadaEspera(false);
            }

            if (isset($_POST['novo_status']) && $_POST['novo_status']!='') {
                switch ($_POST['novo_status']) {
                    case 'aberta':
                        $fila->setStatus(1);
                        break;
                    case 'fechada':
                        $fila->setStatus(0);
                        break;
                    case 'fechaauto':
                        if (isset($_POST['fechamento_programado'])) {
                            if (\DateTime::createFromFormat('Y-m-d\TH:i', $_POST['fechamento_programado'])>=$now) {
                                $fila->setStatus(3);
                            } else {
                                $fila->setStatus(0);    
                            }
                        } else {
                            $fila->setStatus(0);
                        }
                        break;
                    case 'abreauto':
                        if (isset($_POST['abertura_programada'])) {
                            if (\DateTime::createFromFormat('Y-m-d\TH:i', $_POST['abertura_programada'])>=$now) {
                                $fila->setStatus(2);
                            } else {
                                $fila->setStatus(0);    
                            }
                        } else {
                            $fila->setStatus(0);
                        }
                        break;
                    default:
                        $fila->setStatus(0);
                        
                }
            }

            $fila->save();

            header("Location: admin_filas.php");
        }
    }

} else {

    $fila = FilaQuery::create()->filterByEventoId($currentEventId)->findOneByFilaId($_REQUEST['id']);
    if (!$fila) header("Location: admin_filas.php");

    if (@$_REQUEST['act'] == 'Atualizar') {
        $now = new \DateTime();

        $fila->setNome($_POST['nome']);
        $fila->setTempoEspera($_POST['tempo_espera']);
        if (isset($_POST['abertura_programada'])) {
            $fila->setAberturaProgramada(\DateTime::createFromFormat('Y-m-d\TH:i', $_POST['abertura_programada']));
        } else {
            $fila->setAberturaProgramada(null);
        }
        if (isset($_POST['fechamento_programado'])) {
            $fila->setFechamentoProgramado(\DateTime::createFromFormat('Y-m-d\TH:i', $_POST['fechamento_programado']));
        } else {
            $fila->setFechamentoProgramado(null);
        }
        

        if (isset($_POST['permite_troca']) && $_POST['permite_troca']=='1') {
            $fila->setPermiteTroca(true);
        } else {
            $fila->setPermiteTroca(false);
        }

        if (isset($_POST['permite_multiplas']) && $_POST['permite_multiplas']=='1') {
            $fila->setPermiteMultiplas(true);
        } else {
            $fila->setPermiteMultiplas(false);
        }

        if (isset($_POST['permite_chamada_espera']) && $_POST['permite_chamada_espera']=='1') {
            $fila->setPermiteChamadaEspera(true);
        } else {
            $fila->setPermiteChamadaEspera(false);
        }

        if (isset($_POST['novo_status']) && $_POST['novo_status']!='') {
            switch ($_POST['novo_status']) {
                case 'aberta':
                    $fila->setStatus(1);
                    break;
                case 'fechada':
                    $fila->setStatus(0);
                    break;
                case 'fechaauto':
                    if (isset($_POST['fechamento_programado'])) {
                        if (\DateTime::createFromFormat('Y-m-d\TH:i', $_POST['fechamento_programado'])>=$now) {
                            $fila->setStatus(3);
                        } else {
                            $fila->setStatus(0);    
                        }
                    } else {
                        $fila->setStatus(0);
                    }
                    break;
                case 'abreauto':
                    if (isset($_POST['abertura_programada'])) {
                        if (\DateTime::createFromFormat('Y-m-d\TH:i', $_POST['abertura_programada'])>=$now) {
                            $fila->setStatus(2);
                        } else {
                            $fila->setStatus(0);    
                        }
                    } else {
                        $fila->setStatus(0);
                    }
                    break;
                default:
                    $fila->setStatus(0);
                    
            }
        }


        $fila->save();

        header("Location: fila.php?evento=".$_evento_id."&id=".$_fila_id);
    }

    if (@$_REQUEST['act'] == 'Deletar Fila') {
        $fila->delete();
        header("Location: admin_filas.php");
    }
}

Template::printHeader("Detalhes da Fila", false);

FilaAdmin::checkOpen($currentEventId, $_fila_id)

?>
<div style="max-width: 1000px; margin: 0 auto; height:100vh;">
<?php if ($novo) { 
    $novoId = FilaQuery::create()
    ->filterByEventoId($currentEventId)
    ->withColumn('MAX(fila_id)+1', 'MaxFilaId')
    ->select('MaxFilaId')
    ->findOne();    
?>
    <form action="fila.php?nova=true" method="POST">
<?php } else { 
    $abertura = $fila->getAberturaProgramada() ? $fila->getAberturaProgramada()->format('Y-m-d\TH:i') : '';
    $fechamento = $fila->getFechamentoProgramado() ? $fila->getFechamentoProgramado()->format('Y-m-d\TH:i') : '';

    $status = '';

    switch($fila->getStatus()) {
        case 0:
            $status = 'Fechada';
            break;
        case 1:
            $status = 'Aberta';
            break;
        case 2:
            $status = 'Fechada - Abertura Programada';
            break;
        case 3:
            $status = 'Aberta - Fechamento Programado';
            break;
    }

    ?>
    <form action="fila.php?evento=<?= $fila->getEventoId() ?>&id=<?= $fila->getFilaId() ?>" method="POST">
<?php } ?>    
        <table id="myTable" class="tablesorter" style="margin-bottom: 0;">
            <thead>
                <tr style="height: 50px">
                    <th colspan="2" style="vertical-align: middle;" class="sorter-false">
                        <span style="float:left"><a href="admin_filas.php" style="color: white; font-size: 12px;">&nbsp;Voltar</a></span>
                        <span style="font-size: 28px;"><?= $novo?'Nova Fila':$fila->getNome(); ?></span> <br />
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>ID Evento</td>
                    <td><input style="width:700px;" type="text" name="evento_id" readonly    value="<?= $novo?$currentEventId:$fila->getEventoId() ?>"/></td>
                </tr>
                <tr>
                    <td>ID Fila</td>
                    <td><input style="width:700px;" type="number" min=1 max=9999 name="fila_id" <?= $novo?'':'disabled' ?> value="<?= $novo?$novoId:$fila->getFilaId() ?>" /></td>
                </tr>
                <tr>
                    <td>Nome</td>
                    <td><input style="width:700px;" type="text" name="nome" value="<?= $novo?'':$fila->getNome() ?>" /></td>
                </tr>
                <?php if (!$novo) { ?>
                <tr>
                    <td>Status atual</td>
                    <td><?= $status ?></td>
                </tr>
                <?php } ?>
                <tr>
                    <td>Novo status</td>
                    <td>
                        <select name="novo_status">
                            <option value="">--- Selecione se quiser alterar ---</option>
                            <option value="aberta">Aberta</option>
                            <option value="fechada">Fechada</option>
                            <option value="fechaauto">Aberta - Fechamento Automático</option>
                            <option value="abreauto">Fechada - Abertura Automática</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <td>Permite troca de senha?</td>
                    <td><input type="checkbox" name="permite_troca" value="1" <?= $novo?'':($fila->isPermiteTroca()?'checked':'') ?> /></td>
                </tr>
                <tr>
                    <td>Permite múltiplas senhas abertas?</td>
                    <td><input type="checkbox" name="permite_multiplas" value="1" <?= $novo?'':($fila->isPermiteMultiplas()?'checked':'') ?> /></td>
                </tr>
                <tr>
                    <td>Permite chamada em espera?</td>
                    <td><input type="checkbox" name="permite_chamada_espera" value="1" <?= $novo?'':($fila->isPermiteChamadaEspera()?'checked':'') ?> /></td>
                </tr>
                <tr>
                    <td>Tempo de espera [s]</td>
                    <td><input style="width:700px;" min=0 max=36000 type="number" name="tempo_espera" value="<?= $novo?'':$fila->getTempoEspera() ?>" /></td>
                </tr>
                <tr>
                    <td>Abertura programada</td>
                    <td><input style="width:700px;" type="datetime-local" name="abertura_programada" value="<?= $novo?'':htmlspecialchars($abertura) ?>" /></td>
                </tr>
                <tr>
                    <td>Fechamento programado</td>
                    <td><input style="width:700px;" type="datetime-local" name="fechamento_programado" value="<?= $novo?'':htmlspecialchars($fechamento) ?>" /></td>
                </tr>
            </tbody>
            <tfoot>
                <?php if (!$novo) { ?>
                <tr>
                    <th style="height: 30px;" colspan="2"><input type="submit" name="act" value="Atualizar" />
                </tr>
                <tr >
                    <th style="height: 100px;" colspan="2">
                        <input id="deleteBtn" type="button" value="Deletar Fila" onclick="confirmDelete();"/>
                        <div id="deleteConfirmBtn" style="display:none">
                            <strong>Tem certeza que deseja apagar a fila? Essa operação não pode ser desfeita e causará a deleção de todas as senhas ligadas a esta fila.</strong><br/><br/>
                            <input type="submit" name="act" value="Deletar Fila" style="background:red;color:white;"/>&nbsp;&nbsp;&nbsp;&nbsp;<input type="button" value="Cancelar" onclick="cancelDelete();"/>
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