<?php

namespace Baja\Fila;

use Baja\Model\EventoQuery;
use Baja\Model\FilaQuery;
use Baja\Model\SenhaQuery;

define("FECHADA",0);
define("ABERTA",1);
define("PROG_ABRIR",2);
define("PROG_FECHAR",3);

define("FILA",0);
define("ATENDIDO",1);
define("AGUARDANDO",2);
define("NOSHOW",3);
define("ABANDONADO",4);
define("CANCELADA",5);

if (isset($_REQUEST['evento'])) $evento_id = $_REQUEST['evento'];

if (isset($_REQUEST['fila'])) $fila_id = $_REQUEST['fila'];

try {
	$fila_id = (int)$fila_id;
} catch (Exception $e) {
	//.
}

if (!$evento_id || !$fila_id || !is_int($fila_id)) {
	header("Location: index.php");
}

$fila = FilaQuery::create()->filterByEventoId($evento_id)->findOneByFilaId($fila_id);

$senhas_fila = Fila::getFila($evento_id, $fila_id, 200, false);

$senhas_aguardando = SenhaQuery::create()->filterByEventoId($evento_id)->filterByFilaId($fila_id)->filterByStatus(AGUARDANDO)->find();

$evento = EventoQuery::create()->findPk($evento_id);

$fila_status = '';

switch($fila->getStatus()){
	case FECHADA:
		$fila_status = 'Fechada';
		break;
	case ABERTA:
		$fila_status = 'Aberta';
		break;
	case PROG_ABRIR:
		$fila_status = 'Fechada';
		break;
	case PROG_FECHAR:
		$fila_status = 'Aberta';
		break;
}

$senha_atual = Fila::getSenhaAtual($evento_id, $fila_id);
$carro_atual = Fila::getCarroSenha($evento_id, $fila_id, $senha_atual);

?>

<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/html">
	<head>
		<meta name="viewport" content="width=device-width" />
		<meta charset="UTF-8" />
		<meta http-equiv="Content-Language" content="pt-br">
		<meta name="google" content="notranslate">
		<meta http-equiv="refresh" content="10">
		<link rel="icon" href="img/baja.png" type="image/png">
		<title>Baja SAE BRASIL - Fila</title>
	</head>
	<body style="background-color:black;color:white;font-family:Arial;text-align: center;">
		<div style="display: grid; grid-template-columns: 65% 1fr;gap: 0px;grid-template-rows:100%;width:98vw;height:98vh;">
			<div style=" grid-column:1;grid-row:1;">
				<span style="font-size:min(3vw,3vh);color:grey;"><b>Evento:</b> <?= $evento->getTitulo() ?></span><br/>
				<span style="font-size:min(3vw,3vh);color:grey;"><b>Fila:</b> <?= $fila->getNome() ?></span><br/>
				<span style="font-size:min(3vw,3vh);color:grey;"><b>Status:</b> <?= $fila_status ?></span></br></br>
				<span style="font-size:min(8vw,6vh);text-decoration: underline;"><b>Senha atual:</b></span><br/>
				<span style="font-size:min(35vw,40vh);font-weight:bold;"><?= str_pad($senha_atual, 3, '0', STR_PAD_LEFT); ?></span></br>
				<span style="font-size:min(4vw,4vh);font-weight:bold;color:red">Carro: #<?= $carro_atual ?></span></br></br>
				<span style="font-size:min(6vw,5vh);color:lightblue;text-decoration: underline;"><b>Em espera:</b></span><br/>
				<span style="font-size:min(15vw,20vh);color:red;">
				<?php 
					if (sizeof($senhas_aguardando) > 0) { 
						echo $senhas_aguardando[0]->getSenha();
						if (sizeof($senhas_aguardando)>1) {
							echo ", ";
							echo $senhas_aguardando[1]->getSenha();
							if (sizeof($senhas_aguardando)>2) {
								echo ", ";
								echo $senhas_aguardando[2]->getSenha();
								if (sizeof($senhas_aguardando)>3) {
									echo ", ...";
								}
							}
						}
					}
				?>
				</span><br/>
			</div>
			<div style=" grid-column:2;grid-row:1;background-color:white;overflow:scroll;color:black;">
				<span style="font-size:min(8vw,8vh);text-decoration: underline;"><b>Fila:</b></span><br/><br/>
				<?php
					foreach( $senhas_fila as $k => $sf) {
						echo '<span style="font-size:min(4.5vw,4.5vh);color:blue;">' . ($k+1) . ':&nbsp;&nbsp;</span>';
						echo '<span style="font-size:min(6vw,6vh);font-weight:bold;">' . $sf['senha'] . '</span>&nbsp;&nbsp;<span style="font-size:min(3vw,3vh);color:red;">Carro: #' . $sf['equipe_id'] . '</span></br>';
					}
				?>
			</div>
	</body>
</html>
