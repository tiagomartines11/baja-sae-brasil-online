<?php
use Baja\Model\ProvaQuery;
use Baja\Site\Template;
use Baja\Model\EventoQuery;
use Baja\Model\ResultadoQuery;
use Baja\Results\EventStatus;
use Baja\Results\ResultsBuilder;
use Baja\Results\ResultsConfig;
use Baja\Results\ViewerContext;

// The public results page. prova_juiz.php shows this same page to judges:
// it sets $judgeViewer and $judgeMenu, then includes this file. Requests
// that come straight here (nginx gives them SKIP_AUTH, no forum session)
// always get the public view, which is cached publicly.
if (!isset($judgeViewer)) {
    header('Cache-Control: public, max-age=30');
}

$resultado = ResultadoQuery::create()->filterByEventoId(EventoQuery::getCurrentEvent()->getEventoId())->findPk($_REQUEST['id']);
if (!$resultado) {
    header("Location: index.php");
    exit;
}
$config = ResultsConfig::fromColunas($resultado->getColunas());
if ($config->type == "tournament") { header("Location: torneio.php?id=".$_REQUEST['id']); exit; }
$colunas = $config->columns;

$viewer = $judgeViewer ?? ViewerContext::publicView(EventStatus::of(EventoQuery::getCurrentEvent()));
$rows = ResultsBuilder::forPages()->buildFor($resultado, $config, $viewer)->rows;

Template::printHeader($resultado->getNome(), true, $judgeMenu ?? false);
?>
<script id="js">
	$(function(){
		$("table").tablesorter({
			theme : 'blue',
			widgets: [ 'zebra', 'stickyHeaders'　]
        });
	});
    $.tablesorter.addParser({
        id: 'duasmaior',
        is: function(s, table, cell, $cell) {
            return false;
        },
        format: function(s, table, cell, cellIndex) {
            if (s.length === 0) return "";
            var split = s.split(" ");
            if (split.length === 1) return parseFloat(s);
            var a = parseFloat(split[0]);
            var b = parseFloat(split[1]);
            if (isNaN(a)) a = 0;
            if (isNaN(b)) b = 0;
            return a < b ? b : a;
        },
        // set type, either numeric or text
        type: 'numeric'
    });
    $.tablesorter.addParser({
        id: 'duasmenor',
        is: function(s, table, cell, $cell) {
            return false;
        },
        format: function(s, table, cell, cellIndex) {
            if (s.length === 0) return "";
            var split = s.split(" ");
            if (split.length === 1) return parseFloat(s);
            var a = parseFloat(split[0]);
            var b = parseFloat(split[1]);
            if (isNaN(a)) a = 999;
            if (isNaN(b)) b = 999;
            return a > b ? b : a;
        },
        // set type, either numeric or text
        type: 'numeric'
    });
</script>

