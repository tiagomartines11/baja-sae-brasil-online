<?php
use Baja\Model\Input;
use Baja\Model\ProvaQuery;
use Baja\Site\Template;
use Baja\Model\EventoQuery;
use Baja\Model\ResultadoQuery;
use Baja\Model\InputQuery;

$resultado = ResultadoQuery::create()->filterByEventoId(EventoQuery::getCurrentEvent()->getEventoId())->findPk($_REQUEST['id']);
if (!$resultado) header("Location: index.php");
$colunas = (array)$resultado->getColunas()->colunas;
$pos = @$resultado->getColunas()->pos;
$filter = @$resultado->getColunas()->filter;

$vars = [];
$i = InputQuery::create()->filterByEventoId(EventoQuery::getCurrentEvent()->getEventoId())->filterByProvaId($resultado->getInputs())
    ->leftJoinEquipe()->withColumn('Equipe.Escola')->withColumn('Equipe.EquipeId')->withColumn('Equipe.Equipe')->withColumn('Equipe.Estado')
    ->find();

foreach ($i as $input) {
    if (!array_key_exists($input->getEquipeId(), $vars)) $vars[$input->getEquipeId()] = ["NUM" => $input->getEquipeEquipeId(), "EQUIPE" => $input->getEquipeEquipe(), "ESCOLA" => $input->getEquipeEscola(), "ESTADO" => $input->getEquipeEstado()];
    $vars[$input->getEquipeId()] = array_merge($vars[$input->getEquipeId()], (array)$input->getDados(), (array)$input->getVars(), (array) $input->getPontos());
    if ($filter && !$vars[$input->getEquipeId()][$filter]) {
        unset($vars[$input->getEquipeId()]);
    }
}

foreach ($vars as &$v) {
    foreach ($colunas as $c) {
        if (is_array($c->val)) {
            $highlight = $c->highlight ? Input::solveFormula($v, $c->highlight) : null;
            $func = function ($value) use ($v, $highlight) {
                $res = Input::solveFormula($v, $value);
                if ($res == $highlight) $res = "<b>$res</b>";
                return $res;
            };
            $values = array_map($func, $c->val);
            $v[$c->header] = implode("<br /> ", $values);
        } else {
            $v[$c->header] = Input::solveFormula($v, $c->val);
        }
    }
}
unset($v);

if ($pos) {
    $cmp = function($a, $b) use ($pos)
    {
        if (!array_key_exists($pos, $a)) return 0;
        $va = floatval(strip_tags($a[$pos]));
        $vb = floatval(strip_tags($b[$pos]));
        if ($va == $vb) {
            return 0;
        }
        return ($va > $vb) ? -1 : 1;
    };

    usort($vars, $cmp);

    $i = 0; $j = 0; $last = 9999;
    foreach ($vars as &$v) {
        $j++;
        if ($last != $v[$pos]) $i = $j;
        $v["POS"] = $i;
        $last = $v[$pos];
    }
    unset($v);
}
usort($vars, function($a, $b) { return ($a["NUM"] > $b["NUM"]) ? 1 : -1;} );

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
if (count($vars) && count($colunas)) {
?>
    <table id="myTable" class="tablesorter">
        <thead>
        <tr>
            <?php foreach ($colunas as $col) {
                Template::printColumnHeader($col->header, $col->size, $col->sort);
            } ?>
        </tr>
        </thead>
        <tbody>

        <?php
        foreach ($vars as $v) {
            echo "<tr>";
            foreach ($colunas as $c) {
                if ((($c->header == "Pontos" && $resultado->getResultadoId() == EventoQuery::getCurrentEvent()->getEventoId().'_END') ||
                    (($c->header == "Enduro" || $c->header == "Total" || $c->val == "Pos") && $resultado->getResultadoId() == EventoQuery::getCurrentEvent()->getEventoId().'_GER'))
                    && !$_DEV_MODE && false) {
                    echo "<td>SPOILERS</td>";
                } else if ($c->val == "EquipeNum") {
                    echo '<td>' . $v["NUM"] . '</td>';
                } else if ($c->val == "Equipe") {
                    echo '<td style="text-align: left; white-space: nowrap; overflow: hidden; text-overflow: ellipsis">' . $v["EQUIPE"] . '<br />';
                    echo '<i class="nomeEscola">' . $v["ESCOLA"] . '</i></td>';
                } else if ($c->val == "Pos") {
                    $pos = $v["POS"];
                    if ($pos == 1) echo "<td class='ouro'><p style='color:transparent'>" . $pos . "º</p></td>";
                    else if ($pos == 2) echo "<td class='prata'><p style='color:transparent'>" . $pos . "º</p></td>";
                    else if ($pos == 3) echo "<td class='bronze'><p style='color:transparent'>" . $pos . "º</p></td>";
                    else echo "<td>" . $pos . "º</td>";
                } else {
                    echo "<td>" . $v[$c->header] . "</td>";
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
            if (count($resultado->getInputs()) == 1) {
                $prova = ProvaQuery::create()->filterByEventoId(EventoQuery::getCurrentEvent()->getEventoId())->findOneByProvaId($resultado->getInputs()[0]);
                if ($prova->getStatus() == "Parcial") {
                    if ($prova->getTempo())
                        echo "Tempo de Prova: " . (new DateTime())->setTimestamp($prova->getTempo())->diff(new DateTime())->format("%H:%I") . "<br />";
                    else
                        echo "Prova Em Andamento<br />";
                } else if ($prova->getStatus() == "Final") {
                    echo "Prova Finalizada<br />";
                }
                echo "Última Atualização: " . $prova->getModificado()->setTimezone(new DateTimeZone("America/Sao_Paulo"))->format('Y-m-d H:i:s');
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