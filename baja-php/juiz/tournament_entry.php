<?php
namespace Baja\Juiz;

use Baja\Model\EquipeQuery;
use Baja\Model\EventoQuery;
use Baja\Model\InputQuery;
use Baja\Model\ProvaQuery;
use Baja\Model\TournamentQuery;
use Baja\Session;

if (!isset($_REQUEST['p'])) header("Location: index.php");

$_page = $_REQUEST['p'];
$currentEventId = EventoQuery::getCurrentEvent()->getEventoId();

function checkBlocked($match, $currentEventId, $_page) {
    $matches = TournamentQuery::create()->filterByEventoId($currentEventId)->filterByProvaId($_page)->find();
    $blocked = false;

    foreach($matches as $m) {
        
        if (
            (isset(json_decode($m->getDados())->old1) && (json_decode($m->getDados())->old1=='win'.$match || json_decode($m->getDados())->old1=='los'.$match )) 
            || 
            (isset(json_decode($m->getDados())->old2) && (json_decode($m->getDados())->old2=='win'.$match || json_decode($m->getDados())->old2=='los'.$match ))) {
            if ($m->getWinner()>0) {
                $blocked = true;
            }
        }
    }

    return $blocked;
}

if (isset($_REQUEST['reset'])) {

    $match = TournamentQuery::create()->filterByEventoId($currentEventId)->filterByProvaId($_page)->filterByMatchId($_REQUEST['reset'])->findOne();

    $match->setWinner(null);

    $match_dados = json_decode($match->getDados());

    if (isset($match_dados->bestof)){
        $match_dados->win1=0;
        $match_dados->win2=0;
    }

    $match->setDados(json_encode($match_dados));
    $match->save();

    $matches = TournamentQuery::create()->filterByEventoId($currentEventId)->filterByProvaId($_page)->find();

    foreach($matches as $m){
        $dados = false;
        if (is_object(json_decode($m->getDados()))){
            $dados = json_decode($m->getDados());

            if ($dados->old1 == 'win'.$_REQUEST['reset'] || $dados->old1 == 'los'.$_REQUEST['reset']) {
                $m->setEquipe1Id($dados->old1);
                $m->save();
            }
            
            if ($dados->old2 == 'win'.$_REQUEST['reset'] || $dados->old2 == 'los'.$_REQUEST['reset']) {
                $m->setEquipe2Id($dados->old2);
                $m->save();
            }
        }
    }

    header("Location: tournament_entry.php?p=".$_page.(isset($_REQUEST['round'])?'&round='.$_REQUEST['round']:''));
}

