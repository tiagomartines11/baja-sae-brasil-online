<?php
$evt = $_REQUEST['evt'];
$cpf = $_REQUEST['cpf'];


    if ($_POST['cpf']) {
       $cpf = $_REQUEST['cpf'];
	   $cpf2 = dechex($cpf);
    } else {
       $cpf2 = $_REQUEST['cpf'];
	   $cpf = hexdec($cpf);
    };

//TODO: use Propel properly
$servername = "localhost";
$username = $manager->getConfiguration()['user'];
$password = $manager->getConfiguration()['password'];
$dbname = "baja_resultados";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);
$conn->set_charset("utf8");

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
} 
//echo "Connected successfully";

$sql = "SELECT * FROM participantes WHERE cpf = '$cpf' AND evento = '$evt'";

$result = $conn->query($sql);
	if ($result->num_rows > 0) {
		// output data of each row
		while($row = $result->fetch_assoc()) {
			$nome =  $row["nome"];
			$funcao =  $row["funcao"];
		}
	} else {
		echo "Certificado não encontrado";
		exit();
	}

$sql = "SELECT * FROM evento WHERE evento_id = '$evt'";
$result = $conn->query($sql);	
	if ($result->num_rows > 0) {
		// output data of each row
		while($row = $result->fetch_assoc()) {
			$evento =  $row["nome"];
			$local =  $row["local"];
			$presidente =  $row["presidente"];
			$data =  $row["data"];
			$mandatoPresidente =  $row["mandato_presidente"];
	
		}
	} else {
		echo "ERRO";
		exit();
	}
	
// echo $cpf;
// echo "<br>";	
// echo $nome;
// echo "<br>";
// echo $evento;	
// echo "<br>";
// echo $local;
// echo "<br>";
// echo $presidente;
// echo "<br>";
// echo $data;
// echo "<br>";
// echo $mandatoPresidente;
$conn->close();
//exit();


if($funcao == "competidor"){
	$texto = "Participou da <b>".$evento."</b>, ".$local.", no período de <b>".$data."</b>.";
}else if($funcao == "comissario"){
	$texto = "Realizou trabalho voluntário na organização da <b>".$evento."</b>, ".$local.", no período de <b>".$data."</b> na função de <b>COMISSÁRIO</b>.";
}else if($funcao == "juiz"){
	$texto = "Realizou trabalho voluntário na organização da <b>".$evento."</b>, ".$local.", no período de <b>".$data."</b> na função de <b>JUIZ</b>.";
}else if($funcao == "comite"){
	$texto = "Realizou trabalho voluntário na organização da <b>".$evento."</b>, ".$local.", no período de <b>".$data."</b> na função de <b>COMISSÃO TÉCNICA</b>.";
}else if($funcao == "engenheiro"){
	$texto = "Realizou trabalho voluntário na organização da <b>".$evento."</b>, ".$local.", no período de <b>".$data."</b> na função de <b>ENGENHEIRO</b>.";
}else if($funcao == "orientador"){
	$texto = "Participou da <b>".$evento."</b>, ".$local.", no período de <b>".$data."</b> na função de <b>PROFESSOR ORIENTADOR</b>.";
}else if($funcao == "assessor"){
	$texto = "Realizou trabalho voluntário na organização da <b>".$evento."</b>, ".$local.", no período de <b>".$data."</b> na função de <b>ASSESSOR TÉCNICO</b>.";
}


$html = "<html>
	<head>
		<meta charset='utf-8' />
		<style type=\'text/css\'>
			@page { margin: 0px;}
		</style>
	</head>	
	<body style='font-family: Arial; margin: 0; padding: 0; font-size: 20px;'>
		<div style = 'text-align: center; background-image: url(\"img/certificado.png\"); width:100%; height:97%'>
			<br><br><br><br><br><br><br><br><br><br><br><br>
			<div style = 'font-size:24px'>A <b>SAE BRASIL</b> certifica que</div>
			<br>
			<div style = 'font-size:36px; text-transform: uppercase;margin: 0 75px'><b>".$nome."</b></div>
			<br>
			<div style = 'font-size:20px; margin: 0 100px'>".$texto."
				
				<br><br>
			</div>	
			<div style = 'font-size:20px; margin: 0 100px'>			
				".$presidente."<br>
				Presidente <b>SAE BRASIL ".$mandatoPresidente."</b>
				<br><br>
			</div>	
			<div style = 'font-size:16px; margin: 0 250px'>
				Este documento eletrônico dispensa carimbo e assinatura.<br>Sua autenticidade pode ser comprovada acessando a seguinte página: <br>
				<a href=\"http://certificado.bajasaebrasil.online/c/".$evt."/".$cpf2."\">http://certificado.bajasaebrasil.online/c/".$evt."/".$cpf2."</a>
			</div>
		</div>
	</body>
</html>";

//echo $html;
//exit();

//$html = "oi";

// reference the Dompdf namespace
use Dompdf\Dompdf;

// instantiate and use the dompdf class
$dompdf = new Dompdf();
$dompdf->loadHtml($html);

// (Optional) Setup the paper size and orientation
$dompdf->setPaper('A4', 'landscape');

// Render the HTML as PDF
$dompdf->render();

// Output the generated PDF to Browser
$dompdf->stream('certificado_'.$cpf2.'.pdf',array("Attachment"=>0));
?>