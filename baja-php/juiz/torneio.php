<?php
namespace Baja\Juiz;

use Baja\Model\EventoQuery;
use Baja\Model\EquipeQuery;
use Baja\Model\LogQuery;
use Baja\Model\ProvaQuery;
use Baja\Model\TournamentQuery;
use Baja\Model\Tournament;
use Baja\Site\OneSignalClient;
use Baja\Session;
use DateTimeZone;
use Baja\Model\Input;
use Baja\Model\ResultadoQuery;
use Baja\Model\InputQuery;

if (!isset($_REQUEST['id'])) header("Location: index.php");

$_page = $_REQUEST['id'];

Session::permissionCheck('admin');

$currentEventId = EventoQuery::getCurrentEvent()->getEventoId();
$prova = ProvaQuery::create()->filterByEventoId($currentEventId)->findOneByProvaId($_page);
if (!$prova) header("Location: index.php");

$params = $prova->getParams();

$match_count = TournamentQuery::create()->filterByEventoId($currentEventId)->filterByProvaId($_page)->count();

if (isset($_REQUEST['freeze']) && isset($_REQUEST['classification'])) {

    $params->status->frozen = true;
    $params->status->classification = array_map('intval',explode(",",$_REQUEST['classification']));

    $prova->setParams($params)->save();

    header("Location: torneio.php?id=".$_page);
    
}

if (isset($_REQUEST['unfreeze'])) {
    $params->status->frozen = false;
    $params->status->classification = false;

    $prova->setParams($params)->save();

    header("Location: torneio.php?id=".$_page);
}

if (isset($_REQUEST['destroybracket'])) {
    
    TournamentQuery::create()->filterByEventoId($currentEventId)->filterByProvaId($_page)->delete();

    header("Location: torneio.php?id=".$_page);

}

