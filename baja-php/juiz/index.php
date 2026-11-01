<?php
namespace Baja\Juiz;

use Baja\Certificado\Insercao\Acesso;
use Baja\Contagem\Access\ContagemAccess;
use Baja\Model\EventoQuery;
use Baja\Model\ProvaQuery;
use Baja\Rbac\Authorization;
use Baja\Rbac\Permission\Permission;
use Baja\Session;
use Baja\Site\Html;

// Anyone holding any active grant in this event (on it, one of its provas
// or queues) or above it. What they then see depends on what they hold.
if (!Authorization::hasAnyGrantInEvent(Session::getCurrentUser(), Authorization::currentEventId())) {
    die(Authorization::REFUSAL);
}

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
                <th class="sorter-false" style="line-height: 22px;">'.Html::e(EventoQuery::getCurrentEvent()->getNome()).'<br />Entrada de Dados</th>
            </tr>
        </thead>
        <tr></tr>
        ';

foreach(ProvaQuery::create()->findByEventoId(EventoQuery::getCurrentEvent()->getEventoId()) as $prova) {
    // A laps prova is listed for its controller, its counters and the pit
    // log. contagem.php then sends each of them to the right place.
    if ($prova->getType() == ContagemAccess::PROVA_TYPE) {
        if (ContagemAccess::mayEnter($prova)) {
            echo '<tr style="height: 40px"><td><a href="contagem.php?p=' . htmlspecialchars(rawurlencode((string) $prova->getProvaId())) . '">' . htmlspecialchars((string) $prova->getNome()) . '</a></td></tr>';
        }
        continue;
    }
    if (Authorization::currentUserCan(Permission::PROVA_ENTER_SCORES, Authorization::prova($prova->getProvaId())) && (count($prova->getParamsInputs()) > 0 || $prova->getType() == 'tournament')) {
        if ($prova->getType() == 'normal') {
            echo '<tr style="height: 40px"><td><a href="dashboard.php?p=' . htmlspecialchars(rawurlencode((string) $prova->getProvaId())) . '">' . htmlspecialchars((string) $prova->getNome()) . '</a></td></tr>';
        } elseif ($prova->getType() == 'tournament'){
            echo '<tr style="height: 40px"><td><a href="tournament_entry.php?p=' . htmlspecialchars(rawurlencode((string) $prova->getProvaId())) . '">' . htmlspecialchars((string) $prova->getNome()) . '</a></td></tr>';
        } else {
            echo '<tr style="height: 40px"><td><a href="rolling_entry.php?p=' . htmlspecialchars(rawurlencode((string) $prova->getProvaId())) . '">' . htmlspecialchars((string) $prova->getNome()) . '</a></td></tr>';
        }
    }
}

if (Authorization::currentUserCan(Permission::PREMIACAO_VIEW, Authorization::currentEvent())) {
    echo '<tr style="height: 40px"><td><a href="premiacoes.php">Premia&ccedil;&otilde;es</a></td></tr>';
}

// Rendered only for a user who may open the certificate pages. Most people
// reaching this page are judges, and a link that greets them with a permission
// rejection is worse than no link — the same check that gates the page gates
// the link. A stopgap alongside Logout and Resultados until there is real
// navigation here.
if (Acesso::permitido()) {
    echo '<tr style="height: 40px"><td><a href="certificados.php">Certificados</a></td></tr>';
}

// Whoever configures this event's timers or operates them.
$temTimer = Authorization::currentUserCan(Permission::TIMER_MANAGE, Authorization::currentEvent())
    || Authorization::currentUserCan(Permission::TIMER_OPERATE, Authorization::currentEvent());
if ($temTimer) {
    echo '<tr style="height: 40px"><td><a href="timer.php">Timers</a></td></tr>';
}

echo '
<tfoot>
<tr>
    <th style="height: 20px; font-size: 100%;">
        '.(Authorization::currentUserCan(Permission::EVENTO_MANAGE, Authorization::currentEvent()) ? '<a href="admin.php" style="color: white;">Administração</a>&emsp;&emsp;&middot;&emsp;&emsp;' : '').'
        <a href="login.php?act=logout" style="color: white;">Logout</a>
    </th>
</tr>
<tr>
    <th style="height: 20px; font-size: 100%;">
        <a href="https://resultados.bajasaebrasil.net/index_juiz.php" style="color: white;">Resultados</a>
    </th>
</tr>
</tfoot>';
echo '</table></div>';

Template::printFooter();
