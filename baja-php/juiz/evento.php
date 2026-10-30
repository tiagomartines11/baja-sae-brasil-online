<?php
namespace Baja\Juiz;

use Baja\Auth\Csrf;
use Baja\Model\EventoQuery;
use Baja\Model\Evento;
use Baja\Model\ProgramaQuery;
use Baja\Juiz\DatasEvento;
use Baja\ResultsArchive\Queue;
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

// Every event belongs to a program (BAJAAPP-11), chosen on this form and
// never worked out from the event code. A save without a known, active
// program saves nothing and shows the form again with this set.
$erroPrograma = false;
$programaEscolhido = isset($_POST['programa_id']) && is_string($_POST['programa_id'])
    ? ProgramaQuery::create()->findPk($_POST['programa_id'])
    : null;

// The event's first and last day (BAJAAPP-12). Optional, but a date that is
// not a real day, or a last day before the first, saves nothing and shows
// the form again with this message.
$datas = DatasEvento::doFormulario($_POST['data_inicio'] ?? null, $_POST['data_fim'] ?? null);
$erroDatas = null;

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
            } elseif ($programaEscolhido === null || !$programaEscolhido->getAtivo()) {
                $erroPrograma = true;
            } elseif (!$datas->valido()) {
                $erroDatas = $datas->erro;
            } else {
                $evento->setMenu($menu->valor);
                $evento->setProgramaId($programaEscolhido->getProgramaId());
                $evento->setDataInicio($datas->inicio);
                $evento->setDataFim($datas->fim);

                $evento->setEmAndamento(false);

                // A new event created already finalizado asks for an
                // archive like any other (BAJAAPP-67). The event is saved
                // even when the archive queue cannot be written; then the
                // event's own page opens, to show why.
                if (!Queue::saveEvento($evento, false, Session::getCurrentUser()->getUsername())) {
                    header("Location: evento.php?id=" . urlencode($evento->getEventoId()) . "&arquivo=falhou"); exit;
                }

                header("Location: admin_eventos.php"); exit;
            }
        }
    }

} else {

    $evento = EventoQuery::create()->findOneByEventoId($_REQUEST['id']);
    if (!$evento) { header("Location: admin_eventos.php"); exit; }

    // As stored, before the form changes it: marking finalizado is what
    // asks for the event's results to be archived (BAJAAPP-67).
    $eraFinalizado = (bool)$evento->getFinalizado();

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
        } elseif ($programaEscolhido === null
            || (!$programaEscolhido->getAtivo() && $programaEscolhido->getProgramaId() !== $evento->getProgramaId())) {
            // An inactive program is only accepted when it is the one the
            // event already has, as the select below offers.
            $erroPrograma = true;
        } elseif (!$datas->valido()) {
            $erroDatas = $datas->erro;
        } else {
            $evento->setMenu($menu->valor);
            $evento->setProgramaId($programaEscolhido->getProgramaId());
            $evento->setDataInicio($datas->inicio);
            $evento->setDataFim($datas->fim);

            // Saves, then records or cancels the archive request a
            // finalizado change asks for. The event is saved even when that
            // second step fails; the page then says so (below).
            $arquivoOk = Queue::saveEvento($evento, $eraFinalizado, Session::getCurrentUser()->getUsername());

            if (isset($_POST['em_andamento']) && $_POST['em_andamento']=='1') {
                EventoQuery::create()
                    ->filterByEventoId($_REQUEST['id'], Criteria::NOT_EQUAL)
                    ->update(['EmAndamento' => false]);
            }


            header("Location: evento.php?id=".$_page . ($arquivoOk ? '' : '&arquivo=falhou')); exit;
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
                <?php if (@$_GET['arquivo'] === 'falhou') { ?>
                <tr>
                    <td colspan="2" style="color: #a60; font-weight: bold;">Evento salvo, mas o arquivamento dos resultados não foi registrado (erro no servidor, já anotado no log). Avise a equipe técnica. Depois que for corrigido, desmarque e marque "Finalizado?" de novo para pedir o arquivamento.</td>
                </tr>
                <?php } ?>
                <?php if ($erroJson !== null) { ?>
                <tr>
                    <td colspan="2" style="color: red; font-weight: bold;">Evento não salvo: o JSON do menu é inválido (<?= htmlspecialchars($erroJson) ?>). Corrija o texto do menu e salve de novo.</td>
                </tr>
                <?php } ?>
                <?php if ($erroPrograma) { ?>
                <tr>
                    <td colspan="2" style="color: red; font-weight: bold;">Evento não salvo: escolha o programa do evento e salve de novo.</td>
                </tr>
                <?php } ?>
                <?php if ($erroDatas !== null) { ?>
                <tr>
                    <td colspan="2" style="color: red; font-weight: bold;">Evento não salvo: <?= Html::e($erroDatas) ?>. Corrija as datas e salve de novo.</td>
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
                    <td>Programa</td>
                    <td><select name="programa_id" required>
                        <?php
                        // Active programs, plus this event's own if it was made inactive
                        // since: an old event keeps its program.
                        $programaAtual = $novo ? null : $evento->getProgramaId();
                        if ($novo) { ?>
                        <option value="">(escolha)</option>
                        <?php }
                        foreach (ProgramaQuery::create()->orderByOrdem()->find() as $programa) {
                            if (!$programa->getAtivo() && $programa->getProgramaId() !== $programaAtual) continue; ?>
                        <option value="<?= Html::e($programa->getProgramaId()) ?>" <?= $programa->getProgramaId() === $programaAtual ? 'selected' : '' ?>><?= Html::e($programa->getNome()) ?></option>
                        <?php } ?>
                    </select></td>
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
                    <td><input type="checkbox" name="finalizado" value="1" <?= $novo?'':($evento->getFinalizado()?'checked':'') ?> />
                        <?php
                        // Where the archive of the published results stands (BAJAAPP-67).
                        // Only the display is forgiving: before the migration has run
                        // there is no table, and the page must still open.
                        try {
                            $arquivamento = $novo ? null : Queue::forApp()->latestFor((string)$evento->getEventoId());
                        } catch (\Throwable $e) {
                            $arquivamento = null;
                        }
                        if ($arquivamento !== null) {
                            $quando = static fn($ms) => $ms === null ? '' : (new \DateTime('@' . intdiv((int)$ms, 1000)))->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('d/m/Y H:i');
                            if ($arquivamento['estado'] === Queue::PENDENTE) {
                                $texto = 'Arquivamento dos resultados pendente desde ' . $quando($arquivamento['pedido_em_ms']) . '; é feito no backup da noite.';
                                if ((int)$arquivamento['tentativas'] > 0) {
                                    $texto .= ' Falhou ' . (int)$arquivamento['tentativas'] . 'x: ' . $arquivamento['ultimo_erro'];
                                }
                            } elseif ($arquivamento['estado'] === Queue::ARQUIVADO) {
                                $texto = 'Resultados arquivados em ' . $quando($arquivamento['concluido_em_ms']) . ' (' . $arquivamento['pasta'] . ').';
                            } else {
                                $texto = 'Último pedido de arquivamento cancelado: ' . $arquivamento['ultimo_erro'] . '.';
                            }
                            echo '<span style="font-size: 12px; margin-left: 8px;">' . htmlspecialchars($texto) . '</span>';
                        }
                        ?>
                    </td>
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
                <?php
                // After a refused save, the dates as typed; otherwise as stored.
                $dataDoCampo = static fn(string $campo, ?string $salva): string
                    => $erroDatas !== null ? (is_string($_POST[$campo] ?? null) ? $_POST[$campo] : '') : (string)$salva;
                ?>
                <tr>
                    <td>Primeiro dia</td>
                    <td><input type="date" name="data_inicio" value="<?= Html::field($dataDoCampo('data_inicio', $novo ? null : $evento->getDataInicio('Y-m-d'))) ?>" /></td>
                </tr>
                <tr>
                    <td>Último dia</td>
                    <td><input type="date" name="data_fim" value="<?= Html::field($dataDoCampo('data_fim', $novo ? null : $evento->getDataFim('Y-m-d'))) ?>" />
                        <span style="font-size: 12px; margin-left: 8px;">Os papéis dados no evento (juízes, filas...) terminam alguns dias depois deste dia. Sem ele, cada papel precisa de uma data de fim.</span></td>
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