if (isset($_REQUEST['createbracket'])) {

    if ($params->status->frozen && is_array($params->status->classification)) {

        $classification = $params->status->classification;

        $raw_brackets = [[1, 6, 1, 128],[2, 6, 64, 65],[3, 6, 32, 97],[4, 6, 33, 96],[5, 6, 16, 113],[6, 6, 49, 80],[7, 6, 17, 112],[8, 6, 48, 81],[9, 6, 8, 121],[10, 6, 57, 72],[11, 6, 25, 104],[12, 6, 40, 89],[13, 6, 9, 120],[14, 6, 56, 73],[15, 6, 24, 105],[16, 6, 41, 88],[17, 6, 4, 125],[18, 6, 61, 68],[19, 6, 29, 100],[20, 6, 36, 93],[21, 6, 13, 116],[22, 6, 52, 77],[23, 6, 20, 109],[24, 6, 45, 84],[25, 6, 5, 124],[26, 6, 60, 69],[27, 6, 28, 101],[28, 6, 37, 92],[29, 6, 12, 117],[30, 6, 53, 76],[31, 6, 21, 108],[32, 6, 44, 85],[33, 6, 2, 127],[34, 6, 63, 66],[35, 6, 31, 98],[36, 6, 34, 95],[37, 6, 15, 114],[38, 6, 50, 79],[39, 6, 18, 111],[40, 6, 47, 82],[41, 6, 7, 122],[42, 6, 58, 71],[43, 6, 26, 103],[44, 6, 39, 90],[45, 6, 10, 119],[46, 6, 55, 74],[47, 6, 23, 106],[48, 6, 42, 87],[49, 6, 3, 126],[50, 6, 62, 67],[51, 6, 30, 99],[52, 6, 35, 94],[53, 6, 14, 115],[54, 6, 51, 78],[55, 6, 19, 110],[56, 6, 46, 83],[57, 6, 6, 123],[58, 6, 59, 70],[59, 6, 27, 102],[60, 6, 38, 91],[61, 6, 11, 118],[62, 6, 54, 75],[63, 6, 22, 107],[64, 6, 43, 86],[65, 5, 'win1', 'win2'],[66, 5, 'win3', 'win4'],[67, 5, 'win5', 'win6'],[68, 5, 'win7', 'win8'],[69, 5, 'win9', 'win10'],[70, 5, 'win11', 'win12'],[71, 5, 'win13', 'win14'],[72, 5, 'win15', 'win16'],[73, 5, 'win17', 'win18'],[74, 5, 'win19', 'win20'],[75, 5, 'win21', 'win22'],[76, 5, 'win23', 'win24'],[77, 5, 'win25', 'win26'],[78, 5, 'win27', 'win28'],[79, 5, 'win29', 'win30'],[80, 5, 'win31', 'win32'],[81, 5, 'win33', 'win34'],[82, 5, 'win35', 'win36'],[83, 5, 'win37', 'win38'],[84, 5, 'win39', 'win40'],[85, 5, 'win41', 'win42'],[86, 5, 'win43', 'win44'],[87, 5, 'win45', 'win46'],[88, 5, 'win47', 'win48'],[89, 5, 'win49', 'win50'],[90, 5, 'win51', 'win52'],[91, 5, 'win53', 'win54'],[92, 5, 'win55', 'win56'],[93, 5, 'win57', 'win58'],[94, 5, 'win59', 'win60'],[95, 5, 'win61', 'win62'],[96, 5, 'win63', 'win64'],[97, 4, 'win65', 'win66'],[98, 4, 'win67', 'win68'],[99, 4, 'win69', 'win70'],[100, 4, 'win71', 'win72'],[101, 4, 'win73', 'win74'],[102, 4, 'win75', 'win76'],[103, 4, 'win77', 'win78'],[104, 4, 'win79', 'win80'],[105, 4, 'win81', 'win82'],[106, 4, 'win83', 'win84'],[107, 4, 'win85', 'win86'],[108, 4, 'win87', 'win88'],[109, 4, 'win89', 'win90'],[110, 4, 'win91', 'win92'],[111, 4, 'win93', 'win94'],[112, 4, 'win95', 'win96'],[113, 3, 'win97', 'win98'],[114, 3, 'win99', 'win100'],[115, 3, 'win101', 'win102'],[116, 3, 'win103', 'win104'],[117, 3, 'win105', 'win106'],[118, 3, 'win107', 'win108'],[119, 3, 'win109', 'win110'],[120, 3, 'win111', 'win112'],[121, 2, 'win113', 'win114'],[122, 2, 'win115', 'win116'],[123, 2, 'win117', 'win118'],[124, 2, 'win119', 'win120'],[125, 1, 'win121', 'win122'],[126, 1, 'win123', 'win124'],[127, 0, 'win125', 'win126'],[128, -1, 'los125', 'los126']];

        if (!$params->tournament->third_place_match) {
            array_pop($raw_brackets);
        }
        
        $participants = sizeof($classification);

        if ($participants > 128) { die('Participantes excessivos!'); }

        $rounds = (int)ceil(log($participants,2))-1;

        $brackets = [];

        foreach($raw_brackets as $b) {
            if($b[1]==6){
                $win = null;
                if ($b[2]>$participants && $b[3]>$participants) {
                    $win = 0;
                } elseif ($b[2]<=$participants && $b[3]<=$participants) {
                    $win = null;
                } elseif ($b[2] > $participants) {
                    $win = $classification[$b[3]-1];
                } else {
                    $win = $classification[$b[2]-1];
                }

                array_push($brackets,
                    [
                        $b[0],
                        $b[1],
                        ($b[2]>$participants?0:$classification[$b[2]-1]),
                        ($b[3]>$participants?0:$classification[$b[3]-1]),
                        $win
                    ]);
            } else {
                array_push($brackets,
                [
                    $b[0],
                    $b[1],
                    $b[2],
                    $b[3],
                    null
                ]);
            }
        }

        for ($i=0;$i<6;$i++) {
            foreach($brackets as $k => $b) {
                if ($b[4] !== null) {
                    $winner = null;
                    if ($b[4] == 0) { $winner = 'WO'; } else { $winner = $b[4]; }
                    foreach($brackets as $j => $br) {
                        if ($br[2] == 'win'.$b[0]) {
                            $brackets[$j][2] = $winner;
                        }
                        if ($br[3] == 'win'.$b[0]) {
                            $brackets[$j][3] = $winner;
                        }
                    }
                }
            }
            
            foreach($brackets as $k => $b) {
                if ($b[2] == 'WO') {
                    $brackets[$k][4] = $b[3];
                } elseif ($b[3] == 'WO') {
                    $brackets[$k][4] = $b[2];
                }
            }
        }

        $brackets = array_filter($brackets, function($item) use ($rounds) {
            return $item[1] <= $rounds;
        });

        foreach($brackets as $b) {
            $match = new Tournament();
            $match->setEventoId($currentEventId);
            $match->setProvaId($_page);
            $match->setMatchId($b[0]);
            $match->setRound($b[1]);
            $match->setEquipe1Id($b[2]);
            $match->setEquipe2Id($b[3]);
            $match->setWinner($b[4]);
            if (isset($params->tournament->bestof) && is_object($params->tournament->bestof) && $b[1]<= $params->tournament->bestof->from_round) {
                $match->setDados(json_encode(["win1"=>0, "win2"=>0, "bestof"=>$params->tournament->bestof->matches]));
            }
            $match->save();
        }
    }
    
    header("Location: torneio.php?id=".$_page);

}

