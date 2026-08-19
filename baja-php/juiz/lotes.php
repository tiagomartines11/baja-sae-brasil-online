<?php

namespace Baja\Juiz;

use Baja\Certificado\Insercao\Acesso;
use Baja\Certificado\Insercao\Eventos;
use Baja\Certificado\Insercao\Lotes;
use Baja\Certificado\Insercao\Template;
use Baja\Certificado\Insercao\Texto;

/**
 * Every batch that has been created, newest first.
 *
 * The batch id has been what makes a bad paste identifiable since the
 * beginning, but it was identifiable only to somebody who copied it off the
 * success page before navigating away. This is the way back to one.
 *
 * Unlike the certificate lookup, this page uses GET. That page posts because a
 * document number in a URL is a document number in the access log; nothing
 * here is a document number or a name — a batch id is a random token — so the
 * filters can live in the address, and a view of one batch can be sent to
 * somebody.
 */

$usuario = Acesso::exigir();

$eventos = new Eventos();
$autores = Lotes::autores();

$eventosSel = [];
foreach (Texto::mapaDeTexto($_GET['eventos'] ?? []) as $codigo) {
    if ($eventos->existe($codigo)) {
        $eventosSel[] = $codigo;
    }
}

$autorSel = (int) Texto::escalar($_GET['autor'] ?? '0');
if (!isset($autores[$autorSel])) {
    $autorSel = 0;
}

$idBusca = Texto::limpar(Texto::escalar($_GET['id'] ?? ''));
$pagina  = max(1, (int) Texto::escalar($_GET['pagina'] ?? '1'));

$lotes   = new Lotes($eventosSel, $autorSel, $idBusca);
$total   = $lotes->total();
$paginas = $lotes->paginas($total);
$linhas  = $lotes->pagina($pagina);

// Names for whoever appears in this page, in one query rather than one per row.
$ids = [];
foreach ($linhas as $linha) {
    foreach ($linha['autores'] as $id) {
        $ids[] = $id;
    }
}
$nomes = Lotes::nomesDeUsuario($ids);

Template::printHeader('Lotes', $usuario);

$e = fn(string $v): string => Template::e($v);

/** The current filters, for the pager's links. */
$querystring = function (array $extra = []) use ($eventosSel, $autorSel, $idBusca): string {
    $params = array_merge([
        'eventos' => $eventosSel,
        'autor'   => $autorSel > 0 ? $autorSel : null,
        'id'      => $idBusca !== '' ? $idBusca : null,
    ], $extra);

    return http_build_query(array_filter(
        $params,
        static fn ($v): bool => $v !== null && $v !== '' && $v !== []
    ));
};
?>

<div class="card">
    <h1>Lotes</h1>
    <p class="muted">
        Cada colagem cria um lote. É por ele que se descobre o que entrou junto com
        uma linha — e é por ele que uma colagem errada é desfeita.
    </p>

    <form method="get" action="lotes.php">
        <div class="campos">
            <div class="field">
                <label for="f-evento">Evento</label>
                <details class="multi" id="f-evento" data-multi>
                    <summary><span data-resumo><?= $eventosSel === []
                        ? 'Todos os eventos'
                        : $e(count($eventosSel) <= 3 ? implode(', ', $eventosSel) : count($eventosSel) . ' selecionados') ?></span></summary>
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
                <p class="muted">
                    Um lote aparece se qualquer linha dele for desse evento — e as contagens
                    continuam sendo do lote inteiro, não só da parte que combina.
                </p>
            </div>

            <div class="field">
                <label for="autor">Criado por</label>
                <select id="autor" name="autor">
                    <option value="0">Qualquer pessoa</option>
                    <?php foreach ($autores as $id => $username): ?>
                        <option value="<?= (int) $id ?>" <?= $autorSel === (int) $id ? 'selected' : '' ?>>
                            <?= $e($username) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="id" style="margin-top: 16px;">Identificador do lote</label>
                <input type="text" id="id" name="id" value="<?= $e($idBusca) ?>"
                       placeholder="trecho do identificador" maxlength="32" />
            </div>
        </div>

        <button type="submit">Filtrar</button>
        <?php if ($lotes->temFiltro()): ?>
            <a class="btn btn-secondary" href="lotes.php">Limpar filtros</a>
        <?php endif; ?>
    </form>
</div>

<div class="card">
    <?php if ($total === 0): ?>
        <h2>Nenhum lote</h2>
        <p class="muted">
            <?= $lotes->temFiltro()
                ? 'Nenhum lote combina com esses filtros.'
                : 'Nada foi criado por estas páginas ainda.' ?>
        </p>
    <?php else: ?>
        <h2><?= $total ?> lote<?= $total === 1 ? '' : 's' ?></h2>
        <?php if ($paginas > 1): ?>
            <p class="muted">Página <?= $pagina ?> de <?= $paginas ?>.</p>
        <?php endif; ?>

        <table>
            <thead>
                <tr>
                    <th>Quando</th><th>Criado por</th><th>Evento(s)</th>
                    <th>Certificados</th><th>Identificador</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($linhas as $linha): ?>
                    <tr>
                        <td>
                            <?php if ($linha['criado_em'] !== null): ?>
                                <?= $e(date('d/m/Y H:i', strtotime($linha['criado_em']))) ?>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $quem = [];
                            foreach ($linha['autores'] as $id) {
                                $quem[] = $nomes[$id] ?? ('#' . $id);
                            }
                            ?>
                            <?= $quem === [] ? '<span class="muted">—</span>' : $e(implode(', ', $quem)) ?>
                        </td>
                        <td><?= $e(implode(', ', $linha['eventos'])) ?></td>
                        <td>
                            <?= $linha['linhas'] ?>
                            <?php if ($linha['anuladas'] > 0): ?>
                                <div style="font-size: 12px; color: var(--erro);">
                                    <?= $linha['anuladas'] ?> anulado<?= $linha['anuladas'] === 1 ? '' : 's' ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="lote.php?id=<?= urlencode($linha['id']) ?>"><code><?= $e($linha['id']) ?></code></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($paginas > 1): ?>
            <p style="margin-top: 16px;">
                <?php if ($pagina > 1): ?>
                    <a class="btn btn-secondary" href="lotes.php?<?= $e($querystring(['pagina' => $pagina - 1])) ?>">&larr; Anterior</a>
                <?php endif; ?>
                <?php if ($pagina < $paginas): ?>
                    <a class="btn btn-secondary" href="lotes.php?<?= $e($querystring(['pagina' => $pagina + 1])) ?>">Próxima &rarr;</a>
                <?php endif; ?>
            </p>
        <?php endif; ?>
    <?php endif; ?>

    <?php $orfaos = Lotes::semLote(); ?>
    <?php if ($orfaos > 0): ?>
        <p class="muted" style="margin-top: 20px;">
            Além destes, <?= $orfaos ?> certificado<?= $orfaos === 1 ? '' : 's' ?>
            não pertence<?= $orfaos === 1 ? '' : 'm' ?> a nenhum lote:
            <?= $orfaos === 1 ? 'foi criado' : 'foram criados' ?> antes de existir
            registro de origem, e não há de onde reconstruir isso.
        </p>
    <?php endif; ?>
</div>

<?php Template::printScriptMultiSelect(); ?>
<?php Template::printFooter();
