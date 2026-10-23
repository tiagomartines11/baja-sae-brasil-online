<?php

namespace Baja\Juiz;

use Baja\Certificado\Busca;
use Baja\Certificado\Funcao;
use Baja\Certificado\Insercao\Acesso;
use Baja\Certificado\Insercao\Csrf;
use Baja\Certificado\Insercao\Template;
use Baja\Certificado\Insercao\Texto;
use Baja\Certificado\Nome;
use Baja\Model\Map\ParticipanteTableMap;
use Baja\Model\Participante;
use DateTimeImmutable;
use Propel\Runtime\Propel;

/**
 * Correcting the name a person is on file under.
 *
 * The same operation the review screens offer as a resolution, on its own,
 * because it is also the thing somebody asks for by email: their certificate
 * says Joao and their name is João.
 *
 * What makes it worth a page of its own is that the stored name is not a
 * label on a record. It is what an already-issued certificate renders when it
 * is downloaded again, and what /verificar shows a verifier — so correcting
 * it rewrites artefacts that are already out in the world. Every screen here
 * shows which ones, and how many, before anything is written.
 */

$usuario = Acesso::exigir();

const FORMULARIO = 'certificado-nome';

$documento = Texto::limpar(Texto::escalar($_POST['documento'] ?? $_GET['documento'] ?? ''));
$novoNome  = Texto::normalizarEspacos(Texto::limpar(Texto::escalar($_POST['nome'] ?? '')));
$acao      = Texto::escalar($_POST['acao'] ?? '');

$linhas    = [];
$erros     = [];
$avisos    = [];
$aplicadas = null;
$erroCsrf  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Csrf::postValido(FORMULARIO)) {
    $erroCsrf = true;
    $acao     = '';
}

if ($documento !== '' && !$erroCsrf) {
    $linhas = Busca::rowsForDocument($documento);
}

// The rows the correction would actually rewrite: the ones whose stored name
// is not already the new one. Computed for both the preview and the write, so
// the number shown is the number that happens.
$afetadas = [];
$eventos  = [];
if ($novoNome !== '') {
    foreach ($linhas as $linha) {
        if (trim((string) $linha->getNome()) !== $novoNome) {
            $afetadas[] = $linha;
            $eventos[(string) $linha->getEventoId()] = true;
        }
    }
}

if ($novoNome !== '' && $acao !== '') {
    if (!Texto::utf8Valido($novoNome)) {
        $erros[] = 'O nome não está em UTF-8 e não pôde ser lido.';
    }

    $ruins = Texto::naoArmazenaveis($novoNome);
    if ($ruins !== []) {
        $erros[] = 'O nome contém caracteres que a base não armazena: '
            . implode(', ', array_map([Texto::class, 'descrever'], $ruins)) . '.';
    }

    if (mb_strlen($novoNome, 'UTF-8') > 300) {
        $erros[] = 'O nome passa de 300 caracteres.';
    }

    // The same minimum /buscar enforces. Renaming somebody to a single word
    // makes every certificate they hold unreachable from the search, which is
    // a worse outcome than the misspelling being corrected.
    if (count(Nome::parts($novoNome)) < 2) {
        $erros[] = 'O nome precisa de pelo menos dois nomes: a busca pública exige '
            . 'dois para devolver qualquer coisa, e um nome só deixaria estes '
            . 'certificados inacessíveis para quem os recebeu.';
    }

    // Not an error — a correction may legitimately be a different spelling
    // that no longer matches, e.g. a married name. But it should be said.
    if ($erros === [] && $afetadas !== []) {
        $reconhece = false;
        foreach ($afetadas as $linha) {
            if (Nome::matches($novoNome, trim((string) $linha->getNome()))) {
                $reconhece = true;
            }
        }
        if (!$reconhece) {
            $avisos[] = 'O nome novo não se parece com nenhum dos nomes registrados. '
                . 'Confira se o documento é o certo antes de aplicar.';
        }
    }
}

// The count the operator was shown, echoed back. Applying requires it, and
// requires it to still be right — which enforces the preview step and also
// catches the case where the rows changed between seeing the number and
// clicking: another operator adding a certificate for this person in between
// would otherwise have it renamed without anybody having seen it.
$visto = Texto::escalar($_POST['visto'] ?? '');
$conferido = $visto !== '' && (int) $visto === count($afetadas);

if ($acao === 'aplicar' && !$conferido && $erros === []) {
    $erros[] = 'Os registros mudaram desde a conferência. Confira de novo antes de aplicar.';
}

if ($acao === 'aplicar' && $conferido && $erros === [] && $afetadas !== []) {
    $agora = new DateTimeImmutable();
    $con   = Propel::getWriteConnection(ParticipanteTableMap::DATABASE_NAME);
    $con->beginTransaction();

    try {
        foreach ($afetadas as $linha) {
            $linha->setNome($novoNome);
            // The same audit fields an insert carries. A correction is an
            // assertion about a person as much as a creation is, and the row
            // is the only place its author survives.
            $linha->setCriadoPor((int) $usuario->getUserId());
            $linha->setCriadoEm($agora);
            $linha->save($con);
        }
        $con->commit();
    } catch (\Throwable $e) {
        $con->rollBack();

        throw $e;
    }

    $aplicadas = count($afetadas);
    $linhas    = Busca::rowsForDocument($documento);
    $afetadas  = [];
}

Template::printHeader('Corrigir um nome', $usuario);

$e = fn(string $v): string => Template::e($v);
?>

