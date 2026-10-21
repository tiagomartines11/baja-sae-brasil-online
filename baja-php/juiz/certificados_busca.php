<?php

namespace Baja\Juiz;

use Baja\Certificado\Documento;
use Baja\Certificado\Funcao;
use Baja\Certificado\Insercao\Acesso;
use Baja\Certificado\Insercao\Anulacao;
use Baja\Certificado\Insercao\Consulta;
use Baja\Certificado\Insercao\Csrf;
use Baja\Certificado\Insercao\Eventos;
use Baja\Certificado\Insercao\Template;
use Baja\Certificado\Insercao\Texto;
use Baja\Url;

/**
 * Looking up certificates that already exist.
 *
 * The counterpart to /buscar, and deliberately its opposite. That page is
 * public, so it demands a document and a name together and is rate-limited:
 * answering "did this person compete?" to anyone who asks is itself a
 * disclosure. This one is behind `certificados` and exists so that somebody
 * who is allowed to see the table can look through it.
 *
 * It posts rather than using query strings, and that is not a style choice. A
 * document number in a URL is a document number in the access log, which is
 * the thing the whole certificate work package was written to stop. The cost
 * is that a search cannot be bookmarked or pasted to a colleague.
 */

$usuario = Acesso::exigir();

const FORMULARIO = 'certificado-busca';

$eventos = new Eventos();

$eventosSel  = [];
$funcoesSel  = [];
$nome        = '';
$documento   = '';
$tipoDoc     = Consulta::DOC_AMBOS;
$estado      = Consulta::ESTADO_VALIDOS;
$pagina      = 1;
$erroCsrf    = false;
$erroNome    = '';
$consulta    = null;
$linhas      = [];
$total       = 0;

$acao          = '';
$selecionados  = [];
$aAnular       = [];
$motivo        = '';
$errosMotivo   = [];
$anuladas      = null;
$restauradas   = null;
$listaMudou    = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::postValido(FORMULARIO)) {
        $erroCsrf = true;
    } else {
        foreach (Texto::mapaDeTexto($_POST['eventos'] ?? []) as $codigo) {
            if ($eventos->existe($codigo)) {
                $eventosSel[] = $codigo;
            }
        }
        foreach (Texto::mapaDeTexto($_POST['funcoes'] ?? []) as $codigo) {
            if (Funcao::exists($codigo)) {
                $funcoesSel[] = $codigo;
            }
        }

        $acao   = Texto::escalar($_POST['acao'] ?? '');
        $motivo = Texto::limpar(Texto::escalar($_POST['motivo'] ?? ''));

        foreach (Texto::mapaDeTexto($_POST['tokens'] ?? []) as $token) {
            $selecionados[] = $token;
        }

        $estado = Texto::escalar($_POST['estado'] ?? Consulta::ESTADO_VALIDOS);
        if (!in_array($estado, Consulta::estados(), true)) {
            $estado = Consulta::ESTADO_VALIDOS;
        }

        $nome      = Texto::limpar(Texto::escalar($_POST['nome'] ?? ''));
        $documento = Texto::limpar(Texto::escalar($_POST['documento'] ?? ''));
        $tipoDoc   = Texto::escalar($_POST['tipo_documento'] ?? Consulta::DOC_AMBOS);
        $pagina    = max(1, (int) Texto::escalar($_POST['pagina'] ?? '1'));

        if (!in_array($tipoDoc, [Consulta::DOC_CPF, Consulta::DOC_PASSAPORTE, Consulta::DOC_AMBOS], true)) {
            $tipoDoc = Consulta::DOC_AMBOS;
        }

        // `nome` is latin1, and comparing a term against it that holds a
        // character latin1 cannot represent makes MySQL refuse the statement
        // outright rather than return nothing. Caught here so the answer is a
        // sentence instead of a 500 — and the answer is genuinely "no rows",
        // since no stored name can contain those characters either.
        $ruins = Texto::naoArmazenaveis($nome);
        if ($ruins !== []) {
            $erroNome = 'O nome buscado tem caracteres que não existem em nenhum nome registrado: '
                . implode(', ', array_map([Texto::class, 'descrever'], $ruins)) . '.';
        } else {
            // --- voiding, and undoing it -----------------------------------
            //
            // Two steps on purpose. 'anular'/'restaurar' only ever renders the
            // list about to change; the write happens on the confirmation,
            // which echoes back the count it displayed. That both enforces the
            // preview and catches a list that moved underneath — somebody else
            // voiding one of these in between would otherwise be silently
            // included in a number the operator never saw.
            if (in_array($acao, ['anular', 'restaurar', 'anular_confirmado', 'restaurar_confirmado'], true)) {
                $aAnular = Anulacao::linhas($selecionados);
            }

            if ($acao === 'anular' || $acao === 'anular_confirmado') {
                $errosMotivo = Anulacao::problemasDoMotivo($motivo);
            }

            $confirmado = (int) Texto::escalar($_POST['vistos'] ?? '-1');
            $listaMudou = str_ends_with($acao, '_confirmado') && $confirmado !== count($aAnular);

            if ($acao === 'anular_confirmado' && $errosMotivo === [] && !$listaMudou && $aAnular !== []) {
                $anuladas = (new Anulacao((int) $usuario->getUserId()))->anular($selecionados, $motivo);
            }

            if ($acao === 'restaurar_confirmado' && !$listaMudou && $aAnular !== []) {
                $restauradas = (new Anulacao((int) $usuario->getUserId()))->restaurar($selecionados);
            }

            $consulta = new Consulta($eventosSel, $funcoesSel, $nome, $documento, $tipoDoc, $estado);
            $total    = $consulta->total();
            $linhas   = $consulta->pagina($pagina);
        }
    }
}

