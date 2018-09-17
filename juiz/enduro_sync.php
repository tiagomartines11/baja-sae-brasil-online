<?php
namespace Baja\Model;

//$mysql2_hostname	=	"bajasaebrasil.online";
//$mysql2_user		=	"enduro";
//$mysql2_password	=	"baja2018";
//$mysql2_database =	"baja";
//
//$db1 = new \PDO("mysql:dbname={$mysql2_database};host={$mysql2_hostname};port=3306", $mysql2_user, $mysql2_password) or die("Erro Conexão");
//
//$sql1 = $db1->prepare('SELECT enduro_id_eq, Coalesce(enduro_pts, 0), Coalesce(enduro_voltas,0), entrou FROM enduro');
//$sql1->execute();
//$dados = $sql1->fetchAll();
//
//
//foreach ($dados as $d) {
//    if ($d[2] > 1) {
//        $input = InputQuery::create()->filterByEquipeId($d[0])->filterByProvaId('END')->filterByEventoId('18BR')->findOne();
//        if (!$input) {
//            $input = new Input();
//            $input->setEquipeId($d[0]);
//            $input->setEventoId('18BR');
//            $input->setProvaId('END');
//        }
//        $input->setDados(array("VOLTAS"=> intval($d[2])-1));
//        $input->save();
//    }
//}

$prova = ProvaQuery::create()->findPk([EventoQuery::getCurrentEvent()->getEventoId(), "END"]);

$prova->refreshVarsAndPontos();
echo "OK";
exit();




