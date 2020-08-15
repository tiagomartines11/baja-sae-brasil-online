<?php
use Baja\Model\Input;
use Baja\Model\ProvaQuery;
use Baja\Site\Template;
use Baja\Model\EventoQuery;
use Baja\Model\ResultadoQuery;
use Baja\Model\InputQuery;

$_evento = '19SU';
$_prova = '19SU_END';

$resultado = ResultadoQuery::create()->filterByEventoId($_evento)->findPk($_prova);
if (!$resultado) header("Location: index.php");
$colunas = (array)$resultado->getColunas()->colunas;
$filter = @$resultado->getColunas()->filter;

$vars = [];
$i = InputQuery::create()->filterByEventoId($_evento)->filterByProvaId($resultado->getInputs())
    ->leftJoinEquipe()->withColumn('Equipe.Escola')->withColumn('Equipe.EquipeId')->withColumn('Equipe.Equipe')->withColumn('Equipe.Estado')
    ->find();

foreach ($i as $input) {
    if (!array_key_exists($input->getEquipeId(), $vars)) $vars[$input->getEquipeId()] = ["NUM" => $input->getEquipeEquipeId(), "EQUIPE" => $input->getEquipeEquipe(), "ESCOLA" => $input->getEquipeEscola(), "ESTADO" => $input->getEquipeEstado()];
    $vars[$input->getEquipeId()] = array_merge($vars[$input->getEquipeId()], (array)$input->getDados(), (array)$input->getVars(), (array) $input->getPontos());
    if ($filter && !$vars[$input->getEquipeId()][$filter]) {
        unset($vars[$input->getEquipeId()]);
    }
}

echo '<table>';
foreach ($vars as $v) {
    echo "<tr><td>{$v["NUM"]}</td>";
    echo "<td>{$v["VOLTAS"]}</td></tr>";
}
echo '</table>';
