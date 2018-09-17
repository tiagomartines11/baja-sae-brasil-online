<?php
namespace Baja\Juiz;

use Baja\Model\EventoQuery;
use Baja\Model\ProvaQuery;

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
    if (Session::hasPermission($prova->getProvaId()) && count($prova->getParamsInputs()) > 0) {
        echo '<tr style="height: 40px"><td><a href="dashboard.php?p=' . $prova->getProvaId() . '">' . $prova->getNome() . '</a></td></tr>';
    }
}

echo '
<tfoot>
<tr>
    <th style="height: 20px; font-size: 100%;">
        '.(Session::hasPermission('admin') ? '<a href="admin.php" style="color: white;" />Administração</a>&emsp;&emsp;&middot;&emsp;&emsp;' : '').'
        <a href="login.php?act=logout" style="color: white;" />Logout</a>
    </th>
</tr>
</tfoot>';
echo '</table></div>';

Template::printFooter();