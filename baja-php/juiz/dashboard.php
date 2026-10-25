<?php
namespace Baja\Juiz;

use Baja\Model\Equipe;
use Baja\Model\EquipeQuery;
use Baja\Model\EventoQuery;
use Baja\Model\Input;
use Baja\Model\InputQuery;
use Baja\Model\ProvaQuery;
use Baja\Session;
use Baja\Site\Html;

if (!isset($_REQUEST['p'])) { header("Location: index.php"); exit; }

$_page = $_REQUEST['p'];

Session::permissionCheck($_page);

$currentEventId = EventoQuery::getCurrentEvent()->getEventoId();
$prova = ProvaQuery::create()->filterByEventoId($currentEventId)->findOneByProvaId($_page);
if (!$prova) { header("Location: index.php"); exit; }

/** @var Equipe[] $teams */
$teams = EquipeQuery::create()->filterByPresente(true)->findByEventoId($currentEventId)->toKeyIndex('EquipeId');
/** @var Input[] $items */
$items = InputQuery::create()->filterByEventoId($currentEventId)->findByProvaId($prova->getProvaId())->toKeyIndex('EquipeId');
$colunas = $prova->getParamsInputs();

Template::printHeader($prova->getNome());

// Names, codes and every value a judge saved are text: escaped (Baja\Site\Html).
$entryLink = 'entry.php?p=' . rawurlencode((string)$_page) . '&t=';

echo '<div style="margin: 0 auto;"><table id="myTable" class="tablesorter">';
echo '<thead>
        <tr>
            <th colspan="'.(4 + count($colunas)).'" class="sorter-false" style="font-size: 20px; line-height: 22px;">'.Html::e($prova->getNome()).' <span style="float: left;"><a href="index.php" style="color: white; font-size: 12px;">&nbsp;Voltar</a></span></th>
        </tr>
        <tr> 
			<th>#</th>
			<th>Equipe</th>
            <th class="sorter-false">Editar</th>';
			foreach ($colunas as $c) {
                echo '<th>'.Html::e($c->getCode()).'</th>';
            }
            echo '<th class="sorter-false">Editar</th>
		</tr>
		</thead>
		<tr></tr>
		';

foreach ($teams as $num=>$team) {
    $dados = @$items[$num] ? $items[$num]->getDados() : [];
    echo "<tr>";
    echo '<td>'.Html::e($num).'</td>';
    echo '<td>'.Html::e($team->getEquipe()).'</td>';
    echo '<td><a href="'.Html::e($entryLink . rawurlencode((string)$num)).'">Editar</a></td>';
    foreach ($colunas as $c) {
        $style = "";
        if (!$dados || ($dados->{$c->getCode()} ?? null) === null) $style = "background-color: #FFD1D1";
        echo "<td style='".$style."'>".Html::e($dados->{$c->getCode()} ?? '')."</td>";
    }
    echo '<td><a href="'.Html::e($entryLink . rawurlencode((string)$num)).'">Editar</a></td>';
    echo "</tr>";
}
echo '</table></div>';

Template::printFooter();