if (isset($_REQUEST['setwinner']) && isset($_REQUEST['match'])) {

    $match = TournamentQuery::create()->filterByEventoId($currentEventId)->filterByProvaId($_page)->filterByMatchId((int)$_REQUEST['match'])->findOne();

    $match_dados = json_decode($match->getDados());

    $victory = false;
    $winner = null;
    $loser = null;

    if (isset($match_dados->bestof)){
        if ($_REQUEST['setwinner']==$match->getEquipe1Id()) {
            $match_dados->win1++;   
        } elseif ($_REQUEST['setwinner']==$match->getEquipe2Id()) {
            $match_dados->win2++;   
        }

        if ((float)$match_dados->win1 > (float)($match_dados->bestof/2)) {
            $winner = $match->getEquipe1Id();
            $loser = $match->getEquipe2Id();
            $victory = true;
        } elseif ((float)$match_dados->win2 > (float)($match_dados->bestof/2)) {
            $winner = $match->getEquipe2Id();
            $loser = $match->getEquipe1Id();
            $victory = true;
        }

    } else {
        if ($_REQUEST['setwinner'] == $match->getEquipe1Id() || $_REQUEST['setwinner'] == $match->getEquipe2Id()){
            $winner = $_REQUEST['setwinner'];
            $loser = ($match->getEquipe1Id()==$_REQUEST['setwinner']?$match->getEquipe1Id():$match->getEquipe2Id());
            $victory = true;
        }
    }

    if ($victory) {
    
        $match->setWinner($winner);

        $dependent_matches = [];

        $dependent_matches1 = TournamentQuery::create()->filterByEventoId($currentEventId)->filterByProvaId($_page)->filterByEquipe1Id(['win'.$_REQUEST['match'],'los'.$_REQUEST['match']])->find();

        $dependent_matches2 = TournamentQuery::create()->filterByEventoId($currentEventId)->filterByProvaId($_page)->filterByEquipe2Id(['win'.$_REQUEST['match'],'los'.$_REQUEST['match']])->find();

        function updateDependents($dependent_matches, $winner, $loser) {
            foreach($dependent_matches as $dm) {
                $dados = new \stdClass();
                $dados = json_decode($dm->getDados());

                if ($dm->getEquipe1Id()=='win'.$_REQUEST['match']) {
                    $dados->old1=$dm->getEquipe1Id();
                    $dm->setEquipe1Id($winner);
                } elseif ($dm->getEquipe2Id()=='win'.$_REQUEST['match']) {
                    $dados->old2=$dm->getEquipe2Id();
                    $dm->setEquipe2Id($winner);
                } elseif ($dm->getEquipe1Id()=='los'.$_REQUEST['match']) {
                    $dados->old1=$dm->getEquipe1Id();
                    $dm->setEquipe1Id($loser);
                } elseif ($dm->getEquipe2Id()=='los'.$_REQUEST['match']) {
                    $dados->old2=$dm->getEquipe2Id();
                    $dm->setEquipe2Id($loser);
                }

                $dm->setDados(json_encode($dados));
                $dm->save();
            }
        }

        if (sizeof($dependent_matches1) > 0) {
            updateDependents($dependent_matches1, $winner, $loser);
        }
        
        if (sizeof($dependent_matches2) > 0) {
            updateDependents($dependent_matches2, $winner, $loser);
        }
    
    }

    $match->setDados(json_encode($match_dados));
    $match->save();

    header("Location: tournament_entry.php?p=".$_page.(isset($_REQUEST['round'])?'&round='.$_REQUEST['round']:''));
}



Session::permissionCheck($_page);

$currentEventId = EventoQuery::getCurrentEvent()->getEventoId();

$prova = ProvaQuery::create()->filterByEventoId($currentEventId)->findOneByProvaId($_page);



Template::printHeader($prova->getNome(), true);

var_dump($currentEventId);

$rounds = [-1=>"Disputa 3° lugar", 0=>"Final", 1=>"Semifinais", 2=>"Quartas de final", 3=>"Oitavas de Final",4=>"16 avos de Final",5=>"32 avos de Final",6=>"64 avos de Final"];


