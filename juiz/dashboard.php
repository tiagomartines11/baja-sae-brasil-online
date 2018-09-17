<?php
namespace Baja\Juiz;

use Baja\Model\Equipe;
use Baja\Model\EquipeQuery;
use Baja\Model\EventoQuery;
use Baja\Model\Input;
use Baja\Model\InputQuery;
use Baja\Model\ProvaQuery;

if (!isset($_REQUEST['p'])) header("Location: index.php");

$_page = $_REQUEST['p'];

Session::permissionCheck($_page);

$currentEventId = EventoQuery::getCurrentEvent()->getEventoId();
$prova = ProvaQuery::create()->filterByEventoId($currentEventId)->findOneByProvaId($_page);
if (!$prova) header("Location: index.php");

/** @var Equipe[] $teams */
$teams = EquipeQuery::create()->filterByPresente(true)->findByEventoId($currentEventId)->toKeyIndex('EquipeId');
/** @var Input[] $items */
$items = InputQuery::create()->filterByEventoId($currentEventId)->findByProvaId($prova->getProvaId())->toKeyIndex('EquipeId');
$colunas = $prova->getParamsInputs();

Template::printHeader($prova->getNome());

echo '<div style="max-width: 800px; margin: 0 auto;"><table id="myTable" class="tablesorter">';
echo '<thead>
        <tr>
            <th colspan="'.(3 + count($colunas)).'" class="sorter-false" style="font-size: 20px; line-height: 22px;">'.$prova->getNome().' <span style="float: left;"><a href="index.php" style="color: white; font-size: 12px;">&nbsp;Voltar</a></span></th>
        </tr>
        <tr> 
			<th>#</th>
			<th>Equipe</th>';
			foreach ($colunas as $c) {
                echo '<th>'.$c->getCode().'</th>';
            }
            echo '<th class="sorter-false">Editar</th>
		</tr>
		</thead>
		<tr></tr>
		';

foreach ($teams as $num=>$team) {
    $dados = $items[$num] ? $items[$num]->getDados() : [];
    echo "<tr>";
    echo "<td>$num</td>";
    echo "<td>{$team->getEquipe()}</td>";
    foreach ($colunas as $c) {
        $style = "";
        if (!$dados || $dados->{$c->getCode()} === null) $style = "background-color: #FFD1D1";
        echo "<td style='".$style."'>".$dados->{$c->getCode()}."</td>";
    }
    echo '<td><a href="entry.php?p='.$_page.'&t='.$num.'">Editar</a></td>';
    echo "</tr>";
}
echo '</table></div>';

Template::printFooter();