Template::printHeader('Consultar certificados', $usuario);

$e = fn(string $v): string => Template::e($v);

/** The closed state of a multi-select: what is chosen, or that nothing is. */
$resumo = function (array $selecionados, string $vazio) use ($e): string {
    if ($selecionados === []) {
        return $e($vazio);
    }

    return count($selecionados) <= 3
        ? $e(implode(', ', $selecionados))
        : $e(count($selecionados) . ' selecionados');
};
?>

<?php if ($erroCsrf): ?>
    <div class="alerta erro">A sessão do formulário expirou. Faça a busca de novo.</div>
<?php endif; ?>

<?php if ($anuladas !== null): ?>
    <div class="alerta ok">
        <strong><?= (int) $anuladas ?> certificado<?= $anuladas === 1 ? '' : 's' ?>
        anulado<?= $anuladas === 1 ? '' : 's' ?>.</strong>
        <?= $anuladas === 1 ? 'Ele deixa' : 'Eles deixam' ?> de ser confirmado<?= $anuladas === 1 ? '' : 's' ?>
        em <code>/verificar</code> e de aparecer na busca pública. O registro
        continua aqui, com o motivo e quem anulou.
    </div>
<?php endif; ?>

<?php if ($restauradas !== null): ?>
    <div class="alerta ok">
        <strong><?= (int) $restauradas ?> certificado<?= $restauradas === 1 ? '' : 's' ?>
        restaurado<?= $restauradas === 1 ? '' : 's' ?>.</strong>
        <?= $restauradas === 1 ? 'Ele volta' : 'Eles voltam' ?> a ser confirmado<?= $restauradas === 1 ? '' : 's' ?>.
        O registro da anulação anterior não fica guardado.
    </div>
<?php endif; ?>

<?php if ($listaMudou): ?>
    <div class="alerta erro">
        A lista mudou desde a conferência e nada foi alterado. Confira de novo.
    </div>
<?php endif; ?>

<?php
// The confirmation step: exactly what is about to change, and nothing else on
// the page competing for attention.
// Also on a confirmation that did not go through — a missing or unusable
// reason, or a list that moved. Without this the error has nowhere to render
// and the operator sees the search results again as though they had never
// pressed the button.
$falhou = str_ends_with($acao, '_confirmado') && $anuladas === null && $restauradas === null;

$confirmando = in_array($acao, ['anular', 'restaurar'], true) || $falhou;
$restaurando = str_starts_with($acao, 'restaurar');
?>

