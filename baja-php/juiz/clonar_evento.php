<?php
namespace Baja\Juiz;

use Baja\Auth\Csrf;
use Baja\Model\EventoQuery;
use Baja\Model\Evento;
use Baja\Model\ProvaQuery;
use Baja\Model\Prova;
use Baja\Model\ResultadoQuery;
use Baja\Model\Resultado;
use Propel\Runtime\Propel;
use Baja\Session;
use Baja\Site\Html;
use DateTimeZone;
// Without this, "Exception" below means Baja\Juiz\Exception, which does not
// exist: the error paths died with "class not found" instead of their message.
use Exception;
use Baja\Model\Map\EventoTableMap;

Session::permissionCheck('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Cloning creates an event: refused without the form's token, before anything else.
    Csrf::enforce();

    $refEventoId    = $_POST['ref_evento_id'] ?? null;
    $novoEventoId    = $_POST['novo_evento_id'] ?? null;
    $cloneProvas    = !empty($_POST['clone_provas']);
    $cloneResultados= !empty($_POST['clone_resultados']);
    $autoUpdateAno   = !empty($_POST['auto_update_ano']);
    

    if (!$refEventoId || !$novoEventoId) {
        die("Falta ID novo evento e/ou evento referência.");
    }

    $con = Propel::getWriteConnection(EventoTableMap::DATABASE_NAME);

    try {
        $con->transaction(function() use (
            $refEventoId, $novoEventoId,
            $cloneProvas, $cloneResultados,
            $autoUpdateAno, $con
        ) {

            // Para presidente e mandato, copia do último evento tipo=0 (nacional)
            $refEventoPresidente = EventoQuery::create()
                ->filterByTipo('Nacional')
                ->orderByAno('desc')
                ->findOne($con);

            // Para demais dados, copia do evento referência
            $refEvento = EventoQuery::create()->findPk($refEventoId, $con);
            if (!$refEvento) {
                throw new Exception("Evento de referência não encontrado: $refEventoId");
            }

            // Busca ano no novo evento_id
            $newAno = ClonagemEvento::anoDoEvento($novoEventoId);

            $novoEvento = new Evento();
            $novoEvento->setEventoId($novoEventoId);
            $novoEvento->setTipo($refEvento->getTipo());

            $titulo = $refEvento->getTitulo();
            $nome = $refEvento->getNome();
            
            // Atualiza nome e título com base no novo ano
            if ($autoUpdateAno) {
                $titulo = preg_replace('/\b\d{4}/', $newAno, $titulo, 1);
                $nome = preg_replace_callback('/\b(\d{1,2})\b/', function($m) {
                    return $m[1] + 1;
                }, $nome, 1);
            }

            $novoEvento->setTitulo($titulo);
            $novoEvento->setNome($nome);
            $novoEvento->setAno($newAno);

            // Copia o menu trocando o prefixo dos IDs de resultado (25BR_ACE -> 26BR_ACE)
            // e o evento nos links (fila.php?evento=25BR -> ?evento=26BR), para que o
            // menu do novo evento aponte para o novo evento.
            $novoEvento->setMenu(MenuEvento::trocarPrefixoEvento($refEvento->getMenu(), $refEventoId, $novoEventoId));

            // Se achou último nacional, copia presidente e mandato de lá
            if ($refEventoPresidente) {
                $novoEvento->setPresidente($refEventoPresidente->getPresidente());
                $novoEvento->setMandatoPresidente($refEventoPresidente->getMandatoPresidente());
            } else {
                $novoEvento->setPresidente($refEvento->getPresidente());
                $novoEvento->setMandatoPresidente($refEvento->getMandatoPresidente());
            }
            
            $novoEvento->setAtivo(false);
            $novoEvento->setFinalizado(false);
            $novoEvento->setSpoilers(false);
            $novoEvento->setTemCertificado(false);
            $novoEvento->setData(null);            
            $novoEvento->setLocal($refEvento->getLocal());
            $novoEvento->setEmAndamento(false);
            $novoEvento->save($con);

            // Clona provas se selecionado
            if ($cloneProvas) {
                $provas = ProvaQuery::create()
                    ->filterByEventoId($refEventoId)
                    ->find($con);

                foreach ($provas as $p) {
                    $newP = new Prova();
                    $newP->fromArray($p->toArray());
                    $newP->setEventoId($novoEventoId);
                    $newP->setStatus('Parcial');
                    $newP->setTotals(null);
                    $newP->save($con);
                }
            }

            // Clona resultados ajustando evento_id no resultado_id
            if ($cloneResultados) {
                $resultados = ResultadoQuery::create()
                    ->filterByEventoId($refEventoId)
                    ->find($con);

                foreach ($resultados as $r) {
                    $newR = new Resultado();
                    $newR->fromArray($r->toArray());

                    // Ajusta evento_id no resultado_id
                    $oldId = $r->getResultadoId();
                    if (str_starts_with($oldId, $refEventoId . '_')) {
                        $newId = $novoEventoId . substr($oldId, strlen($refEventoId));
                    } else {
                        $newId = $oldId;
                    }

                    $newR->setResultadoId($newId);
                    $newR->setEventoId($novoEventoId);
                    $newR->save($con);
                }
            }
        });

        header("Location: evento.php?id=".$novoEventoId); exit;

    } catch (\Propel\Runtime\Exception\PropelException $e) {
        if (str_contains($e->getMessage(), '1062')) {
            echo "❌ O evento '" . Html::e($novoEventoId) . "' já existe.";
        } else {
            echo "❌ Erro no banco de dados: " . Html::e($e->getMessage());
            var_dump($e);
        }
    } catch (Exception $e) {
        echo "❌ Falha ao clonar evento: " . Html::e($e->getMessage());
    }
} else {

    $eventos = EventoQuery::create()->orderByEventoId('desc')->find();

Template::printHeader("Detalhes do Evento", false);

?>
<div style="max-width: 1000px; margin: 0 auto; height:100vh;">
    <form action="clonar_evento.php" method="POST">
        <?= Csrf::field() ?>
        <table id="myTable" class="tablesorter" style="margin-bottom: 0;">
            <thead>
                <tr style="height: 50px">
                    <th colspan="2" style="vertical-align: middle;" class="sorter-false">
                        <span style="float:left"><a href="admin_eventos.php" style="color: white; font-size: 12px;">&nbsp;Voltar</a></span>
                        <span style="font-size: 28px;">Clonar Evento</span> <br />
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>ID Novo Evento</td>
                    <td><input style="width:700px;" type="text" size="4" name="novo_evento_id" /></td>
                </tr>
                <tr>
                    <td>Evento Referência</td>
                    <td>
                        <select name="ref_evento_id">
                            <option disabled selected value> -- Selecione -- </option>
            <?php foreach ($eventos as $ev) { ?>
                            <option value="<?= Html::field($ev->getEventoId()) ?>" ><?= Html::e($ev->getEventoId()) ?></option>
            <?php } ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <td>Clonar Provas?</td>
                    <td><input type="checkbox" name="clone_provas" value="1" /></td>
                </tr>
                <tr>
                    <td>Clonar Resultados?</td>
                    <td><input type="checkbox" name="clone_resultados" value="1" /></td>
                </tr>
                <tr>
                    <td>Atualizar ano automaticamente?</td>
                    <td><input type="checkbox" name="auto_update_ano" value="1" /></td>
                </tr>
                <tr>
                    <td colspan="2">⚠️ ATENÇÃO!!<br/>Revise com atenção todos os dados do evento, provas e resultados após a clonagem.</td>                    
                </tr>                
            </tbody>
            <tfoot>                
                <tr>
                    <th style="height: 30px;" colspan="2"><input type="submit" name="act" value="Clonar" />
                </tr>                
            </tfoot>
        </table>
    </form>
</div>


<?php
Template::printFooter();
}