<?php
/**
 * /buscar — find your certificates.
 *
 * GET renders the form. POST performs the lookup and renders the results in
 * the same response, deliberately without a redirect: the document number
 * cannot go in a URL, which is the entire point of this work package, and
 * stashing it in the session to support Post/Redirect/Get would be a lot of
 * machinery to avoid a resubmission prompt.
 *
 * This replaces the two-step flow where a participant picked an event and then
 * entered a CPF. One search now returns everything they hold, across every
 * event and every programme, so the event selector is gone.
 */

use Baja\Certificado\Backoff;
use Baja\Certificado\Busca;
use Baja\Certificado\Config;
use Baja\Certificado\Http;
use Baja\Certificado\Template;

Http::sendPrivateHeaders();

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

/*
 * Read straight from $_POST rather than $_REQUEST. bootstrap.php merges GET
 * and POST into $_REQUEST, and reading the document from something a URL can
 * populate is how it would end up in a URL again.
 *
 * Nothing in this file logs either field. The document number and the name
 * together are the credential.
 */
$documento = $isPost ? (string) ($_POST['documento'] ?? '') : '';
$nome      = $isPost ? (string) ($_POST['nome'] ?? '') : '';

$certificados = [];
$failed       = false;

if ($isPost) {
    /*
     * Count failures against the document rather than the caller. nginx limits
     * by IP; this limits guessing at the name behind one CPF, which is the
     * attack the design leaves open now that the CPF is out of the URL.
     *
     * A locked-out document takes the same path as a failed lookup and returns
     * the same bytes. Saying "too many attempts" would confirm that somebody
     * has been trying this document, which is the kind of answer this form
     * exists not to give.
     */
    if (Backoff::allows($documento)) {
        $certificados = Busca::run($documento, $nome);
    }

    $failed = $certificados === [];

    if ($failed) {
        Backoff::recordFailure($documento);
    } else {
        // Otherwise somebody who mistyped their name five times stays locked
        // out for fifteen minutes after finally getting it right.
        Backoff::clear($documento);
    }
}

Template::printHeader('Certificados - SAE BRASIL');
?>
    <div class="card">
        <h1>Certificados SAE BRASIL</h1>
        <p>
            Informe o número do seu documento e o seu nome completo para
            localizar os certificados emitidos em seu nome.
        </p>

        <form method="post" action="/buscar" autocomplete="off">
            <div class="field">
                <label for="documento">CPF ou passaporte</label>
                <?php /* "Passaporte", not "documento estrangeiro". The broader
                         wording invited foreign participants to enter their national
                         ID, which is not what is on file for them. The column behind
                         this stays permissive and the search is unchanged — this
                         narrows what is asked for, not what is accepted, so anyone
                         already on file under something else still resolves.

                         type="text", never type="number": a number input drops the
                         leading zero of a CPF that begins with one, and refuses the
                         punctuation people naturally type. Both forms are accepted
                         and normalized server-side. */ ?>
                <input type="text" id="documento" name="documento" inputmode="text"
                       maxlength="32" required
                       value="" />
            </div>

            <div class="field">
                <label for="nome">Nome completo</label>
                <input type="text" id="nome" name="nome" maxlength="300" required
                       value="" />
            </div>

            <button type="submit">Buscar certificados</button>
        </form>
    </div>

<?php if ($isPost && $failed): ?>
    <div class="card">
        <h2>Nenhum certificado encontrado</h2>
        <p><?= htmlspecialchars(Config::FAILURE_MESSAGE, ENT_QUOTES, 'UTF-8') ?></p>
        <p class="muted">
            Confira se o nome informado está completo e igual ao usado na
            inscrição do evento.
        </p>
    </div>
<?php endif; ?>

<?php if ($certificados !== []): ?>
    <div class="card">
        <h2><?= count($certificados) === 1 ? '1 certificado encontrado' : count($certificados) . ' certificados encontrados' ?></h2>
        <p class="muted">
            O nome exibido é o que consta em nossos registros para cada evento.
        </p>
    </div>

    <?php foreach ($certificados as $certificado): ?>
        <div class="card">
            <dl>
                <dt>Nome</dt>
                <?php /* As stored on this row, not as submitted. The same person is
                         sometimes on file under different spellings for different
                         events, and showing what the record says is what lets the
                         person best placed to report an error notice it. */ ?>
                <dd><?= htmlspecialchars($certificado->getNome(), ENT_QUOTES, 'UTF-8') ?></dd>

                <?php if ($certificado->getFuncaoLabel() !== ''): ?>
                    <dt>Participação</dt>
                    <dd><?= htmlspecialchars($certificado->getFuncaoLabel(), ENT_QUOTES, 'UTF-8') ?></dd>
                <?php endif; ?>

                <dt>Evento</dt>
                <dd><?= htmlspecialchars($certificado->getEventoNome(), ENT_QUOTES, 'UTF-8') ?></dd>

                <?php if ($certificado->getData() !== ''): ?>
                    <dt>Data</dt>
                    <dd><?= htmlspecialchars($certificado->getData(), ENT_QUOTES, 'UTF-8') ?></dd>
                <?php endif; ?>
            </dl>

            <p style="margin-top:16px">
                <?php /* Whoever reaches this page wants their file; the download leads. */ ?>
                <a class="btn" href="<?= htmlspecialchars('/verificar/' . $certificado->getToken() . '/pdf', ENT_QUOTES, 'UTF-8') ?>">Baixar em PDF</a>
                <a class="btn btn-secondary" href="<?= htmlspecialchars('/verificar/' . $certificado->getToken(), ENT_QUOTES, 'UTF-8') ?>">Página de verificação</a>
            </p>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
<?php
/*
 * The document number is not printed anywhere above, in any form.
 */
Template::printFooter();
