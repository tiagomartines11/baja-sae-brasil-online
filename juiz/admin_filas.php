<?php
namespace Baja\Juiz;

use Baja\Model\EventoQuery;
use Baja\Model\FilaQuery;
use Baja\Site\OneSignalClient;
use Baja\Session;

Session::permissionCheck("admin");

$evento = EventoQuery::getCurrentEvent()->getEventoId();

$filas = FilaQuery::create()->filterByEventoId($evento)->find();

Template::printHeader("Admin");

?>

<div style="max-width: 1000px; margin: 0 auto; height:100vh;">

    <table id="myTable2" class="tablesorter">
        <thead>
            <tr style="height: 50px;">
                <th colspan="4" class="sorter-false">
                    <span style="float:left;"><a href="admin.php" style="color: white; font-size: 12px;">&nbsp;Voltar</a></span> 
                    <span style="font-size: 28px;">Filas (<?= $evento ?>)</span>
                </th>
            </tr>
            <tr> 
                <th>Id</th>
                <th>Nome</th>
                <th>Status</th>
                <th>Detalhes</th>
            </tr>
        </thead>
        <tbody>

<?php foreach ($filas as $f) { ?>
            <tr>
                <td><?= $f->getFilaId() ?></td>
                <td><?= $f->getNome() ?></td>
                <td><?= $f->getStatus() ?></td>
                <td><a href='fila.php?evento=<?= $evento ?>&id=<?= $f->getFilaId() ?>'><span>&nbsp;✏️&nbsp;</span></a></td>
            </tr>
<?php } ?>
        </tbody>
        <tfoot>
            <tr>
                <th colspan="5" style="height: 30px">
                    <input type="button" onclick="location.href='fila.php?nova=true';" value="Nova Fila"/>
                </th>
            </tr>
        </tfoot>
    </table>
    
</div>

<?php
Template::printFooter();