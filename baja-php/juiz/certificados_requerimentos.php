<?php

namespace Baja\Juiz;

use Baja\Certificado\Certificado;
use Baja\Certificado\Documento;
use Baja\Certificado\Insercao\Acesso;
use Baja\Certificado\Insercao\Csrf;
use Baja\Certificado\Insercao\Template;
use Baja\Certificado\Insercao\Texto;
use Baja\Certificado\Requerimento\Caso;
use Baja\Model\CertificadoRequerimento;
use Baja\Model\CertificadoRequerimentoQuery;
use Baja\Model\EventoQuery;
use Baja\Model\UserQuery;
use Baja\Url;
use Propel\Runtime\ActiveQuery\Criteria;

/**
 * What participants have reported about their certificates.
 *
 * The queue behind /requerimento. The notification email is a pointer to a row
 * here and carries none of its contents, which is the whole reason the report
 * is stored rather than mailed: this page is behind the `certificados`
 * permission, the mailbox of everyone who holds it is not.
 *
 * Nothing on this page changes a certificate. It records a decision about a
 * report — looked at, acted on, refused — and the acting itself happens on
 * the insertion pages, which keep the audit trail that matters in
 * `participantes`. Keeping the two apart is what stops a queue of unverified
 * claims from becoming a way to edit the register.
 *
 * A query string carries only the report id, which is 16 random bytes and
 * says nothing about a person. The document number never reaches a URL here,
 * the same rule certificados_busca.php follows by posting its searches.
 */

$usuario = Acesso::exigir();

const FORMULARIO = 'certificado-requerimento';

/** The status values, in the order a requerimento moves through them. */
const STATUS = ['aberto', 'em_analise', 'resolvido', 'recusado'];

/**
 * The two that end it, and so the two that owe an explanation.
 *
 * "Em análise" is not one of them: it says somebody picked this up, not that
 * anything was decided.
 */
const FECHADOS = ['resolvido', 'recusado'];

const STATUS_ROTULOS = [
    'aberto'     => 'Aberto',
    'em_analise' => 'Em análise',
    'resolvido'  => 'Resolvido',
    'recusado'   => 'Recusado',
];

/** How long a resolution note may be. Matches the column. */
const RESOLUCAO_MAX = 1000;

$id      = Texto::escalar($_GET['id'] ?? '');
$filtro  = Texto::escalar($_GET['status'] ?? 'aberto');
$erro    = '';
$salvo   = false;

if ($filtro !== 'todos' && !in_array($filtro, STATUS, true)) {
    $filtro = 'aberto';
}

$requerimento = $id !== '' ? CertificadoRequerimentoQuery::create()->findPk($id) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::postValido(FORMULARIO)) {
        // Never act on it, and never explain more than this.
        $erro = 'Sessão expirada. Recarregue a página e tente de novo.';
    } elseif ($requerimento === null) {
        $erro = 'Requerimento não encontrado.';
    } else {
        $novoStatus = Texto::escalar($_POST['status'] ?? '');
        $resolucao  = Texto::limpar(Texto::escalar($_POST['resolucao'] ?? ''));

        if (!in_array($novoStatus, STATUS, true)) {
            $erro = 'Escolha uma situação.';
        } elseif (in_array($novoStatus, FECHADOS, true) && $resolucao === '') {
            /*
             * Only the two that end the requerimento have to say why. Six
             * months later the question is never "was it closed" but "what was
             * decided", and a closed row with no sentence answers the wrong
             * one.
             *
             * "Em análise" is exempt, and asking there was a mistake: it means
             * "I have picked this up", which somebody ticks in passing, before
             * they have looked into anything and so before there is anything
             * to write. A note demanded at that moment is a note nobody means,
             * and the cost of it is that people skip the status instead —
             * which loses the one signal that stops two people working the
             * same requerimento.
             */
            $erro = 'Escreva o que foi decidido antes de encerrar o requerimento.';
        } elseif (mb_strlen($resolucao, 'UTF-8') > RESOLUCAO_MAX) {
            $erro = 'A anotação passa de ' . RESOLUCAO_MAX . ' caracteres.';
        } else {
            $requerimento->setStatus($novoStatus);
            $requerimento->setResolucao($resolucao !== '' ? $resolucao : null);

            if (in_array($novoStatus, FECHADOS, true)) {
                $requerimento->setResolvidoPor((int) $usuario->getUserId());
                $requerimento->setResolvidoEm(new \DateTime());
            } else {
                /*
                 * Reopening, or moving to "em análise", clears the attribution
                 * rather than leaving the last person to close it named
                 * against a requerimento that is open again.
                 *
                 * The column is `resolvido_por` and it means what it says.
                 * Reusing it for "who picked this up" would put a name and a
                 * timestamp on the page under "Fechado por", about a
                 * requerimento nobody has closed.
                 */
                $requerimento->setResolvidoPor(null);
                $requerimento->setResolvidoEm(null);
            }

            $requerimento->save();
            $salvo = true;
        }
    }
}