<?php
if (count($rows) && count($colunas)) {
?>
    <table id="myTable" class="tablesorter">
        <thead>
        <tr>
            <?php foreach ($colunas as $col) {
                Template::printColumnHeader($col->header, $col->size, $col->sort, $col->hidden);
            } ?>
        </tr>
        </thead>
        <tbody>

        <?php
        foreach ($rows as $row) {
            echo "<tr>";
            foreach ($colunas as $c) {
                #if ((
                #    (($c->header == "Pontos" || $c->header == "Voltas" || $c->val == "Pos" || $c->header == "Points" || $c->header == "Laps") && $resultado->getResultadoId() == EventoQuery::getCurrentEvent()->getEventoId().'_END') ||
                #    (($c->header == "Enduro" || $c->header == "Total" || $c->val == "Pos" || $c->header == "Endurance") && $resultado->getResultadoId() == EventoQuery::getCurrentEvent()->getEventoId().'_GER') ||
                #    (($c->header == "Pontos" || $c->header == "Núcleo Técnico" || $c->header == "Total" || $c->val == "Pos") && $resultado->getResultadoId() == EventoQuery::getCurrentEvent()->getEventoId().'_PRO') ||
                #    (($c->val != "EquipeNum" || $c->val == "Equipe") && $resultado->getResultadoId() == EventoQuery::getCurrentEvent()->getEventoId().'_PRT')
                #    ) && EventoQuery::getCurrentEvent()->getSpoilers() && !EventoQuery::getCurrentEvent()->getFinalizado() && !$_DEV_MODE) {
                #    echo "<td>SPOILERS</td>";
                #} else if ($c->val == "EquipeNum") {
                if ($c->val == "EquipeNum") {
                    echo '<td>' . $row->team->number . '</td>';
                } else if ($c->val == "Equipe") {
                    echo '<td style="text-align: left; white-space: nowrap; overflow: hidden; text-overflow: ellipsis">' . $row->team->name . '<br />';
                    #echo '<i class="nomeEscola">' . $v["ESCOLA"] . '</i></td>';
                    #Ajuste para desclassificadas
                    echo '<i class="nomeEscola">' . $row->team->school . '</i>';
                    if ($row->team->isDisqualified()){
                        echo '<br/><small style="color:red;">Desclassificada</small>';
                    }
                    echo '</td>';
                } else if ($c->val == "Pos") {
                    $pos = $row->position;
                    if ($pos == 1) echo "<td class='ouro'><p style='color:transparent'>" . $pos . "º</p></td>";
                    else if ($pos == 2) echo "<td class='prata'><p style='color:transparent'>" . $pos . "º</p></td>";
                    else if ($pos == 3) echo "<td class='bronze'><p style='color:transparent'>" . $pos . "º</p></td>";
                    else echo "<td>" . $pos . "º</td>";
                } else {
                    echo '<td>' . $row->display($c->key()) . "</td>";
                }
            }
            echo "</tr>";
        }
        //}else{
        //	echo "</tr>\n</thead><tbody>";
        //	if($pg == "aviso")echo "<tr><td><img src='img/".(_ETAPA == 'NAC' ? 'aviso.gif' : '2017se.jpg')."' height=500px></td></tr>";
        //	if($prova && !$prova->getStatus())echo "<tr><td><img src='img/aviso2.gif' height=500px></td></tr>";
        //}

        ?>
        </tbody>
        <tfoot>
        <th colspan="<?= count($colunas) ?>" style="line-height: 24px;">
            <?php
            // A resultado built from a single prova shows that prova's status.
            // Its Inputs may name a prova that does not exist in this event
            // (deleted, or mistyped): then show no status rather than calling
            // getStatus() on null, which is a fatal error.
            $prova = count($resultado->getInputs()) == 1
                ? ProvaQuery::create()->filterByEventoId(EventoQuery::getCurrentEvent()->getEventoId())->findOneByProvaId($resultado->getInputs()[0])
                : null;
            if ($prova !== null) {
                if ($prova->getStatus() == "Parcial") {
                    if ($prova->getTempo())
                        echo "Tempo de Prova: " . (new DateTime())->setTimestamp($prova->getTempo())->diff(new DateTime())->format("%H:%I") . "<br />";
                    else
                        echo "Prova Em Andamento<br />";
                } else if ($prova->getStatus() == "Final") {
                    echo "Prova Finalizada<br />";
                }
                // Older provas (05NE to 17SU) were saved before this column was
                // filled in, so modificado can be NULL: skip the line rather than
                // calling setTimezone() on null, which is a fatal error.
                $ultimaAtualizacao = $prova->getModificado();
                if ($ultimaAtualizacao !== null) {
                    echo "Última Atualização: " . $ultimaAtualizacao->setTimezone(new DateTimeZone("Etc/GMT+3"))->format('Y-m-d H:i:s');
                }
                if (!EventoQuery::getCurrentEvent()->getFinalizado()) echo "<br /><span style='color: yellow'>Sujeito a Alterações sem Prévio Aviso</span>";
            }
            ?>
        </th>
        </tfoot>
    </table>

<?php
} else {
?>
    <div>
        <img src="img/aviso2_18.gif" style="min-width: 520px; max-width: 800px; width: 100%"/>
    </div>
<?php
}
Template::printFooter();
?>