<?php
namespace Baja\Juiz;

use Baja\Auth\Csrf;
use Baja\Model\EventoQuery;
use Baja\Model\Evento;
use Baja\Session;
use Baja\Site\Html;
use DateTimeZone;
use Propel\Runtime\ActiveQuery\Criteria;

if (!isset($_REQUEST['id']) && !isset($_REQUEST['novo'])) { header("Location: admin_eventos.php"); exit; }

$_page = @$_REQUEST['id'];

Session::permissionCheck('admin');

// Every act saves or deletes: refused without the form's token, before anything else.
if (isset($_REQUEST['act'])) Csrf::enforce();

    $currentEventId = EventoQuery::getCurrentEvent()->getEventoId();

$novo = false;

// Set when the submitted menu is not valid JSON: nothing is saved and the
// form is shown again with the text as typed and this message.
$erroJson = null;

if (isset($_REQUEST['novo']) && $_REQUEST['novo']=='true') {
    $novo = true;

    if (@$_REQUEST['act'] == 'Salvar') {
        if (!isset($_POST['evento_id']) || $_POST['evento_id'] == '') {
            header("Location: evento.php?novo=true"); exit;
        } else {
            $evento = EventoQuery::create()->findOneByEventoId($_POST['evento_id']);
            if ($evento) { header("Location: evento.php?id=".$_POST['evento_id']); exit; }

            $evento = new Evento();
            
            $evento->setEventoId($_POST['evento_id']);
            $evento->setTitulo($_POST['titulo']);
            $evento->setNome($_POST['nome']);
            $evento->setTipo($_POST['programa']);
            $evento->setAno($_POST['ano']);
            $evento->setCargaHoraria($_POST['carga_horaria']);

            if (isset($_POST['ativo']) && $_POST['ativo']=='1') {
                $evento->setAtivo(true);
            } else {
                $evento->setAtivo(false);
            }

            if (isset($_POST['finalizado']) && $_POST['finalizado']=='1') {
                $evento->setFinalizado(true);
            } else {
                $evento->setFinalizado(false);
            }

            if (isset($_POST['spoilers']) && $_POST['spoilers']=='1') {
                $evento->setSpoilers(true);
            } else {
                $evento->setSpoilers(false);
            }

            if (isset($_POST['certificado']) && $_POST['certificado']=='1') {
                $evento->setTemCertificado(true);
            } else {
                $evento->setTemCertificado(false);
            }

            $evento->setData($_POST['data']);
            $evento->setLocal($_POST['local']);

            $evento->setPresidente($_POST['presidente']);
            $evento->setMandatoPresidente($_POST['mandato']);

            $menu = ConfigJson::decodificar($_POST['menu'] ?? '');
            if (!$menu->valido()) {
                $erroJson = $menu->erro;
            } else {
                $evento->setMenu($menu->valor);

                $evento->setEmAndamento(false);

                $evento->save();

                header("Location: admin_eventos.php"); exit;
            }
        }
    }

} else {

    $evento = EventoQuery::create()->findOneByEventoId($_REQUEST['id']);
    if (!$evento) { header("Location: admin_eventos.php"); exit; }

    if (@$_REQUEST['act'] == 'Atualizar') {
        $evento->setTitulo($_POST['titulo']);
        $evento->setNome($_POST['nome']);
        $evento->setTipo($_POST['programa']);
        $evento->setAno($_POST['ano']);
        $evento->setCargaHoraria($_POST['carga_horaria']);

        if (isset($_POST['ativo']) && $_POST['ativo']=='1') {
            $evento->setAtivo(true);
        } else {
            $evento->setAtivo(false);
        }

        if (!$evento->getEmAndamento()) {
            if (isset($_POST['em_andamento']) && $_POST['em_andamento']=='1') {
                $evento->setEmAndamento(true);
            } else {
                $evento->setEmAndamento(false);
            }
        }

        if (isset($_POST['finalizado']) && $_POST['finalizado']=='1') {
            $evento->setFinalizado(true);
        } else {
            $evento->setFinalizado(false);
        }

        if (isset($_POST['spoilers']) && $_POST['spoilers']=='1') {
            $evento->setSpoilers(true);
        } else {
            $evento->setSpoilers(false);
        }

        if (isset($_POST['certificado']) && $_POST['certificado']=='1') {
            $evento->setTemCertificado(true);
        } else {
            $evento->setTemCertificado(false);
        }

        $evento->setData($_POST['data']);
        $evento->setLocal($_POST['local']);

        $evento->setPresidente($_POST['presidente']);
        $evento->setMandatoPresidente($_POST['mandato']);

        // On an invalid menu nothing is saved. The fields above stay set on the
        // unsaved $evento, so the form below shows what was typed in them too.
        $menu = ConfigJson::decodificar($_POST['menu'] ?? '');
        if (!$menu->valido()) {
            $erroJson = $menu->erro;
        } else {
            $evento->setMenu($menu->valor);

            $evento->save();

            if (isset($_POST['em_andamento']) && $_POST['em_andamento']=='1') {
                EventoQuery::create()
                    ->filterByEventoId($_REQUEST['id'], Criteria::NOT_EQUAL)
                    ->update(['EmAndamento' => false]);
            }


            header("Location: evento.php?id=".$_page); exit;
        }
    }

    if (@$_REQUEST['act'] == 'Deletar Evento') {
        $evento->delete();
        header("Location: admin_eventos.php"); exit;
    }
}