<?php if ($erroCsrf): ?>
    <div class="alerta erro">A sessão do formulário expirou e nada foi alterado.</div>
<?php endif; ?>

<?php if ($aplicadas !== null): ?>
    <div class="alerta ok">
        <strong><?= (int) $aplicadas ?> certificado<?= $aplicadas === 1 ? '' : 's' ?>
        atualizado<?= $aplicadas === 1 ? '' : 's' ?>.</strong>
        Quem baixar qualquer um deles agora recebe o nome novo.
    </div>
<?php endif; ?>

<div class="card">
    <h1>Corrigir um nome</h1>
    <p class="muted">
        Muda o nome em <strong>todos</strong> os certificados de uma pessoa, em todos
        os eventos. Para criar um certificado novo, use a
        <a href="certificados.php">inserção individual</a>.
    </p>

    <form method="post" action="certificados_nome.php">
        <?= Csrf::campo(FORMULARIO) ?>
        <div class="field">
            <label for="documento">CPF ou passaporte</label>
            <input type="text" id="documento" name="documento" required maxlength="32"
                   value="<?= $e($documento) ?>" />
        </div>
        <button type="submit" class="secundario">Procurar</button>
    </form>
</div>

<?php if ($documento !== '' && $linhas === []): ?>
    <div class="card">
        <p>Nenhum certificado registrado com esse documento.</p>
    </div>
<?php elseif ($linhas !== []): ?>
    <div class="card">
        <h2><?= count($linhas) ?> certificado<?= count($linhas) === 1 ? '' : 's' ?> neste documento</h2>
        <?php /* One card per certificate below 760px — see .cartoes in Template. */ ?>
        <table class="cartoes">
            <thead>
                <tr><th>Nome registrado</th><th>Evento</th><th>Função</th><th>Certificado</th></tr>
            </thead>
            <tbody>
                <?php foreach ($linhas as $linha): ?>
                    <tr>
                        <td class="cartao-titulo"><?= $e(trim((string) $linha->getNome())) ?></td>
                        <td data-rotulo="Evento"><?= $e((string) $linha->getEventoId()) ?></td>
                        <td data-rotulo="Função"><?= $e(Funcao::label((string) $linha->getFuncao())) ?></td>
                        <td data-rotulo="Certificado"><code><?= $e((string) $linha->getToken()) ?></code></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <form method="post" action="certificados_nome.php" style="margin-top: 24px;">
            <?= Csrf::campo(FORMULARIO) ?>
            <input type="hidden" name="documento" value="<?= $e($documento) ?>" />
            <div class="field">
                <label for="nome">Nome correto</label>
                <input type="text" id="nome" name="nome" required maxlength="300"
                       value="<?= $e($novoNome) ?>" />
                <p class="muted">
                    Gravado exatamente como digitado. A inserção ajusta nomes que
                    chegam todos em maiúsculas ou minúsculas; aqui não — é este o
                    lugar de acertar um acento ou uma grafia que a regra errou.
                </p>
            </div>

            <?php foreach ($erros as $erro): ?>
                <div class="alerta erro"><?= $e($erro) ?></div>
            <?php endforeach; ?>

            <?php foreach ($avisos as $aviso): ?>
                <div class="alerta aviso"><?= $e($aviso) ?></div>
            <?php endforeach; ?>

            <?php if ($novoNome !== '' && $acao !== '' && $erros === []): ?>
                <?php if ($afetadas === []): ?>
                    <div class="alerta">
                        Nenhum registro mudaria: o nome já está assim em todos eles.
                    </div>
                <?php else: ?>
                    <div class="alerta aviso">
                        <strong>Isto reescreve <?= count($afetadas) ?>
                        certificado<?= count($afetadas) === 1 ? '' : 's' ?>
                        já emitido<?= count($afetadas) === 1 ? '' : 's' ?></strong>
                        em <?= $e(implode(', ', array_keys($eventos))) ?>.
                        <p class="muted" style="margin: 8px 0 0;">
                            Quem já baixou um destes certificados tem uma cópia com o nome
                            antigo; a partir de agora o mesmo endereço de verificação
                            mostra o nome novo. O token de cada certificado não muda.
                        </p>
                        <?php /*
                            Three columns of names do not fit a phone, and this
                            is the table that says what is about to be
                            rewritten — the one place on the page where a
                            column running off the edge would matter most. Each
                            row becomes a labelled card below 760px; no field
                            leads, because "De" and "Para" only mean anything
                            next to their labels.
                        */ ?>
                        <table class="cartoes" style="margin-top: 12px;">
                            <thead><tr><th>De</th><th>Para</th><th>Evento</th></tr></thead>
                            <tbody>
                                <?php foreach ($afetadas as $linha): ?>
                                    <tr>
                                        <td data-rotulo="De"><?= $e(trim((string) $linha->getNome())) ?></td>
                                        <td data-rotulo="Para"><?= $e($novoNome) ?></td>
                                        <td data-rotulo="Evento"><?= $e((string) $linha->getEventoId()) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <button type="submit" name="acao" value="conferir" class="secundario">Conferir</button>
            <?php if ($novoNome !== '' && $acao !== '' && $erros === [] && $afetadas !== []): ?>
                <input type="hidden" name="visto" value="<?= count($afetadas) ?>" />
                <button type="submit" name="acao" value="aplicar">
                    Aplicar em <?= count($afetadas) ?>
                    certificado<?= count($afetadas) === 1 ? '' : 's' ?>
                </button>
            <?php endif; ?>
        </form>
    </div>
<?php endif; ?>

<?php Template::printFooter();
