<?php
/**
 * Legacy certificate route. Resolves nothing.
 *
 * Everything that used to be here — the participant lookup, the role-
 * conditional copy, the dompdf render — now lives in Baja\Certificado and is
 * reached by token. What is left is a redirect, because this file is still the
 * target of three things:
 *
 *   1. /c/{evt}/{cpf-hex}, the URL printed on every certificate ever issued.
 *      Those PDFs are in people's hands and must keep leading somewhere useful
 *      indefinitely. There is no sunset date.
 *   2. POST to any /c/{a}/{b}, which is how the old form submitted: evt and
 *      cpf travelled in the body, in decimal, and $_REQUEST let them override
 *      the path segments, so "novo/certificado" was decorative.
 *   3. /certificado.php?evt=…&cpf=… directly. The vhost passes any .php to
 *      FPM, so the rewrite was a convenience and never a gate. Redirecting
 *      only /c/ would have left the CPF-to-name oracle answering here.
 *
 * nginx redirects the /c/ shape before it reaches PHP, so in normal operation
 * no CPF is handed to this process at all. This redirect is the backstop for
 * the other two shapes, and for the day somebody edits the vhost.
 *
 * The CPF is deliberately not passed on to /buscar, in a query parameter or
 * anywhere else. Prefilling the form would put it straight back into a URL,
 * and auto-resolving would preserve the oracle this work package removes. Two
 * steps for a human is the price.
 */

header('Location: /buscar', true, 302);
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
exit;