Template::printHeader("Detalhes de Prova", false);
?>
    
    <div style="max-width: 600px; margin: 0 auto;">
        <form id="tournamentform" action="torneio.php?id=<?= $prova->getProvaId(); ?>" method="POST">
        <table id="myTable" class="tablesorter" style="margin-bottom: 0;">
            <thead>
            <tr style="height: 50px">
                <th colspan="2" style="vertical-align: middle;" class="sorter-false">
                    <span style="float:left"><a href="admin.php" style="color: white; font-size: 12px;">&nbsp;Voltar</a></span>
                    <span style="font-size: 28px;"><?php echo $prova->getNome(); ?></span> <br />
                </th>
            </thead>
            <tbody>
            <tr>
                <td>Status Atual:</td>
                <td><?= $match_count>0?'Chaveamento fechado':($params->status->frozen?'Classificação fechada':'Aberto') ?></td>
            </tr>
            </tbody>
            <tfoot>
            <tr>
                <th style="height: 30px;" colspan="2">
                    <?php if ($params->status->frozen) { ?>
                        <input type="submit" name="unfreeze" value="Descongelar Classificação" <?= $match_count>0?'disabled':'' ?>/>
                    <?php } else { ?>
                        <input type="submit" name="freeze" value="Congelar Classificação"/>
                    <?php }
                        if ($match_count > 0) { ?>
                        <input type="submit" name="destroybracket" value="Destruir Chaveamento"/>
                    <?php } else { ?>
                        <input type="submit" name="createbracket" value="Criar Chaveamento" <?= $params->status->frozen?'':'disabled' ?>/>
                    <?php } ?>
                </th>
            </tr>
            </tfoot>
        </table>
        </form>
        <br />
        <table id="classificação" class="tablesorter">
            <thead>
                <tr>

<?php

