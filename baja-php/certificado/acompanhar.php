<?php
/**
 * /acompanhar?p={protocolo} — where a report stands (BAJAAPP-88).
 *
 * The link is in the receipt, on the page shown after submitting, and in the
 * email sent when the report is closed. Holding it is the only credential:
 * the protocol is 16 random bytes, the vhost has no session, and the people
 * who use this are not users of anything. See
 * Baja\Certificado\Requerimento\Acompanhamento.
 *
 * Because anybody holding the link sees the page, it shows what the receipt
 * already said and the outcome, and nothing else:
 *
 *   shown   the protocol, the type of report, the event, when it arrived,
 *           the status in words, when it was closed, and, once closed, the
 *           reply staff wrote for the participant
 *   never   the name, the document, the email, the phone, the description,
 *           or the internal note — the same rule Aviso's header explains for
 *           every email this system sends about a report
 *
 * A duplicate shows its own row: its own status and its own reply. Usually
 * those are the original's, because staff close them together; when they
 * did not, this row is the one this person filed, and the original is
 * somebody else's.
 *
 * An unknown protocol and a malformed one get the same 404 page, so that
 * nobody can learn from outside which protocols exist.
 */

use Baja\Certificado\Http;
use Baja\Certificado\Insercao\Texto;
use Baja\Certificado\Requerimento\Acompanhamento;
use Baja\Certificado\Requerimento\Aviso;
use Baja\Certificado\Requerimento\Caso;
use Baja\Certificado\Requerimento\Situacao;
use Baja\Certificado\Template;

Http::sendStatusPageHeaders();

$e = static fn (string $v): string => Template::e($v);

$requerimento = Acompanhamento::encontrar(Texto::escalar($_GET['p'] ?? ''));

if ($requerimento === null) {
    http_response_code(404);
    Template::printHeader('Protocolo não encontrado - Certificados SAE BRASIL');
    ?>
    <div class="card">
        <h1>Protocolo não encontrado</h1>
        <p>
            Confira se o link está completo, igual ao do e-mail de confirmação.
            Requerimentos encerrados há muito tempo também podem ter sido removidos
            pela política de retenção.
        </p>
        <p><a class="btn" href="/requerimento">Abrir um requerimento</a></p>
    </div>
    <?php
    Template::printFooter();
    exit;
}

$status   = (string) $requerimento->getStatus();
$fechado  = Situacao::fechada($status);
$resposta = (string) $requerimento->getResposta();

Template::printHeader('Acompanhar requerimento - Certificados SAE BRASIL');
?>
    <div class="card">
        <h1>Acompanhar requerimento</h1>
        <dl>
            <dt>Protocolo</dt>
            <dd><code><?= $e((string) $requerimento->getRequerimentoId()) ?></code></dd>
            <dt>Tipo</dt>
            <dd><?= $e(Caso::resumo((string) $requerimento->getCaso())) ?></dd>
            <dt>Evento</dt>
            <dd><?= $e(Aviso::descreverEvento($requerimento)) ?></dd>
            <dt>Recebido em</dt>
            <dd><?= $e((string) $requerimento->getCriadoEm('d/m/Y')) ?></dd>
            <dt>Situação</dt>
            <dd><strong><?= $e(Situacao::rotulo($status)) ?></strong></dd>
            <?php if ($fechado && $requerimento->getResolvidoEm() !== null): ?>
                <dt>Encerrado em</dt>
                <dd><?= $e((string) $requerimento->getResolvidoEm('d/m/Y')) ?></dd>
            <?php endif; ?>
        </dl>
    </div>

    <?php if ($fechado && $resposta !== ''): ?>
        <div class="card">
            <h2>Resposta da equipe</h2>
            <?php /* As written, escaped, with its line breaks — the same
                     rendering the staff page gives the participant's text. */ ?>
            <p style="white-space:pre-wrap;overflow-wrap:anywhere;margin:0"><?= $e($resposta) ?></p>
        </div>
    <?php elseif (!$fechado): ?>
        <div class="card">
            <?php if ($status === Situacao::EM_ANALISE): ?>
                <p>A equipe responsável pelos certificados já está analisando este requerimento.</p>
            <?php else: ?>
                <p>O requerimento está na fila e ainda não foi analisado.</p>
            <?php endif; ?>
            <p class="muted">
                Quando ele for encerrado, a resposta aparece aqui e também chega por
                e-mail, no endereço informado no requerimento. Não é preciso enviar de novo.
            </p>
        </div>
    <?php endif; ?>

    <div class="card">
        <p class="muted" style="margin:0">
            Nada muda em nenhum certificado enquanto um requerimento corre: certificados
            já emitidos continuam válidos e verificáveis.
        </p>
    </div>
<?php
Template::printFooter();
