<?php

namespace Baja\Juiz;

use Baja\Certificado\Funcao;
use Baja\Certificado\Insercao\Acesso;
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
$pagina      = 1;
$erroCsrf    = false;
$erroNome    = '';
$consulta    = null;
$linhas      = [];
$total       = 0;

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
            $consulta = new Consulta($eventosSel, $funcoesSel, $nome, $documento, $tipoDoc);
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

            <table>
                <thead>
                    <tr>
                        <th>Nome</th><th>Evento</th><th>Função</th><th>Documento</th>
                        <th>Certificado</th><th>Origem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($linhas as $linha):
                        $token = (string) $linha->getToken();
                        $doc   = (string) ($linha->getCpf() ?: $linha->getDocumentoEstrangeiro());
                    ?>
                        <tr>
                            <td><?= $e(trim((string) $linha->getNome())) ?></td>
                            <td><?= $e((string) $linha->getEventoId()) ?></td>
                            <td><?= $e(Funcao::label((string) $linha->getFuncao())) ?></td>
                            <td>
                                <?= $e($doc) ?>
                                <?php if ($linha->getCpf() === null && $doc !== ''): ?>
                                    <span class="muted">(passaporte)</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?= $e(Url::subdomain('certificado', '/verificar/' . $token)) ?>"
                                   target="_blank" rel="noreferrer"><code><?= $e($token) ?></code></a>
                            </td>
                            <td class="muted">
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

<script>
// Progressive enhancement only. Without this the panels still open, every
// option is still visible, and only the filter box does nothing.
document.querySelectorAll('[data-multi]').forEach(function (bloco) {
    var filtro  = bloco.querySelector('[data-filtro]');
    var resumo  = bloco.querySelector('[data-resumo]');
    var limpar  = bloco.querySelector('[data-limpar]');
    var labels  = Array.prototype.slice.call(bloco.querySelectorAll('.multi-lista label'));
    var vazio   = resumo.textContent.trim();

    function atualizarResumo() {
        var marcados = labels
            .filter(function (l) { return l.querySelector('input').checked; })
            .map(function (l) { return l.querySelector('input').value; });

        if (marcados.length === 0)      resumo.textContent = vazio;
        else if (marcados.length <= 3)  resumo.textContent = marcados.join(', ');
        else                            resumo.textContent = marcados.length + ' selecionados';
    }

    filtro.addEventListener('input', function () {
        var termo = filtro.value.toLowerCase();
        labels.forEach(function (l) {
            // A checked option always stays visible. Filtering something out
            // of sight while it is still part of the search is how somebody
            // ends up not understanding their own results.
            var visivel = l.querySelector('input').checked
                || l.textContent.toLowerCase().indexOf(termo) !== -1;
            l.style.display = visivel ? '' : 'none';
        });
    });

    // Typing in the filter must not submit the form.
    filtro.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') { ev.preventDefault(); }
    });

    bloco.addEventListener('change', atualizarResumo);

    limpar.addEventListener('click', function () {
        labels.forEach(function (l) { l.querySelector('input').checked = false; });
        atualizarResumo();
    });

    atualizarResumo();
});

// A new search starts at the first page; only the pager's own buttons carry
// a page number.
var campoPagina = document.getElementById('pagina');
if (campoPagina) { campoPagina.value = '1'; }
</script>

<?php Template::printFooter();
