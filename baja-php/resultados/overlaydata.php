<?php

use Baja\Model\Input;
use Baja\Model\InputQuery;
use Baja\Model\ProvaQuery;
use Baja\Model\EventoQuery;
use Baja\Model\ResultadoQuery;

if (isset($_REQUEST['time'])) {
  $p = ProvaQuery::create()->filterByEventoId($_REQUEST['evt'])->findOneByProvaId('END');
  if ($p) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST');
    header("Access-Control-Allow-Headers: X-Requested-With");
    header('Content-Type: application/json');
    echo(json_encode($p->getTempo()));
    exit();
  } else {
    header('HTTP/1.0 404 Not Found');
    return false;
  }
}


$vars = [];
$i = InputQuery::create()->filterByEventoId($_REQUEST['evt'])->filterByProvaId('END')
    ->leftJoinEquipe()->withColumn('Equipe.Escola')->withColumn('Equipe.EquipeId')->withColumn('Equipe.Equipe')->withColumn('Equipe.EquipeCurto')->withColumn('Equipe.Estado')->withColumn('Equipe.Cidade')->withColumn('Equipe.EscolaCurto')->find();

if ($_REQUEST['mode'] == 'json') {
  foreach ($i as $input) {
    $dados  = (array)$input->getDados();
    if ($_REQUEST['mode'] == 'json') {
      array_push($vars, ["carro"=>$input->getEquipeId(), "equipe"=>($input->getEquipeEquipeCurto() . ' - ' . $input->getEquipeEscolaCurto()), "local"=>($input->getEquipeCidade() . ' - ' . $input->getEquipeEstado()),"voltas"=>$dados["VOLTAS"],"last_pass"=>$dados["LAST"]]);
    }
  }
} else {
  foreach ($i as $input) {
    $dados  = (array)$input->getDados();
    array_push($vars, ["equipe_id"=>$input->getEquipeId(), "equipe"=>$input->getEquipeEquipeCurto(), "escola"=>$input->getEquipeEscolaCurto(), "lap_count"=>$dados["VOLTAS"], "best_lap"=>$dados["BEST"], "last_pass"=>$dados["LAST"], "estado"=>$input->getEquipeEstado(), "cidade"=>$input->getEquipeCidade()]);
  }
}




function sorter_pos($a, $b) {
  $key = 'lap_count';
  if ($_REQUEST['mode'] == 'json') {
    $key = 'voltas';
  }
    
  if ($a[$key] == $b[$key]) {
    if ($a["last_pass"] == $b["last_pass"]) {
      return 0;
    }
    return ($a["last_pass"] < $b["last_pass"]) ? -1 : 1;
  }
  return ($a[$key] < $b[$key]) ? 1 : -1;
}

function sorter_team($a, $b) {
  $key = 'equipe_id';
  if ($_REQUEST['mode'] == 'json') {
    $key = 'carro';
  }
  if ($a[$key] == $b[$key]) {
    return 0;
  }
  return ($a[$key] < $b[$key]) ? -1 : 1;
}

if ($_REQUEST['order'] == 'pos') {
  usort($vars, 'sorter_pos');
} else {
  usort($vars, 'sorter_team');
}

if ($_REQUEST['mode'] == 'json') {

  foreach ($vars as $pos=>$v) {
    $vars[$pos]["pos"] = ($pos+1);
  }
  header('Access-Control-Allow-Origin: *');

  header('Access-Control-Allow-Methods: GET, POST');

  header("Access-Control-Allow-Headers: X-Requested-With");
  header('Content-Type: application/json');
}

echo(json_encode($vars));
