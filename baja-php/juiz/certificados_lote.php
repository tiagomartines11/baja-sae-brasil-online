<?php

namespace Baja\Juiz;

use Baja\Certificado\Funcao;
use Baja\Certificado\Insercao\Acesso;
use Baja\Certificado\Insercao\Csrf;
use Baja\Certificado\Insercao\Mapeamento;
use Baja\Certificado\Insercao\Planilha;
use Baja\Certificado\Insercao\Template;
use Baja\Model\EventoQuery;

/**
 * Bulk certificate creation from a spreadsheet paste.
 *
 * Three steps, each one a POST carrying the paste forward in a single hidden
 * field: paste, map the columns, review and commit. The text travels whole
 * rather than as parsed rows, so nothing about the submission is reconstructed
 * from something the browser could have altered — every step re-parses and
 * re-validates from the same bytes the operator pasted.
 */

$usuario = Acesso::exigir();

const FORMULARIO = 'certificado-lote';

$eventos = EventoQuery::create()->orderByEventoId('desc')->find();

// PHP answers a body over post_max_size with an empty $_POST, no error and no
// exception — the page would simply redraw as if nothing had been sent. This
// is the only way to notice, and it has to happen before anything else reads
// $_POST.
$estourouPost = $_SERVER['REQUEST_METHOD'] === 'POST'
    && $_POST === []
    && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;

$etapa     = 'colar';
$erroCsrf  = false;
$colado    = '';
$planilha  = null;
$mapeamento = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$estourouPost) {
    if (!Csrf::postValido(FORMULARIO)) {
        $erroCsrf = true;
    } else {
        $colado   = (string) ($_POST['colado'] ?? '');
        $planilha = Planilha::analisar($colado);

        if (!$planilha->vazia()) {
            $etapa = 'mapear';

            if (isset($_POST['colunas']) || isset($_POST['fixos'])) {
                $mapeamento = new Mapeamento(
                    (array) ($_POST['colunas'] ?? []),
                    (array) ($_POST['fixos'] ?? [])
                );
            } else {
                $mapeamento = Mapeamento::padrao($planilha->largura());
            }
        }
    }
}

Template::printHeader('Inserção em lote', $usuario);

$e = fn(string $v): string => Template::e($v);
?>

<?php if ($estourouPost): ?>
    <div class="alerta erro">
        <strong>A colagem é grande demais para ser enviada.</strong>
        O limite do servidor é <?= $e((string) ini_get('post_max_size')) ?>.
        Divida a planilha em partes menores — o limite desta página é
        <?= Planilha::MAX_LINHAS ?> linhas por vez, e isso costuma caber bem
        dentro do limite do servidor.
    </div>
<?php endif; ?>

<?php if ($erroCsrf): ?>
    <div class="alerta erro">
        A sessão do formulário expirou e nada foi salvo. Cole a planilha de novo.
    </div>
<?php endif; ?>

<?php if ($etapa === 'colar'): ?>

<div class="card">
    <h1>Inserção em lote</h1>
    <p class="muted">
        Cole aqui as linhas copiadas direto da planilha. Para um participante só,
        use a <a href="certificados.php">inserção individual</a>.
    </p>

    <form method="post" action="certificados_lote.php">
        <?= Csrf::campo(FORMULARIO) ?>
        <div class="field">
            <label for="colado">Cole a planilha</label>
            <textarea id="colado" name="colado" rows="14" required
                      placeholder="Evento&#9;Nome&#9;Função&#9;Documento"><?= $e($colado) ?></textarea>
            <p class="muted">
                Até <?= Planilha::MAX_LINHAS ?> linhas por vez. Na próxima tela você
                diz qual coluna é o quê — não precisa reorganizar a planilha.
                <br />
                Se a coluna do CPF estiver formatada como número, formate-a como
                texto antes de copiar: o Excel transforma um CPF longo em algo como
                <code>1,23457E+10</code> e os dígitos se perdem de vez.
            </p>
        </div>
        <button type="submit">Ler a planilha</button>
    </form>
</div>

<?php elseif ($etapa === 'mapear' && $planilha !== null && $mapeamento !== null):

    $largura   = $planilha->largura();
    $faltando  = $mapeamento->faltando();
    $duplicados = $mapeamento->duplicados();

    $irregulares = [];
    foreach ($planilha->linhas as $i => $celulas) {
        if ($mapeamento->ehIrregular($celulas)) {
            $irregulares[] = $i + 1;
        }
    }
?>

