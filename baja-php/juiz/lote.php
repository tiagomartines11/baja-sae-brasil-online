<?php

namespace Baja\Juiz;

use Baja\Certificado\Funcao;
use Baja\Certificado\Insercao\Acesso;
use Baja\Certificado\Insercao\Csrf;
use Baja\Certificado\Insercao\Gravador;
use Baja\Certificado\Insercao\Template;
use Baja\Certificado\Insercao\Texto;
use Baja\Certificado\Token;
use Baja\Model\EventoQuery;

/**
 * One batch: what it created, and the way to undo it.
 *
 * "Certificate rows are never deleted" protects certificates that have been
 * issued to people. A batch pasted in error two minutes ago is not that, and
 * leaving it in place makes the mistake permanent — the operator's only
 * alternative would be deleting rows by hand from a list they have to
 * reconstruct.
 *
 * So deletion exists, and is deliberately not comfortable. There is no
 * automatic rollback and no delete button next to the success message. It
 * lives on this page, behind a confirmation that names the event and the
 * count, so that the thing being destroyed is described before it is
 * destroyed.
 */

$usuario = Acesso::exigir();

const FORMULARIO = 'certificado-lote-apagar';

$id          = Texto::escalar($_GET['id'] ?? $_POST['id'] ?? '');
$apagado     = 0;
$erroCsrf    = false;
$semConfirmar = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Texto::escalar($_POST['acao'] ?? '') === 'apagar') {
    if (!Csrf::postValido(FORMULARIO)) {
        $erroCsrf = true;
    } elseif (Texto::escalar($_POST['confirmo'] ?? '') === '') {
        // The checkbox is `required`, which is a hint to a browser and nothing
        // to a request. The deliberate act has to be checked where it matters.
        $semConfirmar = true;
    } else {
        $apagado = Gravador::apagarLote($id);
    }
}

$linhas = Gravador::linhasDoLote($id);

$eventos = [];
foreach (EventoQuery::create()->find() as $evento) {
    $eventos[(string) $evento->getEventoId()] = html_entity_decode(
        (string) $evento->getNome(),
        ENT_QUOTES | ENT_HTML5,
        'UTF-8'
    );
}

$doLote = [];
foreach ($linhas as $linha) {
    $doLote[(string) $linha->getEventoId()] = true;
}

Template::printHeader('Lote de certificados', $usuario);

$e = fn(string $v): string => Template::e($v);
?>

<?php if ($erroCsrf): ?>
    <div class="alerta erro">
        A sessão do formulário expirou e nada foi apagado.
    </div>
<?php endif; ?>

<?php if ($semConfirmar): ?>
    <div class="alerta erro">
        Nada foi apagado: a confirmação não foi marcada.
    </div>
<?php endif; ?>

<?php if ($apagado > 0): ?>
    <div class="alerta ok">
        <strong><?= $apagado ?> certificado<?= $apagado === 1 ? '' : 's' ?>
        apagado<?= $apagado === 1 ? '' : 's' ?>.</strong>
        Os tokens desses certificados deixam de existir: quem tiver o endereço de
        verificação de algum deles vai receber a mesma página de "não encontrado"
        de um token inventado.
    </div>
<?php endif; ?>

<div class="card">
    <h1>Lote de certificados</h1>

    <?php if (!Token::isWellFormed($id)): ?>
        <p>Identificador de lote inválido.</p>
        <p><a class="btn" href="certificados_lote.php">Inserção em lote</a></p>
    <?php elseif ($linhas === []): ?>
        <p>
            Não há nenhum certificado no lote <code><?= $e($id) ?></code>.
            <?= $apagado > 0 ? 'Ele acabou de ser apagado.' : 'Ou ele nunca existiu, ou já foi apagado.' ?>
        </p>
        <p><a class="btn" href="certificados_lote.php">Inserção em lote</a></p>
    <?php else: ?>
        <dl>
            <dt>Lote</dt>
            <dd><code><?= $e($id) ?></code></dd>

            <dt>Certificados</dt>
            <dd><?= count($linhas) ?></dd>

            <dt>Evento<?= count($doLote) === 1 ? '' : 's' ?></dt>
            <dd>
                <?php foreach (array_keys($doLote) as $codigo): ?>
                    <div><?= $e($codigo) ?> — <?= $e($eventos[$codigo] ?? '') ?></div>
                <?php endforeach; ?>
            </dd>

            <?php
            $criadoEm = $linhas[0]->getCriadoEm();
            if ($criadoEm !== null):
            ?>
                <dt>Criado em</dt>
                <dd><?= $e($criadoEm->format('d/m/Y H:i')) ?></dd>
            <?php endif; ?>
        </dl>

        <table style="margin-top: 20px;">
            <thead>
                <tr><th>Nome</th><th>Função</th><th>Documento</th><th>Evento</th><th>Certificado</th></tr>
            </thead>
            <tbody>
                <?php foreach ($linhas as $linha): ?>
                    <tr>
                        <td><?= $e((string) $linha->getNome()) ?></td>
                        <td><?= $e(Funcao::label((string) $linha->getFuncao())) ?></td>
                        <td><?= $e((string) ($linha->getCpf() ?: $linha->getDocumentoEstrangeiro())) ?></td>
                        <td><?= $e((string) $linha->getEventoId()) ?></td>
                        <td><code><?= $e((string) $linha->getToken()) ?></code></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php if ($linhas !== []): ?>
<div class="card">
    <h2>Apagar este lote</h2>
    <p>
        Isto apaga <strong><?= count($linhas) ?>
        certificado<?= count($linhas) === 1 ? '' : 's' ?></strong>
        de <strong><?= $e(implode(', ', array_keys($doLote))) ?></strong>.
    </p>
    <p class="muted">
        Use isto para desfazer uma colagem que saiu errada, e só para isso. Se
        algum destes certificados já foi entregue a alguém, apagá-lo quebra o
        endereço de verificação impresso nele, e não há como recriá-lo com o
        mesmo token.
    </p>
    <form method="post" action="lote.php"
          onsubmit="return confirm('Apagar <?= count($linhas) ?> certificado(s) de <?= $e(implode(', ', array_keys($doLote))) ?>?');">
        <?= Csrf::campo(FORMULARIO) ?>
        <input type="hidden" name="id" value="<?= $e($id) ?>" />
        <input type="hidden" name="acao" value="apagar" />
        <label style="font-weight: normal; margin-bottom: 12px;">
            <input type="checkbox" name="confirmo" required />
            Entendi que <?= count($linhas) ?>
            certificado<?= count($linhas) === 1 ? '' : 's' ?>
            <?= count($linhas) === 1 ? 'será apagado' : 'serão apagados' ?>
            e que isso não pode ser desfeito.
        </label>
        <button type="submit" class="perigo">Apagar o lote</button>
    </form>
</div>
<?php endif; ?>

<?php Template::printFooter();
