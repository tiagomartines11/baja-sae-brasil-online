<?php
use Baja\Model\ProvaQuery;
use Baja\Site\CachePolicy;
use Baja\Site\CleanUrl;
use Baja\Site\Template;
use Baja\Model\EventoQuery;
use Baja\Model\ResultadoQuery;
use Baja\Results\EventStatus;
use Baja\Results\ResultsBuilder;
use Baja\Results\ResultsConfig;
use Baja\Results\ViewerContext;

// The results page, for the public and for judges, on the same URL
// (docs/results-caching.md). nginx runs it one of two ways:
//
//   - without the forum session, for everybody without the judges' staff
//     cookie. The public view, cached by Cloudflare;
//   - with the forum session, for a browser with the staff cookie. A user in
//     the resultado's "authorized_users" (or a developer) sees past spoilers,
//     anybody else gets the public view. Never cached.
//
// prova_juiz.php, the judges' former copy, redirects here.
CleanUrl::enforce(['id']);
CachePolicy::send(EventoQuery::getCurrentEvent());

$resultado = ResultadoQuery::create()->filterByEventoId(EventoQuery::getCurrentEvent()->getEventoId())->findPk($_REQUEST['id'] ?? null);
if (!$resultado) {
    header("Location: index.php");
    exit;
}
$config = ResultsConfig::fromColunas($resultado->getColunas());
if ($config->type == "tournament") { header("Location: torneio.php?id=".rawurlencode($resultado->getResultadoId())); exit; }
$colunas = $config->columns;

$eventStatus = EventStatus::of(EventoQuery::getCurrentEvent());
$viewer = ViewerContext::publicView($eventStatus);
if (CachePolicy::hasSession()) {
    // $user and $_DEV_MODE come from src/bootstrap.php, which sets $user
    // only when it loaded the session.
    $authorized = ViewerContext::authorized($eventStatus, $user->data['username'] ?? null, $_DEV_MODE);
    // Only a user it lets past the spoilers (in authorized_users, or a
    // developer) gets the authorized view. Anybody else gets exactly the
    // public page: the authorized view would still rank them while the
    // public one hides the ranking (R12), and the ranking can give away
    // what the spoilers hide.
    if (!$authorized->hidesSpoilers($config->authorizedUsers)) {
        $viewer = $authorized;
    }
}
$rows = ResultsBuilder::buildFor($resultado, $config, $viewer)->pageOrder();

Template::printHeader($resultado->getNome());
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
            Template::printResultRow($colunas, $row);
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