$e = fn (string $v): string => Template::e($v);

/** The event in words, from whichever column holds it. */
$descreverEvento = static function (CertificadoRequerimento $r): string {
    $codigo = $r->getEventoId();

    if ($codigo !== null && $codigo !== '') {
        $evento = EventoQuery::create()->findPk($codigo);

        if ($evento !== null) {
            return html_entity_decode(
                (string) ($evento->getTitulo() ?: $evento->getNome()),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );
        }

        return $codigo;
    }

    $texto = (string) $r->getEventoTexto();

    return $texto !== '' ? $texto . ' (informado pelo participante)' : 'não informado';
};

Template::printHeader($requerimento !== null ? 'Requerimento de participante' : 'Requerimentos de participantes', $usuario);
?>

<?php if ($salvo): ?>
    <div class="alerta ok"><strong>Situação atualizada.</strong></div>
<?php endif; ?>

<?php if ($erro !== ''): ?>
    <div class="alerta erro"><strong><?= $e($erro) ?></strong></div>
<?php endif; ?>

<?php if ($id !== '' && $requerimento === null): ?>
    <div class="card">
        <h1>Requerimento não encontrado</h1>
        <p>
            O link pode ser antigo, ou o requerimento pode ter sido removido pela
            política de retenção.
        </p>
        <p><a class="btn" href="certificados_requerimentos.php">Ver a fila</a></p>
    </div>
