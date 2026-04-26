<?php
use Baja\Model\Input;
use Baja\Model\ProvaQuery;
use Baja\Site\Template;
use Baja\Model\EventoQuery;
use Baja\Model\EquipeQuery;
use Baja\Model\Equipe;
use Baja\Model\ResultadoQuery;
use Baja\Model\InputQuery;
use Baja\Model\TournamentQuery;

$evt = ResultadoQuery::create()->findOneByResultadoId($_REQUEST['id'])->getEventoId();

$resultado = ResultadoQuery::create()->filterByEventoId($evt)->findPk($_REQUEST['id']);
if (!$resultado) header("Location: index.php");
$colunas = (array)$resultado->getColunas()->colunas;
if (@$resultado->getColunas()->type != "tournament") header("Location: prova.php?id=".$_REQUEST['id']);

Template::printHeaderTournament($resultado->getNome());

$overlay = false;

if ($_REQUEST['overlay']) $overlay = true;

# if (!$overlay) Template::printMenu();

$rounds = ["-1"=>"Disputa 3° lugar", "0"=>"Final", "1"=>"Semifinais", "2"=>"Quartas de final", "3"=>"Oitavas de Final","4"=>"16 avos de Final","5"=>"32 avos de Final","6"=>"64 avos de Final"];

$matches = TournamentQuery::create()->filterByEventoId($evt)->filterByProvaId($resultado->getInputs())->orderByMatchId('asc')->orderByRound('desc')->find();

$teams = EquipeQuery::create()->filterByEventoId($evt);

?>

<div class="container">
  <h1><?= $resultado->getNome() ?></h1>
  <h2><?=  EventoQuery::getCurrentEvent()->getNome() ?></h2>
  <div class="tournament-bracket tournament-bracket--rounded">

  <?php

    $current_round = false;

    foreach($matches as $m) {

      if ($m->getRound() != $current_round) {

        if ($current_round !== false) {
          ?>
              </ul>
            </div>
          <?php
        }

        $current_round = $m->getRound();

        ?>
        <div class="tournament-bracket__round tournament-bracket__round--<?= $current_round ?>">
          <h3 class="tournament-bracket__round-title"><?= $rounds[$current_round] ?></h3>
          <ul class="tournament-bracket__list">
      
        <?php

      }
      ?>

      <li class="tournament-bracket__item">
        <div class="tournament-bracket__match" tabindex="0">
          <table class="tournament-bracket__table">

          <?php if (is_int(json_decode($m->getDados())->bestof)) { ?>
          
            <caption class="tournament-bracket__caption">
                <time>Melhor de <?= json_decode($m->getDados())->bestof ?></time>
            </caption>
        <?php } ?>

            <thead class="sr-only">
              <tr>
              <?php if (is_int(json_decode($m->getDados())->bestof)) { ?>
          
                <th>Vitórias</th>
            <?php } ?>
                <th>Equipe</th>
              </tr>
            </thead>  
            <tbody class="tournament-bracket__content">
              <tr class="tournament-bracket__team <?= ($m->getWinner() == $m->getEquipe1Id() && ctype_digit($m->getEquipe1Id()))?'tournament-bracket__team--winner':'' ?>">
                <td class="tournament-bracket__score">
                  <span class="tournament-bracket__number"><abbr class="tournament-bracket__code"><?= (!ctype_digit($m->getEquipe1Id()))?($m->getEquipe1Id()=='WO'?'WO':'?'):($m->getEquipe1Id().' - '.EquipeQuery::create()->filterByEventoId($evt)->findOneByEquipeId($m->getEquipe1Id())->getEquipeCurto()) ?></abbr></span>
                </td>

                <?php if (is_int(json_decode($m->getDados())->bestof)) { ?>
          
                    <td class="tournament-bracket__score">
                    <span class="tournament-bracket__number"><?= json_decode($m->getDados())->win1 ?></span>
                  </td>
                <?php } ?>   

              </tr>
              <tr class="tournament-bracket__team <?= ($m->getWinner() == $m->getEquipe2Id() && ctype_digit($m->getEquipe1Id()))?'tournament-bracket__team--winner':'' ?>">
                <td class="tournament-bracket__score">
                <span class="tournament-bracket__number"><abbr class="tournament-bracket__code"><?= (!ctype_digit($m->getEquipe2Id()))?($m->getEquipe2Id()=='WO'?'WO':'?'):($m->getEquipe2Id().' - '.EquipeQuery::create()->filterByEventoId($evt)->findOneByEquipeId($m->getEquipe2Id())->getEquipeCurto()) ?></abbr></span>
                </td>
                <?php if (is_int(json_decode($m->getDados())->bestof)) { ?>
          
                    <td class="tournament-bracket__score">
                    <span class="tournament-bracket__number"><?= json_decode($m->getDados())->win2 ?></span>
                    </td>
                <?php } ?>   
              </tr>
            </tbody>
          </table>
        </div>
      </li>

      <?php

    }

    ?>

  </div>
</div>