if (TournamentQuery::create()->filterByEventoId($currentEventId)->filterByProvaId($_page)->count()>0) {

$max_round = TournamentQuery::create()->filterByEventoId($currentEventId)->filterByProvaId($_page)->orderByRound('desc')->findOne()->getRound();
$min_round = TournamentQuery::create()->filterByEventoId($currentEventId)->filterByProvaId($_page)->orderByRound('asc')->findOne()->getRound();



if (isset($_REQUEST['round'])) {
    $current_round = (int)$_REQUEST['round'];
} else {
    $first_null_winner = TournamentQuery::create()->filterByEventoId($currentEventId)->filterByProvaId($_page)->filterByWinner(null)->orderByRound('desc')->findOne();
    if ($first_null_winner) {
        $current_round = $first_null_winner->getRound();
    } else {
        $current_round = 0;
    } 

}



$matches = TournamentQuery::create()->filterByEventoId($currentEventId)->filterByProvaId($_page)->filterByRound($current_round)->find();



?>

<table class="tablesorter-blue" style="margin: 0 auto; max-width:500px; table-layout:fixed;border: solid 2px black; border-collapse: collapse">
    <thead>
        <tr>
            <th colspan="4" style="border: solid 2px black; padding-bottom: 20px;" class="sorter-false">
                <span style="font-size: 28px; line-height: 34px;"><?= $prova->getNome() ?></span> <br />
            </th>
        </tr>
        <tr>
            <th class="sorter-false"><?php if ($current_round+1<=$max_round){ ?><span style="font-size: 40px;"><a href="tournament_entry.php?p=<?= $_page ?>&round=<?= $current_round+1 ?>">⬅️</a></span><?php } ?></th>
            <th colspan="2" class="sorter-false"><?= $rounds[$current_round] ?></th>
            <th class="sorter-false"><?php if ($current_round-1>=$min_round){ ?><span style="font-size: 40px;"><a href="tournament_entry.php?p=<?= $_page ?>&round=<?= $current_round-1 ?>">➡️</a></span><?php } ?></th>
        </tr>
    </thead>
    <tbody>

<?php

foreach($matches as $m) {

?>
    <tr><td colspan="4"><b>Embate: <?= $m->getMatchId() ?></b></td></tr>
    <tr>
        <td colspan="2"><span style="font-size:40px;<?= $m->getWinner()===(int)$m->getEquipe1Id()?'font-weight:bold;color:green;':'' ?>"><?= $m->getEquipe1Id() ?></span><br/><span style="font-size:15px;"><?= (int)$m->getEquipe1Id()>0?(EquipeQuery::create()->filterByEventoId($currentEventId)->filterByEquipeId((int)$m->getEquipe1Id())->findOne()->getEquipeCurto()):'-' ?></span></td>
        <td colspan="2"><span style="font-size:40px;<?= $m->getWinner()===(int)$m->getEquipe2Id()?'font-weight:bold;color:green;':'' ?>"><?= $m->getEquipe2Id() ?></span><br/><span style="font-size:15px;<?= $m->getWinner()==(int)$m->getEquipe2Id()?'text-weight:bold;color:green;':'' ?>"><?= (int)$m->getEquipe2Id()>0?(EquipeQuery::create()->filterByEventoId($currentEventId)->filterByEquipeId((int)$m->getEquipe2Id())->findOne()->getEquipeCurto()):'-' ?></span></td>
    </tr>    
<?php
    if ((int)$m->getEquipe1Id()>0 && (int)$m->getEquipe2Id()>0) { 
    
        if ($m->getWinner()>0) {
            if(checkBlocked($m->getMatchId(), $currentEventId, $_page)===false) {
?>
            <tr><td colspan="4"><a href="tournament_entry.php?p=<?= $_page ?>&round=<?= $current_round ?>&reset=<?= $m->getMatchId() ?>">Cancelar Resultado</a></td></tr>
            
<?php        
            }
        } else {
            $qtd = 1;
            $dados = json_decode($m->getDados());
            if (isset($dados->bestof)) {
                $qtd = $dados->bestof;
            }
            if ($qtd>1){
?>
                <tr><td colspan="4">Melhor de <b><?= $qtd ?></b><br/>
                <span style="font-size:35px;"><?= $dados->win1 ?>&nbsp;&nbsp;&nbsp;&nbsp;X&nbsp;&nbsp;&nbsp;&nbsp;<?= $dados->win2 ?></td></tr>
<?php

            }            
?>        
                <tr>
                    <td colspan="2"><a href="tournament_entry.php?p=<?= $_page ?>&round=<?= $current_round ?>&match=<?= $m->getMatchId() ?>&setwinner=<?= $m->getEquipe1Id() ?>"><span style="font-size:50px;">✅</span></a></td>
                    <td colspan="2"><a href="tournament_entry.php?p=<?= $_page ?>&round=<?= $current_round ?>&match=<?= $m->getMatchId() ?>&setwinner=<?= $m->getEquipe2Id() ?>"><span style="font-size:50px;">✅</span></a></td>
                </tr>
<?php
            
        }

    }
?>
    <tr><td colspan="4" style="background-color:black !important"></td></tr>
<?php

}
    
?>
    <!--tr><td colspan="2">-----------------------------------</td><td colspan="2">-----------------------------------</td></tr-->
</tbody>
    
<?php
Template::printFooter();
}