<div class="card">
    <h1>De qual coluna é cada campo?</h1>
    <p class="muted">
        <?= count($planilha->linhas) ?> linha<?= count($planilha->linhas) === 1 ? '' : 's' ?>
        lida<?= count($planilha->linhas) === 1 ? '' : 's' ?>,
        <?= $largura ?> coluna<?= $largura === 1 ? '' : 's' ?>.
    </p>

    <?php if ($planilha->truncada()): ?>
        <div class="alerta aviso">
            A planilha tem <?= $planilha->total ?> linhas e só as primeiras
            <?= Planilha::MAX_LINHAS ?> foram lidas. Envie o resto em outra colagem.
        </div>
    <?php endif; ?>

    <form method="post" action="certificados_lote.php">
        <?= Csrf::campo(FORMULARIO) ?>
        <input type="hidden" name="colado" value="<?= $e($colado) ?>" />

        <table>
            <thead>
                <tr>
                    <?php for ($c = 0; $c < $largura; $c++): ?>
                        <th>
                            <select name="colunas[<?= $c ?>]">
                                <option value=""<?= $mapeamento->campoDaColuna($c) === '' ? ' selected' : '' ?>>— ignorar —</option>
                                <?php foreach (Mapeamento::CAMPOS as $campo): ?>
                                    <option value="<?= $e($campo) ?>"<?= $mapeamento->campoDaColuna($c) === $campo ? ' selected' : '' ?>>
                                        <?= $e(Mapeamento::rotulo($campo)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </th>
                    <?php endfor; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_slice($planilha->linhas, 0, 5) as $celulas): ?>
                    <tr>
                        <?php for ($c = 0; $c < $largura; $c++): ?>
                            <td><?= $e($celulas[$c] ?? '') ?></td>
                        <?php endfor; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="muted">
            <?= count($planilha->linhas) > 5 ? 'Cinco primeiras linhas.' : '' ?>
            Se a primeira linha for o cabeçalho da planilha, apague-a da colagem:
            ela seria lida como um participante chamado "Nome".
        </p>

        <h2 style="margin-top: 24px;">Valores para a planilha inteira</h2>
        <p class="muted">
            Para os campos que não têm coluna própria. O normal é uma planilha ser
            de um evento só.
        </p>
        <div class="campos">
            <div class="field">
                <label for="fixo_evento">Evento</label>
                <select id="fixo_evento" name="fixos[evento]"
                        <?= in_array('evento', $mapeamento->colunas(), true) ? 'disabled' : '' ?>>
                    <option value="">— usar a coluna —</option>
                    <?php foreach ($eventos as $evento): ?>
                        <option value="<?= $e((string) $evento->getEventoId()) ?>"
                            <?= $mapeamento->fixo('evento') === (string) $evento->getEventoId() ? 'selected' : '' ?>>
                            <?= $e((string) $evento->getEventoId()) ?> —
                            <?= $e(html_entity_decode((string) $evento->getNome(), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="fixo_funcao">Função</label>
                <select id="fixo_funcao" name="fixos[funcao]"
                        <?= in_array('funcao', $mapeamento->colunas(), true) ? 'disabled' : '' ?>>
                    <option value="">— usar a coluna —</option>
                    <?php foreach (Funcao::selectable() as $codigo => $rotulo): ?>
                        <option value="<?= $e($codigo) ?>"<?= $mapeamento->fixo('funcao') === $codigo ? ' selected' : '' ?>>
                            <?= $e($rotulo) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <?php if ($duplicados !== []): ?>
            <div class="alerta erro">
                <?= $e(implode(', ', array_map([Mapeamento::class, 'rotulo'], $duplicados))) ?>
                está em mais de uma coluna. Escolha qual.
            </div>
        <?php endif; ?>

        <?php if ($faltando !== []): ?>
            <div class="alerta erro">
                Falta dizer de onde vem:
                <?= $e(implode(', ', array_map([Mapeamento::class, 'rotulo'], $faltando))) ?>.
            </div>
        <?php endif; ?>

        <?php if ($irregulares !== []): ?>
            <div class="alerta aviso">
                <strong><?= count($irregulares) ?>
                linha<?= count($irregulares) === 1 ? '' : 's' ?> com menos colunas
                que o esperado</strong>
                (linha<?= count($irregulares) === 1 ? '' : 's' ?>
                <?= $e(implode(', ', array_slice($irregulares, 0, 20))) ?><?= count($irregulares) > 20 ? '…' : '' ?>).
                Elas não são completadas automaticamente: quase sempre é uma célula
                com tabulação dentro, e completar a linha desloca todos os campos
                seguintes — o resultado passaria na validação e seria sobre outra
                pessoa.
            </div>
        <?php endif; ?>

        <button type="submit" name="etapa" value="mapear" class="secundario">Reler com este mapeamento</button>
        <button type="submit" name="etapa" value="revisar" <?= $mapeamento->valido() ? '' : 'disabled' ?>>
            Conferir <?= count($planilha->linhas) ?> linha<?= count($planilha->linhas) === 1 ? '' : 's' ?>
        </button>
    </form>
</div>

<?php else: ?>

<div class="card">
    <h1>Nada para ler</h1>
    <p>A colagem estava vazia.</p>
    <p><a class="btn" href="certificados_lote.php">Colar de novo</a></p>
</div>

<?php endif; ?>

<?php Template::printFooter();