<?php elseif ($requerimento !== null): ?>
    <?php
    $token        = (string) $requerimento->getToken();
    $certificado  = $token !== '' ? Certificado::fromToken($token) : null;
    $resolvidoPor = $requerimento->getResolvidoPor() !== null
        ? UserQuery::create()->findPk($requerimento->getResolvidoPor())
        : null;
    ?>
    <div class="card">
        <h1><?= $e(Caso::resumo((string) $requerimento->getCaso())) ?></h1>
        <p class="muted">
            Protocolo <code><?= $e((string) $requerimento->getRequerimentoId()) ?></code> ·
            recebido em <?= $e((string) $requerimento->getCriadoEm('d/m/Y H:i')) ?> ·
            <?= $e(STATUS_ROTULOS[(string) $requerimento->getStatus()] ?? (string) $requerimento->getStatus()) ?>
        </p>
        <p><?= $e(Caso::rotulo((string) $requerimento->getCaso())) ?></p>

        <?php if ($requerimento->getAvisadoEm() === null): ?>
            <p class="alerta aviso">
                O aviso por e-mail deste requerimento não foi enviado. Ele chegou até
                aqui, mas ninguém foi notificado, então vale conferir a configuração
                de SMTP.
            </p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Quem reportou</h2>
        <dl>
            <dt>Nome informado</dt>
            <dd><?= $e((string) $requerimento->getNome()) ?></dd>
            <dt>Documento informado</dt>
            <dd>
                <span class="doc-longo"><?= $e((string) $requerimento->getDocumento()) ?></span>
                <span class="doc-curto"><?= $e(Documento::mascarar((string) $requerimento->getDocumento())) ?></span>
            </dd>
            <dt>E-mail</dt>
            <dd><a href="mailto:<?= $e((string) $requerimento->getEmail()) ?>"><?= $e((string) $requerimento->getEmail()) ?></a></dd>
            <?php if ($requerimento->getTelefone() !== null && $requerimento->getTelefone() !== ''): ?>
                <dt>Telefone</dt>
                <dd><?= $e((string) $requerimento->getTelefone()) ?></dd>
            <?php endif; ?>
            <dt>Evento</dt>
            <dd><?= $e($descreverEvento($requerimento)) ?></dd>
        </dl>
        <p class="muted" style="margin-top:16px">
            Estes dados foram digitados pelo participante e não estão conferidos. É o que
            há para localizar o registro, não uma confirmação de identidade.
        </p>
    </div>

    <?php if ($token !== ''): ?>
        <div class="card">
            <h2>Certificado apontado</h2>
            <?php if ($certificado !== null): ?>
                <dl>
                    <dt>Nome no registro</dt>
                    <dd><?= $e($certificado->getNome()) ?></dd>
                    <dt>Evento</dt>
                    <dd><?= $e($certificado->getEventoNome()) ?></dd>
                    <?php if ($certificado->getFuncaoLabel() !== ''): ?>
                        <dt>Participação</dt>
                        <dd><?= $e($certificado->getFuncaoLabel()) ?></dd>
                    <?php endif; ?>
                    <dt>Token</dt>
                    <dd>
                        <a href="<?= $e(Url::subdomain('certificado', '/verificar/' . $token)) ?>"
                           target="_blank" rel="noreferrer"><code><?= $e($token) ?></code></a>
                    </dd>
                </dl>
                <p style="margin-top:16px">
                    <a class="btn btn-secondary" href="certificados_busca.php">Buscar para anular ou corrigir</a>
                    <a class="btn btn-secondary" href="certificados_nome.php">Corrigir um nome</a>
                </p>
            <?php else: ?>
                <?php /*
                 * A report can outlive its certificate: the row carries no
                 * foreign key, deliberately, so that deleting a bad lote does
                 * not destroy the reports that prompted it. Saying so is the
                 * honest rendering — the alternative is a blank panel that
                 * reads as a bug.
                 */ ?>
                <p class="alerta aviso">
                    O certificado <code><?= $e($token) ?></code> não existe mais, ou foi anulado.
                    O requerimento é anterior a isso.
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2>O que o participante escreveu</h2>
        <?php /*
         * Printed as typed, escaped, with the line breaks preserved — a
         * report reads as paragraphs and running it together loses the shape
         * of it. white-space: pre-wrap rather than nl2br so that no markup is
         * introduced into a value that came from a request.
         */ ?>
        <p style="white-space:pre-wrap;overflow-wrap:anywhere;margin:0"><?= $e((string) $requerimento->getDescricao()) ?></p>
        <p class="muted" style="margin-top:16px">
            O formulário pede que dados pessoais sensíveis não sejam escritos aqui, mas
            nada impede que sejam. Se este texto trouxer algum, não copie para outro
            lugar. Responda ao participante pedindo que o envie por um canal combinado.
        </p>
    </div>

    <div class="card">
        <h2>Situação</h2>
        <?php if ($resolvidoPor !== null && $requerimento->getResolvidoEm() !== null): ?>
            <p class="muted">
                Fechado por <strong><?= $e((string) $resolvidoPor->getUsername()) ?></strong>
                em <?= $e((string) $requerimento->getResolvidoEm('d/m/Y H:i')) ?>.
            </p>
        <?php endif; ?>

        <form method="post" action="certificados_requerimentos.php?id=<?= urlencode((string) $requerimento->getRequerimentoId()) ?>">
            <?= Csrf::campo(FORMULARIO) ?>
            <div class="field">
                <label for="status">Situação</label>
                <select id="status" name="status">
                    <?php foreach (STATUS as $valor): ?>
                        <option value="<?= $e($valor) ?>"
                                <?= (string) $requerimento->getStatus() === $valor ? 'selected' : '' ?>>
                            <?= $e(STATUS_ROTULOS[$valor]) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="resolucao">O que foi decidido</label>
                <textarea id="resolucao" name="resolucao" rows="4"
                          maxlength="<?= RESOLUCAO_MAX ?>"><?= $e((string) $requerimento->getResolucao()) ?></textarea>
                <p class="muted" style="margin-top:6px">
                    Fica no registro do requerimento, não é enviado ao participante.
                    A resposta a ele continua sendo um e-mail seu.
                </p>
            </div>
            <button type="submit">Salvar</button>
            <a class="btn btn-secondary" href="certificados_requerimentos.php">Voltar para a fila</a>
        </form>
    </div>
