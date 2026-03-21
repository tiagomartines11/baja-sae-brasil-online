<?php
namespace Baja\Juiz;

use Baja\Model\EventoQuery;
use Baja\Model\EquipeQuery;
use Baja\Model\User;
use Baja\Model\UserQuery;
use Baja\Session;

Session::permissionCheck("admin");

$evento = EventoQuery::getCurrentEvent()->getEventoId();
$equipes = EquipeQuery::create()->filterByEventoId($evento)->find();

Template::printHeader("Admin");

?>

<div style="max-width: 1000px; margin: 0 auto; height:100vh;">

    <table id="myTable2" class="tablesorter">
        <thead>
            <tr style="height: 50px;">
                <th colspan="5" class="sorter-false">
                    <span style="float:left;"><a href="admin.php" style="color: white; font-size: 12px;">&nbsp;Voltar</a></span> 
                    <span style="font-size: 28px;">Equipes (<?= $evento ?>)</span>
                </th>
            </tr>
            <tr> 
                <th>Carro</th>
                <th>Equipe</th>
                <th>Escola</th>
                <th>Detalhes</th>
            </tr>
        </thead>
        <tbody>

<?php foreach ($equipes as $eq) { ?>
            <tr>
                <td><?= $eq->getEquipeId() ?></td>
                <td><?= $eq->getEquipe() ?></td>
                <td><?= $eq->getEscola() ?></td>
                <td><a href='equipe.php?id=<?= $eq->getEquipeId() ?>'><span>&nbsp;✏️&nbsp;</span></a></td>
            </tr>
<?php } ?>
        </tbody>
        <tfoot>
            <tr>
                <th colspan="5" style="height: 30px">
                    <input type="button" onclick="location.href='equipe.php?nova=true';" value="Nova Equipe"/>
                </th>
            </tr>
        </tfoot>
    </table>
    
</div>

<?php
Template::printFooter();