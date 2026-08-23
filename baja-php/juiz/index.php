<?php
namespace Baja\Juiz;

use Baja\Certificado\Insercao\Acesso;
use Baja\Model\EventoQuery;
use Baja\Model\ProvaQuery;
use Baja\Session;

Session::permissionCheck("index");

Template::printHeader("Início");

echo '<div style="max-width: 400px; margin: 0 auto"><table class="tablesorter">
        <thead>
            <tr class="tablesorter-ignoreRow"> 
                <th class="sorter-false">
                    <span style="float:left; width:30%; ; text-align:left; line-height:40px">
					    <img src="img/baja_grande.png" class="logo">
                    </span>
                    <span style="float:right; height:30%; text-align:right">
                        <img src="img/sae.png" class="logo" width="200px">
                    </span>
                </th>
            </tr>	
            <tr class="tablesorter-ignoreRow" style="height: 40px;">
                <th class="sorter-false" style="line-height: 22px;">'.EventoQuery::getCurrentEvent()->getNome().'<br />Entrada de Dados</th>
            </tr>
        </thead>
        <tr></tr>
        ';

foreach(ProvaQuery::create()->findByEventoId(EventoQuery::getCurrentEvent()->getEventoId()) as $prova) {
    if (Session::hasPermission($prova->getProvaId()) && (count($prova->getParamsInputs()) > 0 || $prova->getType() == 'tournament')) {
        if ($prova->getType() == 'normal') {
            echo '<tr style="height: 40px"><td><a href="dashboard.php?p=' . $prova->getProvaId() . '">' . $prova->getNome() . '</a></td></tr>';
        } elseif ($prova->getType() == 'tournament'){
            echo '<tr style="height: 40px"><td><a href="tournament_entry.php?p=' . $prova->getProvaId() . '">' . $prova->getNome() . '</a></td></tr>';
        } else {
            echo '<tr style="height: 40px"><td><a href="rolling_entry.php?p=' . $prova->getProvaId() . '">' . $prova->getNome() . '</a></td></tr>';
        }
    }
}

if (Session::hasPermission('PREMIACAO')) {
    echo '<tr style="height: 40px"><td><a href="premiacoes.php">Premia&ccedil;&otilde;es</a></td></tr>';
}

// Rendered only for a user who actually holds `certificados`. Most people
// reaching this page are judges, and a link that greets them with a permission
// rejection is worse than no link — the same check that gates the page gates
// the link. A stopgap alongside Logout and Resultados until there is real
// navigation here.
if (Acesso::permitido()) {
    echo '<tr style="height: 40px"><td><a href="certificados.php">Certificados</a></td></tr>';
}

echo '
<tfoot>
<tr>
    <th style="height: 20px; font-size: 100%;">
        '.(Session::hasPermission('admin') ? '<a href="admin.php" style="color: white;" />Administração</a>&emsp;&emsp;&middot;&emsp;&emsp;' : '').'
        <a href="login.php?act=logout" style="color: white;" />Logout</a>
    </th>
</tr>
<tr>
    <th style="height: 20px; font-size: 100%;">
        <a href="https://resultados.bajasaebrasil.net/index_juiz.php" style="color: white;" />Resultados</a>
    </th>
</tr>
</tfoot>';
echo '</table></div>';

Template::printFooter();
