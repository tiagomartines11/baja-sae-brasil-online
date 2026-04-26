<?php

use Baja\Model\EventoQuery;
use Baja\Model\ParticipanteQuery;
use Dompdf\Dompdf;
use Dompdf\Options;

$evt = $_REQUEST['evt'] ?? null;
$cpfRaw = $_REQUEST['cpf'] ?? null;

if (!empty($_POST['cpf'])) {
    // Form submission: CPF arrives as decimal digits.
    $cpf = $cpfRaw;
    $cpf2 = dechex((int)$cpf);
} else {
    // Routed URL /c/{evt}/{cpf-hex}: CPF arrives hex-encoded.
    $cpf2 = $cpfRaw;
    $cpf = hexdec($cpfRaw);
}

$participante = ParticipanteQuery::create()
    ->filterByEventoId($evt)
    ->filterByCpf($cpf)
    ->findOne();

if (!$participante) {
    echo "Certificado não encontrado";
    exit();
}

$evento = EventoQuery::create()->findPk($evt);

if (!$evento) {
    echo "ERRO";
    exit();
}

$nome = $participante->getNome();
$funcao = $participante->getFuncao();

$eventoNome        = $evento->getNome();
$local             = $evento->getLocal();
$presidente        = $evento->getPresidente();
$data              = $evento->getData();
$mandatoPresidente = $evento->getMandatoPresidente();
$cargaHoraria      = $evento->getCargaHoraria();

$cabecalho = "<b>" . $eventoNome . "</b>, " . $local . ", no período de <b>" . $data . "</b>";

switch ($funcao) {
    case "competidor":
        $texto = "Participou da " . $cabecalho;
        $texto .= $cargaHoraria
            ? ", com carga horária de " . (string)$cargaHoraria . " horas."
            : ".";
        break;
    case "comissario":
        $texto = "Realizou trabalho voluntário na organização da " . $cabecalho . " na função de <b>COMISSÁRIO</b>.";
        break;
    case "juiz":
        $texto = "Realizou trabalho voluntário na organização da " . $cabecalho . " na função de <b>JUIZ</b>.";
        break;
    case "comite":
        $texto = "Realizou trabalho voluntário na organização da " . $cabecalho . " na função de <b>COMISSÃO TÉCNICA</b>.";
        break;
    case "engenheiro":
        $texto = "Realizou trabalho voluntário na organização da " . $cabecalho . " na função de <b>ENGENHEIRO</b>.";
        break;
    case "orientador":
        $texto = "Participou da " . $cabecalho . " na função de <b>PROFESSOR ORIENTADOR</b>.";
        break;
    case "assessor":
        $texto = "Realizou trabalho voluntário na organização da " . $cabecalho . " na função de <b>ASSESSOR TÉCNICO</b>.";
        break;
    default:
        $texto = "";
}

$certUrl = \Baja\Url::subdomain('certificado', '/c/' . $evt . '/' . $cpf2);

$html = "<html>
	<head>
		<meta charset='utf-8' />
		<style type=\'text/css\'>
			@page { margin: 0px;}
		</style>
	</head>
	<body style='font-family: Arial, sans-serif; margin: 0; padding: 0; font-size: 18px;'>
		<div style = 'text-align: center; background-image: url(\"img/certificado.png\"); width:100%; height:97%'>
			<br><br><br><br><br><br><br><br><br><br><br><br><br>
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
				<a href=\"" . htmlspecialchars($certUrl, ENT_QUOTES, 'UTF-8') . "\">" . htmlspecialchars($certUrl, ENT_QUOTES, 'UTF-8') . "</a>
			</div>
		</div>
	</body>
</html>";

$options = new Options();
$options->setChroot(__DIR__);          // allow file access within /var/www/html/certificado/
$options->setIsRemoteEnabled(false);   // we don't need HTTP fetches; keep this off for safety
$options->setIsHtml5ParserEnabled(true);

$dompdf = new Dompdf($options);
$dompdf->setBasePath(__DIR__ . '/');

$dompdf->loadHtml($html);

$dompdf->setPaper('A4', 'landscape');

$dompdf->render();

$dompdf->stream('certificado_'.$cpf2.'.pdf',array("Attachment"=>0));