<?php if ($confirmando && $aAnular !== []): ?>
<div class="card">
    <h1><?= $restaurando ? 'Restaurar' : 'Anular' ?>
        <?= count($aAnular) ?> certificado<?= count($aAnular) === 1 ? '' : 's' ?></h1>

    <?php if ($restaurando): ?>
        <p>
            <?= count($aAnular) === 1 ? 'Este certificado volta' : 'Estes certificados voltam' ?>
            a ser confirmado<?= count($aAnular) === 1 ? '' : 's' ?> em <code>/verificar</code>.
        </p>
        <p class="muted">
            O registro da anulação — motivo, data e quem anulou — não fica guardado.
            Guardá-lo exigiria um histórico de mudanças, que este sistema não tem.
        </p>
    <?php else: ?>
        <p>
            <?= count($aAnular) === 1 ? 'Este certificado deixa' : 'Estes certificados deixam' ?>
            de ser confirmado<?= count($aAnular) === 1 ? '' : 's' ?> em <code>/verificar</code>
            e de aparecer na busca pública. Quem já tiver baixado o PDF continua com
            uma cópia; o endereço de verificação impresso nele passa a responder que
            não encontrou nada.
        </p>
        <p class="muted">
            A linha não é apagada: o nome, o documento, quem emitiu e agora quem
            anulou continuam registrados. Para desfazer uma colagem inteira que saiu
            errada, use <a href="certificados_lote.php">a página do lote</a> — lá as
            linhas são realmente removidas, e isso só faz sentido para uma colagem
            recém-feita.
        </p>
    <?php endif; ?>

    <?php /* One card per certificate below 760px — see .cartoes in Template. */ ?>
    <table class="cartoes">
        <thead><tr><th>Nome</th><th>Evento</th><th>Função</th><th>Certificado</th><th>Estado</th></tr></thead>
        <tbody>
            <?php foreach ($aAnular as $linha): ?>
                <tr>
                    <td class="cartao-titulo"><?= $e(trim((string) $linha->getNome())) ?></td>
                    <td data-rotulo="Evento"><?= $e((string) $linha->getEventoId()) ?></td>
                    <td data-rotulo="Função"><?= $e(Funcao::label((string) $linha->getFuncao())) ?></td>
                    <td data-rotulo="Certificado"><code><?= $e((string) $linha->getToken()) ?></code></td>
                    <td class="muted" data-rotulo="Estado">
                        <?php if ($linha->getAnuladoEm() !== null): ?>
                            já anulado em <?= $e($linha->getAnuladoEm()->format('d/m/Y')) ?>
                        <?php else: ?>
                            válido
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <form method="post" action="certificados_busca.php" style="margin-top: 20px;">
        <?= Csrf::campo(FORMULARIO) ?>
        <?php foreach ($aAnular as $linha): ?>
            <input type="hidden" name="tokens[]" value="<?= $e((string) $linha->getToken()) ?>" />
        <?php endforeach; ?>
        <input type="hidden" name="vistos" value="<?= count($aAnular) ?>" />
        <input type="hidden" name="estado" value="<?= $e($estado) ?>" />
        <input type="hidden" name="nome" value="<?= $e($nome) ?>" />
        <input type="hidden" name="documento" value="<?= $e($documento) ?>" />
        <input type="hidden" name="tipo_documento" value="<?= $e($tipoDoc) ?>" />
        <?php foreach ($eventosSel as $codigo): ?>
            <input type="hidden" name="eventos[]" value="<?= $e($codigo) ?>" />
        <?php endforeach; ?>
        <?php foreach ($funcoesSel as $codigo): ?>
            <input type="hidden" name="funcoes[]" value="<?= $e($codigo) ?>" />
        <?php endforeach; ?>

        <?php if (!$restaurando): ?>
            <div class="field">
                <label for="motivo">Motivo</label>
                <input type="text" id="motivo" name="motivo" required
                       maxlength="<?= Anulacao::MOTIVO_MAX ?>" value="<?= $e($motivo) ?>"
                       placeholder="ex.: emitido em duplicidade para o mesmo participante" />
                <p class="muted">
                    Fica no registro. É a única parte que ninguém consegue reconstruir depois.
                </p>
            </div>
            <?php foreach ($errosMotivo as $erro): ?>
                <div class="alerta erro"><?= $e($erro) ?></div>
            <?php endforeach; ?>
        <?php endif; ?>

        <button type="submit" name="acao" value="<?= $restaurando ? 'restaurar_confirmado' : 'anular_confirmado' ?>"
                class="<?= $restaurando ? '' : 'perigo' ?>">
            <?= $restaurando ? 'Restaurar' : 'Anular' ?> <?= count($aAnular) ?>
            certificado<?= count($aAnular) === 1 ? '' : 's' ?>
        </button>
        <a class="btn btn-secondary" href="certificados_busca.php">Cancelar</a>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <h1>Consultar certificados</h1>
    <p class="muted">
        Todos os certificados já emitidos. Deixe um campo em branco para não filtrar
        por ele. Em nome e documento, <code>*</code> vale por qualquer trecho:
        <code>Jose*Silva</code> encontra "José Antônio da Silva". Acentos, maiúsculas
        e apóstrofos são ignorados dos dois lados.
    </p>

    <form method="post" action="certificados_busca.php">
        <?= Csrf::campo(FORMULARIO) ?>
        <input type="hidden" name="pagina" value="1" id="pagina" />

        <div class="campos">
            <div class="field">
                <label for="f-evento">Evento</label>
                <details class="multi" id="f-evento" data-multi>
                    <summary><span data-resumo><?= $resumo($eventosSel, 'Todos os eventos') ?></span></summary>
                    <div class="multi-painel">
                        <input type="text" class="multi-filtro" placeholder="filtrar eventos…" data-filtro />
                        <div class="multi-lista">
                            <?php foreach ($eventos->porCodigo() as $codigo => $nomeEvento): ?>
                                <label>
                                    <input type="checkbox" name="eventos[]" value="<?= $e($codigo) ?>"
                                        <?= in_array($codigo, $eventosSel, true) ? 'checked' : '' ?> />
                                    <?= $e($codigo) ?> — <?= $e($nomeEvento) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="multi-acoes">
                            <button type="button" data-limpar>Limpar seleção</button>
                        </div>
                    </div>
                </details>
            </div>

            <div class="field">
                <label for="f-funcao">Função</label>
                <details class="multi" id="f-funcao" data-multi>
                    <summary><span data-resumo><?= $resumo($funcoesSel, 'Todas as funções') ?></span></summary>
                    <div class="multi-painel">
                        <input type="text" class="multi-filtro" placeholder="filtrar funções…" data-filtro />
                        <div class="multi-lista">
                            <?php foreach (Funcao::labels() as $codigo => $rotulo): ?>
                                <label>
                                    <input type="checkbox" name="funcoes[]" value="<?= $e($codigo) ?>"
                                        <?= in_array($codigo, $funcoesSel, true) ? 'checked' : '' ?> />
                                    <?= $e($rotulo) ?>
                                    <?php if (Funcao::isDeprecated($codigo)): ?>
                                        <span class="muted">(não usada em registros novos)</span>
                                    <?php endif; ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="multi-acoes">
                            <button type="button" data-limpar>Limpar seleção</button>
                        </div>
                    </div>
                </details>
            </div>
        </div>

        <div class="campos">
            <div class="field">
                <label for="nome">Nome</label>
                <input type="text" id="nome" name="nome" value="<?= $e($nome) ?>"
                       placeholder="Jose*Silva" maxlength="300" />
            </div>

            <div class="field">
                <label for="documento">Documento</label>
                <div class="doc-composto">
                    <input type="text" id="documento" name="documento" value="<?= $e($documento) ?>"
                           placeholder="529.982* ou AB123*" maxlength="64" />
                    <select name="tipo_documento" aria-label="Tipo de documento">
                        <option value="<?= Consulta::DOC_AMBOS ?>"      <?= $tipoDoc === Consulta::DOC_AMBOS ? 'selected' : '' ?>>CPF e passaporte</option>
                        <option value="<?= Consulta::DOC_CPF ?>"        <?= $tipoDoc === Consulta::DOC_CPF ? 'selected' : '' ?>>Só CPF</option>
                        <option value="<?= Consulta::DOC_PASSAPORTE ?>" <?= $tipoDoc === Consulta::DOC_PASSAPORTE ? 'selected' : '' ?>>Só passaporte</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="field">
            <label for="estado">Situação</label>
            <select id="estado" name="estado">
                <?php foreach (Consulta::estados() as $opcao): ?>
                    <option value="<?= $e($opcao) ?>" <?= $estado === $opcao ? 'selected' : '' ?>>
                        <?= $e(Consulta::rotuloEstado($opcao)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="muted">
                Certificados anulados ficam fora por padrão. Eles não aparecem em
                <code>/verificar</code> nem na busca pública, mas continuam registrados
                aqui — com o motivo e quem anulou.
            </p>
        </div>

        <?php if ($erroNome !== ''): ?>
            <div class="alerta erro"><?= $e($erroNome) ?></div>
        <?php endif; ?>

        <button type="submit">Buscar</button>
    </form>
</div>

<?php if ($consulta !== null): ?>
    <div class="card">
        <?php if ($consulta->documentoImpossivel()): ?>
            <h2>Nenhum certificado encontrado</h2>
            <p>
                <code><?= $e($consulta->documento()) ?></code> não pode ser
                <?= $tipoDoc === Consulta::DOC_CPF ? 'um CPF' : 'um passaporte' ?>:
                <?= $tipoDoc === Consulta::DOC_CPF
                    ? 'não sobrou nenhum dígito depois de tirar as letras'
                    : 'não sobrou nenhuma letra nem dígito' ?>.
            </p>
            <p class="muted">
                A busca não ignorou esse campo — ignorá-lo devolveria mais linhas do
                que você pediu, e não menos. Troque o seletor ao lado do campo para
                <em>CPF e passaporte</em>, ou corrija o termo.
            </p>
        <?php elseif ($total === 0): ?>
            <h2>Nenhum certificado encontrado</h2>
            <p class="muted">
                Nenhuma linha combina com esses filtros.
                <?php if ($nome !== ''): ?>
                    A busca é por trecho, não por palavra: <code>Jose Silva</code> só
                    acha quem tem exatamente isso escrito junto. Para um nome do meio
                    no caminho, use <code>Jose*Silva</code>.
                <?php endif; ?>
            </p>
        <?php else:
            $paginas = $consulta->paginas($total);
        ?>
            <h2>
                <?= $total ?> certificado<?= $total === 1 ? '' : 's' ?>
                <?php if (!$consulta->temFiltro()): ?>
                    <span class="muted" style="font-size: 14px;">— sem nenhum filtro, é a tabela inteira</span>
                <?php endif; ?>
            </h2>
            <?php if ($paginas > 1): ?>
                <p class="muted">Página <?= $pagina ?> de <?= $paginas ?>.</p>
            <?php endif; ?>

            <form method="post" action="certificados_busca.php" id="acoes">
            <?= Csrf::campo(FORMULARIO) ?>
            <input type="hidden" name="estado" value="<?= $e($estado) ?>" />
            <input type="hidden" name="nome" value="<?= $e($nome) ?>" />
            <input type="hidden" name="documento" value="<?= $e($documento) ?>" />
            <input type="hidden" name="tipo_documento" value="<?= $e($tipoDoc) ?>" />
            <?php foreach ($eventosSel as $codigo): ?>
                <input type="hidden" name="eventos[]" value="<?= $e($codigo) ?>" />
            <?php endforeach; ?>
            <?php foreach ($funcoesSel as $codigo): ?>
                <input type="hidden" name="funcoes[]" value="<?= $e($codigo) ?>" />
            <?php endforeach; ?>

            <?php /*
                A table on a desktop and one card per certificate below 760px,
                which is the same markup either way: the labels come from
                data-rotulo, so the header row above and the labels on the
                cards cannot drift apart. See .cartoes in Template.
            */ ?>
            <table class="cartoes">
                <thead>
                    <tr>
                        <th></th>
                        <th>Nome</th><th>Evento</th><th>Função</th><th>Documento</th>
                        <th>Certificado</th><th>Origem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($linhas as $linha):
                        $token   = (string) $linha->getToken();
                        $doc     = (string) ($linha->getCpf() ?: $linha->getDocumentoEstrangeiro());
                        $anulado = $linha->getAnuladoEm() !== null;
                    ?>
                        <tr<?= $anulado ? ' style="background: #fbf3f3;"' : '' ?>>
                            <td class="cartao-marcar">
                                <input type="checkbox" name="tokens[]" value="<?= $e($token) ?>"
                                       aria-label="selecionar" />
                            </td>
                            <td class="cartao-titulo">
                                <?= $e(trim((string) $linha->getNome())) ?>
                                <?php if ($anulado): ?>
                                    <div class="muted" style="font-size: 12px; color: var(--erro);">
                                        anulado em <?= $e($linha->getAnuladoEm()->format('d/m/Y')) ?><?php
                                            if ($linha->getAnuladoMotivo() !== null): ?> —
                                            <?= $e((string) $linha->getAnuladoMotivo()) ?><?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td data-rotulo="Evento"><?= $e((string) $linha->getEventoId()) ?></td>
                            <td data-rotulo="Função"><?= $e(Funcao::label((string) $linha->getFuncao())) ?></td>
                            <td data-rotulo="Documento">
                                <?php /*
                                    Both spellings are here and the viewport
                                    picks one; see .doc-curto in Template. Not
                                    a privacy control — this page needs the
                                    `certificados` permission — but a phone
                                    held at arm's length in a paddock is read
                                    by more people than a laptop at a desk.
                                */ ?>
                                <span class="doc-longo"><?= $e($doc) ?></span>
                                <span class="doc-curto"><?= $e(Documento::mascarar($doc)) ?></span>
                                <?php if ($linha->getCpf() === null && $doc !== ''): ?>
                                    <span class="muted">(passaporte)</span>
                                <?php endif; ?>
                            </td>
                            <td data-rotulo="Certificado">
                                <a href="<?= $e(Url::subdomain('certificado', '/verificar/' . $token)) ?>"
                                   target="_blank" rel="noreferrer"><code><?= $e($token) ?></code></a>
                            </td>
                            <td class="muted" data-rotulo="Origem">
                                <?php if ($linha->getLoteId() !== null): ?>
                                    <a href="lote.php?id=<?= urlencode((string) $linha->getLoteId()) ?>">lote</a>
                                <?php endif; ?>
                                <?php if ($linha->getCriadoEm() !== null): ?>
                                    <?= $e($linha->getCriadoEm()->format('d/m/Y')) ?>
                                <?php elseif ($linha->getLoteId() === null): ?>
                                    anterior ao registro de origem
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div style="margin-top: 16px;">
                <button type="submit" name="acao" value="anular" class="secundario">
                    Anular selecionados&hellip;
                </button>
                <?php if ($estado !== Consulta::ESTADO_VALIDOS): ?>
                    <button type="submit" name="acao" value="restaurar" class="secundario">
                        Restaurar selecionados&hellip;
                    </button>
                <?php endif; ?>
                <span class="muted" style="margin-left: 8px;">
                    Anular não apaga a linha — ela continua registrada, com o motivo.
                </span>
            </div>
            </form>

            <?php if ($paginas > 1): ?>
                <form method="post" action="certificados_busca.php" style="margin-top: 16px;">
                    <?= Csrf::campo(FORMULARIO) ?>
                    <?php
                    // The filters travel with the page turn. There is no URL to
                    // put them in, because a document number must not be in one.
                    foreach ($eventosSel as $codigo): ?>
                        <input type="hidden" name="eventos[]" value="<?= $e($codigo) ?>" />
                    <?php endforeach; ?>
                    <?php foreach ($funcoesSel as $codigo): ?>
                        <input type="hidden" name="funcoes[]" value="<?= $e($codigo) ?>" />
                    <?php endforeach; ?>
                    <input type="hidden" name="nome" value="<?= $e($nome) ?>" />
                    <input type="hidden" name="documento" value="<?= $e($documento) ?>" />
                    <input type="hidden" name="tipo_documento" value="<?= $e($tipoDoc) ?>" />

                    <?php if ($pagina > 1): ?>
                        <button type="submit" name="pagina" value="<?= $pagina - 1 ?>" class="secundario">&larr; Anterior</button>
                    <?php endif; ?>
                    <?php if ($pagina < $paginas): ?>
                        <button type="submit" name="pagina" value="<?= $pagina + 1 ?>" class="secundario">Próxima &rarr;</button>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php Template::printScriptMultiSelect(); ?>

<script>
// A new search starts at the first page; only the pager's own buttons carry a
// page number.
var campoPagina = document.getElementById('pagina');
if (campoPagina) { campoPagina.value = '1'; }
</script>

<?php Template::printFooter();