if ($params->status->frozen) {

?>
                    <th>Carro</th>
                    <th>Equipe</th>
                    <th>Classificação</th>
                </tr>
            </thead>
            <tbody>
<?php                
    
            foreach ($params->status->classification as $k => $eq) {
?>
                <tr>
                    <td><?= $eq ?></td>
                    <td><?= EquipeQuery::create()->filterByEventoId($currentEventId)->findOneByEquipeId($eq)->getEquipe(); ?></td>
                    <td><?= $k+1 ?></td>
                </tr>
<?php
            }

} else {
    
$able_cars = [];

$vars = [];
$i = InputQuery::create()->filterByEventoId(EventoQuery::getCurrentEvent()->getEventoId())->filterByProvaId($params->seeding->able->inputs)
    ->leftJoinEquipe()->withColumn('Equipe.EquipeId')->withColumn('Equipe.Equipe')
    ->find();

foreach ($i as $input) {
    if (!array_key_exists($input->getEquipeId(), $vars)) $vars[$input->getEquipeId()] = ["NUM" => $input->getEquipeEquipeId(), "EQUIPE" => $input->getEquipeEquipe()];
    $dados = (array)$input->getDados();
    $vars[$input->getEquipeId()] = array_merge($vars[$input->getEquipeId()], $dados, (array)$input->getVars(), (array)$input->getPontos());    
}

foreach ($vars as $v) {
    if(Input::solveFormula($v, $params->seeding->able->formula) === 1){
        array_push($able_cars,[$v["NUM"],$v["EQUIPE"]]);
    }
}

$classifications = [];
$classificion_count = 0;



foreach ($able_cars as $k => $ac) {
    foreach ($params->seeding->classification as $class) {
        if ($class->formula == 'random') {
            array_push($able_cars[$k], rand(0,1000000000000));
            if ($k==0) { array_push($classifications, 'asc'); ++$classificion_count; }
        } else {
            $vars = [];
            $i = InputQuery::create()->filterByEventoId(EventoQuery::getCurrentEvent()->getEventoId())->filterByProvaId($class->inputs)
                ->leftJoinEquipe()->withColumn('Equipe.EquipeId')->withColumn('Equipe.Equipe')
                ->find();

            foreach ($i as $input) {
                if (!array_key_exists($input->getEquipeId(), $vars)) $vars[$input->getEquipeId()] = ["NUM" => $input->getEquipeEquipeId(), "EQUIPE" => $input->getEquipeEquipe()];
                $dados = (array)$input->getDados();
                $vars[$input->getEquipeId()] = array_merge($vars[$input->getEquipeId()], $dados, (array)$input->getVars(), (array)$input->getPontos());    
            }
            array_push($able_cars[$k], Input::solveFormula($vars[$ac[0]], $class->formula));
            if ($k==0) { array_push($classifications, $class->order); ++$classificion_count;}
        }
    }
}

for($x = ($classificion_count+1); $x>1; $x--) {
    usort($able_cars, function($a, $b) use ($x) {
        $scoreA = $a[$x];
        $scoreB = $b[$x];
        
        // Compare scores based on sorting order
        if ($scoreA != $scoreB) {
            return ($classifications[$x-2] == 'asc') ? ($scoreA > $scoreB) : ($scoreA < $scoreB);
        }
    // If all scores are equal, maintain the original order
        return 0;
    });
}

$current_classification = [];

foreach ($able_cars as $ac) {
    array_push($current_classification,$ac[0]);
}

?>

                    <th>Carro</th>
                    <th>Equipe</th>
                    <?php
                        foreach ($params->seeding->classification as $class) {
                    ?>
                            <th><?= $class->title ?></th>
                    <?php
                        }
                    ?>
                    <th>Classificação</th>
                </tr>
            </thead>
            <input type="hidden" name="classification" value="<?= implode(",",$current_classification) ?>" form="tournamentform"/>
            <tbody>
                <?php
                    foreach($able_cars as $i => $ac) { if (is_array($ac)) {
                ?>
                        <tr>
                <?php
                         foreach($ac as $car) {
                ?>
                            <td><?= $car ?></td>
                <?php
                        }
                ?>
                    <td><?= $i+1 ?></td></tr>
                <?php       
                    }}
                ?>
            
<?php
}
?>
            </tbody>
        </table>
        </form>
        <br/>
        
    </div>


<?php
Template::printFooter();