<?php else: ?>
    <?php
    $consulta = CertificadoRequerimentoQuery::create();

    if ($filtro !== 'todos') {
        $consulta->filterByStatus($filtro);
    }

    // Oldest first. A queue read newest-first is a queue where the report
    // nobody wanted to answer never gets answered.
    $requerimentos = $consulta->orderByCriadoEm(Criteria::ASC)->limit(200)->find();
    $abertos  = CertificadoRequerimentoQuery::create()->filterByStatus('aberto')->count();
    ?>
    <div class="card">
        <h1>Requerimentos de participantes</h1>
        <p>
            O que chegou pelo formulário público de requerimento. Nenhum destes altera
            um certificado por si só, são pedidos de análise.
        </p>
        <p class="muted">
            <?= $abertos === 1 ? '1 requerimento aberto' : $abertos . ' requerimentos abertos' ?>.
        </p>
        <p class="voltar" style="margin:16px 0 0">
            <?php foreach (['aberto', 'em_analise', 'resolvido', 'recusado', 'todos'] as $opcao): ?>
                <?php if ($opcao === $filtro): ?>
                    <strong><?= $e($opcao === 'todos' ? 'Todos' : STATUS_ROTULOS[$opcao]) ?></strong>
                <?php else: ?>
                    <a href="certificados_requerimentos.php?status=<?= $e($opcao) ?>"><?= $e($opcao === 'todos' ? 'Todos' : STATUS_ROTULOS[$opcao]) ?></a>
                <?php endif; ?>
                <?= $opcao === 'todos' ? '' : '&emsp;&middot;&emsp;' ?>
            <?php endforeach; ?>
        </p>
    </div>

    <?php if (count($requerimentos) === 0): ?>
        <div class="card">
            <p class="muted">Nada aqui.</p>
        </div>
    <?php else: ?>
        <div class="card">
            <table class="cartoes">
                <thead>
                    <tr>
                        <th>Recebido</th>
                        <th>Tipo</th>
                        <th>Nome informado</th>
                        <th>Evento</th>
                        <th>Situação</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requerimentos as $linha): ?>
                        <?php /*
                         * data-rotulo is what the narrow layout prints above
                         * each value once the header row is gone — see the
                         * .cartoes rules in Insercao\Template. The title cell
                         * carries none, because a card headed "Nome informado"
                         * above the name is a label nobody needed.
                         */ ?>
                        <tr>
                            <td data-rotulo="Recebido"><?= $e((string) $linha->getCriadoEm('d/m/Y H:i')) ?></td>
                            <td data-rotulo="Tipo"><?= $e(Caso::resumo((string) $linha->getCaso())) ?></td>
                            <td class="cartao-titulo">
                                <a href="certificados_requerimentos.php?id=<?= urlencode((string) $linha->getRequerimentoId()) ?>">
                                    <?= $e((string) $linha->getNome()) ?>
                                </a>
                            </td>
                            <td data-rotulo="Evento"><?= $e($descreverEvento($linha)) ?></td>
                            <td data-rotulo="Situação"><?= $e(STATUS_ROTULOS[(string) $linha->getStatus()] ?? (string) $linha->getStatus()) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php
Template::printFooter();
