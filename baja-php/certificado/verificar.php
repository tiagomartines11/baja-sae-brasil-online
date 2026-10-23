<?php
/**
 * /verificar/{token}      — HTML confirmation page for one certificate
 * /verificar/{token}/pdf  — the certificate itself
 *
 * Both are routed here by nginx, which passes the token as a query parameter.
 *
 * The page is HTML rather than the PDF because most traffic here is somebody
 * checking a certificate they already hold — an employer, a scholarship
 * committee, a QR scan — and answering that with a dompdf run costs more than
 * everything else this system does put together.
 */

use Baja\Certificado\Certificado;
use Baja\Certificado\Http;
use Baja\Certificado\Pdf;
use Baja\Certificado\Template;

Http::sendPrivateHeaders();

$token        = (string) ($_GET['token'] ?? '');
$wantsPdf     = ($_GET['format'] ?? '') === 'pdf';
$certificado  = Certificado::fromToken($token);

if ($certificado === null) {
    // Malformed token, unknown token, orphaned row: one answer for all three.
    http_response_code(404);
    Template::printNotFound();
    exit;
}

if ($wantsPdf) {
    $pdf = Pdf::render($certificado);
    header('Content-Type: application/pdf');
    // The token, never the document number. The old filename was
    // certificado_{cpf-in-hex}.pdf, which put a recoverable CPF in every
    // Downloads folder and every forwarded mail attachment.
    header('Content-Disposition: inline; filename="' . $certificado->getPdfFilename() . '"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

Template::printHeader('Certificado válido - SAE BRASIL');
?>
    <div class="card">
        <h1>Certificado válido</h1>
        <p class="valid">Este certificado foi emitido pela SAE BRASIL e consta em nossos registros.</p>

        <dl>
            <dt>Nome</dt>
            <dd><?= htmlspecialchars($certificado->getNome(), ENT_QUOTES, 'UTF-8') ?></dd>

            <?php if ($certificado->getFuncaoLabel() !== ''): ?>
                <dt>Participação</dt>
                <dd><?= htmlspecialchars($certificado->getFuncaoLabel(), ENT_QUOTES, 'UTF-8') ?></dd>
            <?php endif; ?>

            <dt>Evento</dt>
            <dd><?= htmlspecialchars($certificado->getEventoNome(), ENT_QUOTES, 'UTF-8') ?></dd>

            <?php if ($certificado->getLocal() !== ''): ?>
                <dt>Local</dt>
                <dd><?= htmlspecialchars($certificado->getLocal(), ENT_QUOTES, 'UTF-8') ?></dd>
            <?php endif; ?>

            <?php if ($certificado->getData() !== ''): ?>
                <dt>Data</dt>
                <dd><?= htmlspecialchars($certificado->getData(), ENT_QUOTES, 'UTF-8') ?></dd>
            <?php endif; ?>
        </dl>
    </div>

    <div class="card">
        <h2>Baixar o certificado</h2>
        <p>
            <a class="btn" href="<?= htmlspecialchars('/verificar/' . $certificado->getToken() . '/pdf', ENT_QUOTES, 'UTF-8') ?>">Baixar em PDF</a>
        </p>
    </div>
<?php
/*
 * Nothing below lists the participant's other certificates, and that is
 * deliberate rather than unfinished. This token authorizes one row. Whoever
 * holds a certificate for one event has no claim on the person's participation
 * history, and a page that showed siblings would turn a single leaked token
 * into a profile.
 *
 * The document number does not appear here either — not masked, not partial,
 * not in an attribute.
 */
Template::printFooter();
