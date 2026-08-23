<?php

namespace Baja\Certificado;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Renders a certificate to PDF.
 *
 * Lifted out of certificado/certificado.php, which resolved a participant and
 * rendered a document in one file. They are separate concerns now that a
 * certificate can be reached two ways and that verifying one no longer means
 * rendering it — dompdf is the most expensive thing this system does, and most
 * traffic to a certificate is somebody checking one they already hold.
 *
 * The mask, palette and layout are as SAE BRASIL supplied them and are not
 * ours to adjust.
 */
final class Pdf
{
    /** Web root of the certificado app — where img/certificado.png lives. */
    private const ASSET_ROOT = __DIR__ . '/../../../certificado';

    public static function render(Certificado $certificado): string
    {
        $options = new Options();
        $options->setChroot(realpath(self::ASSET_ROOT));
        $options->setIsRemoteEnabled(false);   // no HTTP fetches; keep it off
        $options->setIsHtml5ParserEnabled(true);

        $dompdf = new Dompdf($options);
        $dompdf->setBasePath(realpath(self::ASSET_ROOT) . '/');
        $dompdf->loadHtml(self::html($certificado));
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    private static function html(Certificado $certificado): string
    {
        $evento = $certificado->getEvento();

        $nome              = htmlspecialchars($certificado->getNome(), ENT_QUOTES, 'UTF-8');
        $texto             = $certificado->getTexto();
        $presidente        = htmlspecialchars((string) $evento->getPresidente(), ENT_QUOTES, 'UTF-8');
        $mandatoPresidente = htmlspecialchars((string) $evento->getMandatoPresidente(), ENT_QUOTES, 'UTF-8');

        // The URL the certificate promises can be used to confirm it. Until
        // /verificar existed this link handed back another copy of the very
        // file the reader was trying to check; the sentence around it needed
        // no change to become true.
        $url = htmlspecialchars($certificado->getVerificationUrl(), ENT_QUOTES, 'UTF-8');

        // Bottom left, in the empty region opposite the SAE International
        // seal, where it balances the layout instead of crowding it. Rendered
        // at ten pixels per module and scaled to 90pt here, so the module
        // edges stay hard when the sheet is printed.
        $qr = QrCode::dataUri($certificado->getVerificationUrl());

        return "<html>
	<head>
		<meta charset='utf-8' />
		<style type='text/css'>
			@page { margin: 0px;}
		</style>
	</head>
	<body style='font-family: Arial, sans-serif; margin: 0; padding: 0; font-size: 18px;'>
		<div style = 'text-align: center; background-image: url(\"img/certificado.png\"); width:100%; height:97%'>
			<br><br><br><br><br><br><br><br><br><br><br><br><br>
			<div style = 'font-size:24px'>A <b>SAE BRASIL</b> certifica que</div>
			<br>
			<div style = 'font-size:36px; text-transform: uppercase;margin: 0 75px'><b>" . $nome . "</b></div>
			<br>
			<div style = 'font-size:20px; margin: 0 100px'>" . $texto . "

				<br><br>
			</div>
			<div style = 'font-size:20px; margin: 0 100px'>
				" . $presidente . "<br>
				Presidente <b>SAE BRASIL " . $mandatoPresidente . "</b>
				<br><br>
			</div>
			<div style = 'font-size:16px; margin: 0 250px'>
				Este documento eletrônico dispensa carimbo e assinatura.<br>Sua autenticidade pode ser comprovada acessando a seguinte página: <br>
				<a href=\"" . $url . "\">" . $url . "</a>
			</div>
			<div style = 'position: absolute; left: 60px; bottom: 100px; text-align: center;'>
				<img src=\"" . $qr . "\" style='width: 90pt; height: 90pt;' alt='' />
			</div>
		</div>
	</body>
</html>";
    }
}
