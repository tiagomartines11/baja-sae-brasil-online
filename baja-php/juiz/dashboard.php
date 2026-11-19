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
// Phone first (a judge fills this in at the track): the page fits 360 px.
// The table keeps every column and scrolls inside its own box, sideways
// and down; its header row and the car number column stay in view while
// it does. Only this page's look changes here; the shared theme is left
// as it is.
?>
<style>
    /* theme.blue.css gives every judge page a body of at least 520 px: on a
       phone (360 px) that only scrolls the whole page sideways. */
    body { min-width: 0; }

    /* The title bar, above the table: "Voltar" and the prova's name, in the
       colours of the table header (theme.blue.css). Outside the table, so it
       stays put while the table scrolls sideways. */
    .dashboard-titulo { display: flex; align-items: center; gap: 8px; padding: 4px;
        background-color: #004785; color: white; font: 20px/22px "Trebuchet MS", Arial, Sans-serif; }
    .dashboard-titulo-nome { flex: 1; text-align: center; }
    /* Keeps the name centred: as wide as "Voltar" on its left. */
    .dashboard-titulo::after { content: ""; min-width: 52px; }

    /* The table scrolls in here, not the page: sideways for the many
       columns, and down within the screen's height, so its header can stay
       in view (below). */
    .dashboard-tabela { max-width: 100%; overflow: auto; -webkit-overflow-scrolling: touch;
        max-height: calc(100vh - 60px); max-height: calc(100dvh - 60px); }
    /* As wide as its columns need, and at least the screen. */
    .dashboard-tabela table.tablesorter-blue { width: auto; min-width: 100%; }
    /* One line per value: a wrapped number is hard to read. */
    .dashboard-tabela th, .dashboard-tabela td { white-space: nowrap; }
    /* The team name may wrap, so the first columns stay narrow on a phone. */
    .dashboard-tabela td.dashboard-equipe { white-space: normal; min-width: 7em; max-width: 12em; }

    /* The header row stays at the top of the box, and the car number at its
       left edge (the row's zebra colour from the theme keeps it opaque). */
    /* top: -1px covers the 1 px of the collapsed border above, which does not stick. */
    .dashboard-tabela thead th { position: sticky; top: -1px; z-index: 2; }
    .dashboard-tabela .dashboard-numero { position: sticky; left: 0; z-index: 1; }
    .dashboard-tabela thead th.dashboard-numero { z-index: 3; }
    .dashboard-tabela tbody td.dashboard-numero { font-weight: bold; box-shadow: inset -1px 0 0 #cdcdcd; }
    /* The judge pages' script also keeps a copy of the header in view while
       the PAGE scrolls (tablesorter's stickyHeaders); here the box scrolls
       instead, and that copy would not follow it sideways. */
    .tablesorter-sticky-wrapper { display: none !important; }

    /* Links big enough for a finger: at least 44 x 44 px. */
    a.dashboard-voltar, a.dashboard-editar { display: inline-flex; align-items: center; justify-content: center;
        min-width: 44px; min-height: 44px; padding: 0 8px; box-sizing: border-box; }
    a.dashboard-voltar { color: white; font-size: 14px; }
    a.dashboard-editar { font-size: 15px; }
    .dashboard-tabela td.dashboard-link { padding: 0 2px; }
</style>
<?php

// Names, codes and every value a judge saved are text: escaped (Baja\Site\Html).
$entryLink = 'entry.php?p=' . rawurlencode((string)$_page) . '&t=';
// The same "Editar" link at both ends of a row, so it is at hand wherever the table is scrolled.
$editLink = fn($num) => '<td class="dashboard-link"><a class="dashboard-editar" href="'.Html::e($entryLink . rawurlencode((string)$num)).'">Editar</a></td>';

echo '<div class="dashboard-titulo">
        <a class="dashboard-voltar" href="index.php">Voltar</a>
        <span class="dashboard-titulo-nome">'.Html::e($prova->getNome()).'</span>
    </div>';
echo '<div class="dashboard-tabela"><table id="myTable" class="tablesorter">';
echo '<thead>
        <tr> 
			<th class="dashboard-numero">#</th>
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
    echo '<td class="dashboard-numero">'.Html::e($num).'</td>';
    echo '<td class="dashboard-equipe">'.Html::e($team->getEquipe()).'</td>';
    echo $editLink($num);
    foreach ($colunas as $c) {
        $style = "";
        if (!$dados || ($dados->{$c->getCode()} ?? null) === null) $style = "background-color: #FFD1D1";
        echo "<td style='".$style."'>".Html::e($dados->{$c->getCode()} ?? '')."</td>";
    }
    echo $editLink($num);
    echo "</tr>";
}
echo '</table></div>';

Template::printFooter();