Template::printHeader("Detalhes do Evento", false);



?>
<div style="max-width: 1000px; margin: 0 auto; height:100vh;">
<?php if ($novo) { ?>
    <form action="evento.php?novo=true" method="POST">
        <?= Csrf::field() ?>
<?php } else { ?>
    <form action="evento.php?id=<?= Html::e(rawurlencode($evento->getEventoId())) ?>" method="POST">
        <?= Csrf::field() ?>
<?php } ?>    
        <table id="myTable" class="tablesorter" style="margin-bottom: 0;">
            <thead>
                <tr style="height: 50px">
                    <th colspan="2" style="vertical-align: middle;" class="sorter-false">
                        <span style="float:left"><a href="admin_eventos.php" style="color: white; font-size: 12px;">&nbsp;Voltar</a></span>
                        <span style="font-size: 28px;"><?= $novo?'Novo Evento':Html::e($evento->getNome()); ?></span> <br />
                    </th>
                </tr>
            </thead>
            <tbody>
                <?php if ($erroJson !== null) { ?>
                <tr>
                    <td colspan="2" style="color: red; font-weight: bold;">Evento não salvo: o JSON do menu é inválido (<?= htmlspecialchars($erroJson) ?>). Corrija o texto do menu e salve de novo.</td>
                </tr>
                <?php } ?>
                <tr>
                    <td>ID Evento</td>
                    <td><input style="width:700px;" type="text" name="evento_id" <?= $novo?'':'disabled' ?> value="<?= $novo?'':Html::field($evento->getEventoId()) ?>"/></td>
                </tr>
                <tr>
                    <td>Título</td>
                    <td><input style="width:700px;" type="text" name="titulo" value="<?= $novo?'':Html::field($evento->getTitulo()) ?>" /></td>
                </tr>
                <tr>
                    <td>Nome</td>
                    <td><input style="width:700px;" type="text" name="nome" value="<?= $novo?'':Html::field($evento->getNome()) ?>" /></td>
                </tr>
                <tr>
                    <td>Tipo</td>
                    <td><input style="width:700px;" type="text" name="programa" value="<?= $novo?'':Html::field($evento->getTipo()) ?>" /></td>
                </tr>
                <tr>
                    <td>Ano</td>
                    <td><input style="width:700px;" type="number" name="ano" value="<?= $novo?'':Html::field($evento->getAno()) ?>" /></td>
                </tr>
                <tr>
                    <td>Menu<br /><a href="ajuda_menu.php" target="_blank" style="font-size: 12px;">❓ Ajuda</a></td>
                    <td><textarea style="width:700px;min-height:600px;" name="menu" id="menu" <?= $novo?'':'oninput="resetStyles();"' ?>><?php
                        if ($erroJson !== null) {
                            // Give back exactly what was typed, so the fix is one edit away
                            echo htmlspecialchars($_POST['menu'] ?? '');
                        } else {
                            $menu = $novo ? null : $evento->getMenu();
                            echo $menu === null ? '' : Html::field(json_encode($menu, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                        }
                    ?></textarea></td>
                </tr>
                <tr>
                    <td>Ativo?</td>
                    <td><input type="checkbox" name="ativo" value="1" <?= $novo?'':($evento->isAtivo()?'checked':'') ?> /></td>
                </tr>
                <tr>
                    <td>Evento padrão site?</td>
                    <td><input type="checkbox" name="em_andamento" value="1" <?= $novo?'disabled':($evento->isEmAndamento()?'checked disabled':'') ?> /></td>
                </tr>
                <tr>
                    <td>Finalizado?</td>
                    <td><input type="checkbox" name="finalizado" value="1" <?= $novo?'':($evento->getFinalizado()?'checked':'') ?> /></td>
                </tr>
                <tr>
                    <td>Spoilers?</td>
                    <td><input type="checkbox" name="spoilers" value="1" <?= $novo?'':($evento->getSpoilers()?'checked':'') ?> /></td>
                </tr>
                <tr>
                    <td>Tem certificado?</td>
                    <td><input type="checkbox" name="certificado" value="1" <?= $novo?'':($evento->getTemCertificado()?'checked':'') ?> /></td>
                </tr>
                <tr>
                    <td>Data Evento</td>
                    <td><input style="width:700px;" type="text" name="data" value="<?= $novo?'':Html::field($evento->getData()) ?>" /></td>
                </tr>
                <tr>
                    <td>Local Evento</td>
                    <td><input style="width:700px;" type="text" name="local" value="<?= $novo?'':Html::field($evento->getLocal()) ?>" /></td>
                </tr>
                <tr>
                    <td>Presidente SAE</td>
                    <td><input style="width:700px;" type="text" name="presidente" value="<?= $novo?'':Html::field($evento->getPresidente()) ?>" /></td>
                </tr>
                <tr>
                    <td>Mandato Presidente</td>
                    <td><input style="width:700px;" type="text" name="mandato" value="<?= $novo?'':Html::field($evento->getMandatoPresidente()) ?>" /></td>
                </tr>
                <tr>
                    <td>Carga Horária</td>
                    <td><input style="width:700px;" type="number" name="carga_horaria" value="<?= $novo?'':Html::field($evento->getCargaHoraria()) ?>" min="1" max="9999"/></td>
                </tr>                
            </tbody>
            <tfoot>
                <?php if (!$novo) { ?>
                <tr>
                    <th style="height: 30px;" colspan="2"><input type="submit" name="act" value="Atualizar" />
                </tr>
                <tr >
                    <th style="height: 100px;" colspan="2">
                        <input id="deleteBtn" type="button" value="Deletar Evento" onclick="confirmDelete();"/>
                        <div id="deleteConfirmBtn" style="display:none">
                            <strong>Tem certeza que deseja apagar o evento? Essa operação não pode ser desfeita e causará a deleção de todos os outros registros ligados a este evento (equipes, provas, resultados, dados).</strong><br/><br/>
                            <input type="submit" name="act" value="Deletar Evento" style="background:red;color:white;"/>&nbsp;&nbsp;&nbsp;&nbsp;<input type="button" value="Cancelar" onclick="cancelDelete();"/>
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