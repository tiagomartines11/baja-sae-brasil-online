<?php

namespace Baja\Juiz;

use Baja\Certificado\Funcao;
use Baja\Certificado\Insercao\Acesso;
use Baja\Certificado\Insercao\Csrf;
use Baja\Certificado\Insercao\Exportacao;
use Baja\Certificado\Insercao\Gravador;
use Baja\Certificado\Insercao\Mapeamento;
use Baja\Certificado\Insercao\Planilha;
use Baja\Certificado\Insercao\Problema;
use Baja\Certificado\Insercao\Revisao;
use Baja\Certificado\Insercao\Template;
use Baja\Certificado\Insercao\Texto;
use Baja\Certificado\Insercao\Validador;
use Baja\Certificado\Token;
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

$etapa      = 'colar';
$erroCsrf   = false;
$colado     = '';
$planilha   = null;
$mapeamento = null;
$revisao     = null;
$resultado   = null;
$pendentes    = [];
$jaGravado    = null;
$tentouGravar = false;
$irregulares = [];
$loteAlvo    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$estourouPost) {
    if (!Csrf::postValido(FORMULARIO)) {
        $erroCsrf = true;
    } else {
        $colado   = Texto::escalar($_POST['colado'] ?? '');
        $planilha = Planilha::analisar($colado);

        if (!$planilha->vazia()) {
            $etapa = 'mapear';

            if (isset($_POST['colunas']) || isset($_POST['fixos'])) {
                $mapeamento = new Mapeamento(
                    Texto::mapaDeTexto($_POST['colunas'] ?? []),
                    Texto::mapaDeTexto($_POST['fixos'] ?? [])
                );
            } else {
                $mapeamento = Mapeamento::padrao($planilha->largura());
            }

            foreach ($planilha->linhas as $i => $celulas) {
                if ($mapeamento->ehIrregular($celulas)) {
                    $irregulares[] = $i + 1;
                }
            }

            // A ragged row is a mapping problem, not a row problem: it means
            // this paste does not have the shape the mapping says it has. It
            // is refused here rather than carried into the review, where it
            // would show up as four unrelated errors about the wrong fields.
            $pedido = Texto::escalar($_POST['etapa'] ?? '');

            if (in_array($pedido, ['revisar', 'gravar', 'gravar_parcial'], true) && $mapeamento->valido() && $irregulares === []) {
                $etapa = 'revisar';

                $brutas = [];
                foreach ($planilha->linhas as $celulas) {
                    $brutas[] = $mapeamento->aplicar($celulas);
                }

                // The answers, per row and in bulk. Bulk fills the gaps rather
                // than overriding: a radio the operator actually clicked is a
                // statement about that row, and a group answer applied on top
                // of it would silently discard the more specific one.
                $emLote = array_filter(
                    Texto::mapaDeTexto($_POST['lote'] ?? []),
                    static fn (string $escolha): bool => $escolha !== ''
                );

                $escolhas = [];
                foreach ($brutas as $i => $_bruta) {
                    $numero = $i + 1;
                    $daLinha = array_filter(
                        Texto::mapaDeTexto(($_POST['resolucao'] ?? [])[$numero] ?? []),
                        static fn (string $escolha): bool => $escolha !== ''
                    );
                    $escolhas[$numero] = $daLinha + $emLote;
                }

                $revisao = new Revisao((new Validador())->validar($brutas, $escolhas));

                // The batch id is decided when the review is rendered and
                // travels with the form, so that a resubmitted commit — which
                // is what a browser sends when somebody presses F5 on the
                // result — is recognisable as the same commit rather than a
                // new one. Content cannot tell us that: pasting the same sheet
                // twice for two different events is a legitimate thing to do.
                $loteAlvo = Texto::escalar($_POST['lote_alvo'] ?? '');
                if (!Token::isWellFormed($loteAlvo)) {
                    $loteAlvo = Token::generate();
                }

                $gravando = in_array($pedido, ['gravar', 'gravar_parcial'], true);
                $tentouGravar = $gravando;

                if ($gravando && Gravador::loteExiste($loteAlvo)) {
                    // Already done. Saying so beats the alternative, which is
                    // what happened before this check: the rows now exist, so
                    // every one of them comes back flagged as a duplicate and
                    // the operator is left wondering whether anything landed.
                    $jaGravado = $loteAlvo;
                    $etapa     = 'ja_gravado';
                } elseif ($gravando) {
                    // podeGravar() is re-checked here against this pass's
                    // validation, not against what the previous page rendered.
                    // The browser can post 'gravar' whenever it likes; what
                    // decides is whether the rows, re-read and re-validated a
                    // moment ago, are ready.
                    $aGravar = $pedido === 'gravar_parcial'
                        ? $revisao->prontas()
                        : ($revisao->podeGravar() ? $revisao->linhas : []);

                    if ($aGravar !== []) {
                        $resultado = (new Gravador((int) $usuario->getUserId()))
                            ->gravar($aGravar, $loteAlvo);
                        $etapa     = 'gravado';

                        // What was left behind, so the summary can hand it
                        // back. Only meaningful for a partial commit; a whole
                        // one leaves nothing.
                        $pendentes = $pedido === 'gravar_parcial' ? $revisao->naoProntas() : [];
                    }
                }
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

<?php if ($etapa === 'ja_gravado' && $jaGravado !== null): ?>

<div class="card">
    <h1>Este lote já foi criado</h1>
    <p>
        O formulário foi enviado duas vezes — normalmente é o F5 depois de criar.
        Nada foi criado de novo.
    </p>
    <p class="muted">
        Lote <code><?= $e($jaGravado) ?></code>, com
        <?= count(Gravador::linhasDoLote($jaGravado)) ?> certificado<?=
            count(Gravador::linhasDoLote($jaGravado)) === 1 ? '' : 's' ?>.
    </p>
    <p>
        <a class="btn" href="lote.php?id=<?= urlencode($jaGravado) ?>">Ver o lote</a>
        <a class="btn btn-secondary" href="certificados_lote.php">Colar outra planilha</a>
    </p>
</div>

<?php elseif ($etapa === 'gravado' && $resultado !== null): ?>

<div class="card">
    <h1>Lote criado</h1>
    <div class="alerta ok">
        <strong><?= $resultado->criadas ?>
        certificado<?= $resultado->criadas === 1 ? '' : 's' ?>
        criado<?= $resultado->criadas === 1 ? '' : 's' ?>.</strong>
    </div>

    <dl>
        <dt>Evento<?= count($resultado->eventos) === 1 ? '' : 's' ?></dt>
        <dd><?= $e(implode(', ', $resultado->eventos)) ?></dd>

        <dt>Criados</dt>
        <dd><?= $resultado->criadas ?></dd>

        <?php if ($resultado->atualizadas > 0): ?>
            <dt>Registros existentes atualizados</dt>
            <dd><?= $resultado->atualizadas ?></dd>
        <?php endif; ?>

        <?php if ($resultado->ignoradas > 0): ?>
            <dt>Linhas ignoradas</dt>
            <dd><?= $resultado->ignoradas ?></dd>
        <?php endif; ?>

        <?php if ($resultado->nomesCorrigidos > 0): ?>
            <dt>Nomes corrigidos em registros anteriores</dt>
            <dd><?= $resultado->nomesCorrigidos ?></dd>
        <?php endif; ?>

        <dt>Lote</dt>
        <dd><code><?= $e($resultado->loteId) ?></code></dd>
    </dl>

    <p style="margin-top: 20px;">
        <a class="btn" href="lote.php?id=<?= urlencode($resultado->loteId) ?>">Ver o lote</a>
        <a class="btn btn-secondary" href="certificados_lote.php">Colar outra planilha</a>
    </p>
    <p class="muted">
        Guarde o identificador do lote. É por ele que este conjunto de linhas pode
        ser encontrado — e desfeito — se tiver saído errado.
    </p>
</div>

<?php if ($pendentes !== []): ?>
<div class="card">
    <h2><?= count($pendentes) ?>
        linha<?= count($pendentes) === 1 ? '' : 's' ?>
        <?= count($pendentes) === 1 ? 'ficou' : 'ficaram' ?> de fora</h2>
    <p>
        <?= count($pendentes) === 1 ? 'Ela' : 'Elas' ?> não foram criadas e não estão
        guardadas em nenhum lugar. Continue agora, ou copie as linhas abaixo para
        resolver depois — é uma planilha como a que você colou, e pode ser colada de
        volta aqui ou enviada para quem souber responder.
    </p>

    <form method="post" action="certificados_lote.php">
        <?= Csrf::campo(FORMULARIO) ?>
        <textarea name="colado" rows="<?= min(12, max(3, count($pendentes) + 1)) ?>"
                  readonly onclick="this.select()"><?= $e(Exportacao::tsv($pendentes)) ?></textarea>
        <?php foreach (Exportacao::mapeamento() as $indice => $campo): ?>
            <input type="hidden" name="colunas[<?= (int) $indice ?>]" value="<?= $e($campo) ?>" />
        <?php endforeach; ?>
        <p class="muted">
            Colunas: <?= $e(implode(', ', array_map([Mapeamento::class, 'rotulo'], Exportacao::mapeamento()))) ?>.
            O botão abaixo já vem com esse mapeamento; se você colar em outra hora,
            marque as colunas nessa ordem.
        </p>
        <button type="submit" name="etapa" value="revisar">
            Continuar com <?= count($pendentes) ?>
            linha<?= count($pendentes) === 1 ? '' : 's' ?>
        </button>
    </form>

    <table class="blocos" style="margin-top: 20px;">
        <thead><tr><th>#</th><th>Nome</th><th>Por que ficou de fora</th></tr></thead>
        <tbody>
            <?php foreach ($pendentes as $linha): ?>
                <tr>
                    <td class="bloco-num"><?= $linha->numero ?></td>
                    <td class="bloco-nome"><?= $e($linha->nomeBruto) ?></td>
                    <td class="muted bloco-decisao">
                        <?php foreach ($linha->erros() as $problema): ?>
                            <div style="color: var(--erro);"><?= $e($problema->mensagem) ?></div>
                        <?php endforeach; ?>
                        <?php foreach ($linha->avisos() as $problema): ?>
                            <?php if ($linha->resolucao($problema->codigo) === null): ?>
                                <div style="color: var(--aviso);"><?= $e($problema->mensagem) ?></div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php elseif ($etapa === 'colar'): ?>

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

        <?php /*
            The one table on these pages that stays a table on a phone. It is
            the pasted spreadsheet as it arrived, and the operator is matching
            it against what is open in Excel; reshaping it would defeat the
            purpose. So it scrolls sideways inside .rolagem — and only inside
            it, never the page — with a shadow at whichever edge still has
            content behind it. See Template.
        */ ?>
        <div class="rolagem">
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
        </div>
        <p class="muted">
            Se a planilha tiver colunas separadas de CPF e passaporte, marque cada
            uma como tal — é isso que faz um passaporte só com números não ser
            confundido com um CPF que não passa na verificação. Se houver uma
            coluna só, misturando os dois, marque-a como <em>Documento</em>.
            <br />
            <?= count($planilha->linhas) > 5 ? 'Cinco primeiras linhas.' : '' ?>
            Se a primeira linha for o cabeçalho da planilha, apague-a da colagem:
            ela seria lida como um participante chamado "Nome".
        </p>

        <h2 style="margin-top: 24px;">Valores para a planilha inteira</h2>
        <p class="muted">
            Para os campos que não têm coluna própria. O normal é uma planilha ser
            de um evento só. Se houver coluna de evento, ela aceita o código
            (<code>22BR</code>), o nome por extenso
            (<code>22ª Competição Baja SAE BRASIL - Etapa Sul</code>) ou o título
            curto (<code>Baja SAE BRASIL - Etapa Sul 2025</code>).
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

        <?php if ($mapeamento->documentosConflitantes()): ?>
            <div class="alerta erro">
                Você marcou uma coluna como <em>Documento</em> e outra como
                <em>CPF</em> ou <em>Passaporte</em>. Use um jeito ou o outro:
                <em>Documento</em> quer dizer "descubra o que é isto", e as outras
                duas dizem o que a coluna é.
            </div>
        <?php endif; ?>

        <?php if ($faltando !== []): ?>
            <div class="alerta erro">
                Falta dizer de onde vem:
                <?= $e(implode(', ', array_map([Mapeamento::class, 'rotulo'], $faltando))) ?>.
            </div>
        <?php elseif (($_POST['etapa'] ?? '') === 'revisar' && $irregulares !== []): ?>
            <div class="alerta erro">
                Não dá para conferir enquanto houver linha com menos colunas que o
                esperado: os campos ficam deslocados e a conferência seria sobre os
                valores errados.
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
        <button type="submit" name="etapa" value="revisar">
            Conferir <?= count($planilha->linhas) ?> linha<?= count($planilha->linhas) === 1 ? '' : 's' ?>
        </button>
    </form>
</div>

<?php elseif ($etapa === 'revisar' && $revisao !== null):

    $grupos = $revisao->agrupados();

    /** Renders the four submitted values of one row, as pasted. */
    $celulasDa = function ($linha) use ($e): string {
        // The name as it would be written, not as it was pasted, with the
        // original underneath wherever the recasing rule changed it. Showing
        // only the paste would hide what actually lands in the certificate.
        $nome = $e($linha->caixaAjustada && $linha->nome !== null ? $linha->nome : $linha->nomeBruto);
        if ($linha->caixaAjustada) {
            $nome .= '<div class="muted" style="font-size: 12px;">colado como '
                . $e($linha->nomeBruto) . '</div>';
        }

        // The classes are for the narrow layout, where the row stops being a
        // table row and these four run together as one summary line. See
        // .blocos in Template.
        return sprintf(
            '<td>%s</td><td class="bloco-nome">%s</td><td>%s</td><td>%s</td>',
            $e($linha->eventoBruto),
            $nome,
            $e($linha->funcaoBruta),
            $e($linha->documentoBruto)
        );
    };
?>

<div class="card">
    <h1>Conferir antes de criar</h1>
    <p class="muted">
        <?= count($revisao->linhas) ?> linha<?= count($revisao->linhas) === 1 ? '' : 's' ?> —
        <?= count($revisao->erros) ?> com erro,
        <?= count($revisao->avisos) ?> pedindo decisão,
        <?= count($revisao->ok) ?> sem pendência.
    </p>

    <?php if ($revisao->caixaAjustada() > 0): ?>
        <div class="alerta">
            <?= $revisao->caixaAjustada() ?>
            nome<?= $revisao->caixaAjustada() === 1 ? '' : 's' ?>
            <?= $revisao->caixaAjustada() === 1 ? 'estava' : 'estavam' ?>
            todo<?= $revisao->caixaAjustada() === 1 ? '' : 's' ?> em maiúsculas ou
            minúsculas e <?= $revisao->caixaAjustada() === 1 ? 'foi ajustado' : 'foram ajustados' ?>,
            porque é assim que o nome sai impresso no certificado. O nome colado
            aparece abaixo do ajustado nas tabelas.
            <div class="muted" style="margin-top: 6px;">
                Acentos não são inventados: uma planilha em maiúsculas costuma ter
                perdido os dele. Para gravar um nome exatamente como está escrito,
                use <a href="certificados_nome.php">Corrigir um nome</a> depois.
            </div>
        </div>
    <?php endif; ?>

    <form method="post" action="certificados_lote.php">
        <?= Csrf::campo(FORMULARIO) ?>
        <input type="hidden" name="colado" value="<?= $e($colado) ?>" />
        <input type="hidden" name="lote_alvo" value="<?= $e($loteAlvo) ?>" />
        <?php foreach ($mapeamento->colunas() as $indice => $campo): ?>
            <input type="hidden" name="colunas[<?= (int) $indice ?>]" value="<?= $e($campo) ?>" />
        <?php endforeach; ?>
        <?php foreach ($mapeamento->fixos() as $campo => $valor): ?>
            <input type="hidden" name="fixos[<?= $e((string) $campo) ?>]" value="<?= $e($valor) ?>" />
        <?php endforeach; ?>

        <?php if ($revisao->erros !== []): ?>
            <h2 style="color: var(--erro);">
                <?= count($revisao->erros) ?> linha<?= count($revisao->erros) === 1 ? '' : 's' ?>
                com erro
            </h2>
            <p class="muted">
                Nada é criado enquanto houver um erro aqui. São valores que o sistema
                não consegue usar — corrija na planilha e cole de novo.
            </p>
            <table class="blocos">
                <thead>
                    <tr><th>#</th><th>Evento</th><th>Nome</th><th>Função</th><th>Documento</th><th>Problema</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($revisao->erros as $linha): ?>
                        <tr>
                            <td class="bloco-num"><?= $linha->numero ?></td>
                            <?= $celulasDa($linha) ?>
                            <td class="bloco-decisao" style="color: var(--erro);">
                                <?php foreach ($linha->erros() as $problema): ?>
                                    <div><?= $e($problema->mensagem) ?></div>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ($revisao->avisos !== []): ?>
            <h2 style="color: var(--aviso); margin-top: 28px;">
                <?= count($revisao->avisos) ?> linha<?= count($revisao->avisos) === 1 ? '' : 's' ?>
                pedindo decisão
            </h2>

            <?php if ($grupos !== []): ?>
                <div class="alerta">
                    <strong>Responder de uma vez</strong>
                    <p class="muted">
                        Vale para as linhas que você não responder individualmente.
                    </p>
                    <?php foreach ($grupos as $codigo => $grupo): ?>
                        <div class="field">
                            <label for="lote_<?= $e($codigo) ?>">
                                <?= $e(Revisao::rotuloGrupo($codigo)) ?>
                                <span class="muted">(<?= $grupo['linhas'] ?> linha<?= $grupo['linhas'] === 1 ? '' : 's' ?>)</span>
                            </label>
                            <select id="lote_<?= $e($codigo) ?>" name="lote[<?= $e($codigo) ?>]">
                                <option value="">— decidir linha a linha —</option>
                                <?php foreach ($grupo['problema']->resolucoes as $resolucao): ?>
                                    <option value="<?= $e($resolucao) ?>"
                                        <?= (($_POST['lote'][$codigo] ?? '') === $resolucao) ? 'selected' : '' ?>>
                                        <?= $e(Problema::rotuloResolucao($resolucao)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php /*
                Radio buttons with a sentence explaining each are a form, not
                tabular data, and a fifth of the width renders them as a
                one-word-wide ribbon several screens tall. Below 760px the row
                becomes a block: the five identity fields run together as one
                summary line and the decision gets the whole width underneath.
                The wide table is kept on a desktop, where it works. See
                .blocos in Template.
            */ ?>
            <table class="blocos">
                <thead>
                    <tr><th>#</th><th>Evento</th><th>Nome</th><th>Função</th><th>Documento</th><th>Decisão</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($revisao->avisos as $linha): ?>
                        <tr<?= $linha->estaResolvida() ? '' : ' style="background: #fffaf0;"' ?>>
                            <td class="bloco-num"><?= $linha->numero ?></td>
                            <?= $celulasDa($linha) ?>
                            <td class="bloco-decisao">
                                <?php foreach ($linha->avisos() as $problema): ?>
                                    <div style="margin-bottom: 10px;">
                                        <div style="color: var(--aviso);"><?= $e($problema->mensagem) ?></div>
                                        <?php foreach ($problema->resolucoes as $resolucao): ?>
                                            <label style="font-weight: normal; display: block;">
                                                <input type="radio"
                                                       name="resolucao[<?= $linha->numero ?>][<?= $e($problema->codigo) ?>]"
                                                       value="<?= $e($resolucao) ?>"
                                                       <?= (($_POST['resolucao'][$linha->numero][$problema->codigo] ?? '') === $resolucao) ? 'checked' : '' ?> />
                                                <?= $e(Problema::rotuloResolucao($resolucao)) ?>
    <?php if ($resolucao === Problema::ATUALIZAR_NOME && $problema->alcance() !== ''): ?>
                                                <span class="muted">— <?= $e($problema->alcance()) ?></span>
                                            <?php endif; ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ($revisao->ok !== []): ?>
            <details style="margin-top: 28px;">
                <summary style="cursor: pointer; color: var(--ok); font-size: 18px; font-weight: bold;">
                    <?= count($revisao->ok) ?> linha<?= count($revisao->ok) === 1 ? '' : 's' ?>
                    sem pendência
                </summary>
                <table class="blocos" style="margin-top: 12px;">
                    <thead>
                        <tr><th>#</th><th>Evento</th><th>Nome</th><th>Função</th><th>Documento</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($revisao->ok as $linha): ?>
                            <tr><td class="bloco-num"><?= $linha->numero ?></td><?= $celulasDa($linha) ?></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
        <?php endif; ?>

        <div style="margin-top: 28px;">
            <?php if ($revisao->erros !== []): ?>
                <div class="alerta erro">
                    <?php if ($tentouGravar): ?><strong>Nada foi criado.</strong><?php endif; ?>
                    Corrija <?= count($revisao->erros) ?>
                    linha<?= count($revisao->erros) === 1 ? '' : 's' ?> na planilha
                    e cole de novo — ou crie agora só as que já estão resolvidas.
                </div>
            <?php elseif ($revisao->pendentes() !== []): ?>
                <div class="alerta aviso">
                    <?php if ($tentouGravar): ?><strong>Nada foi criado.</strong><?php endif; ?>
                    <?php $n = count($revisao->pendentes()); ?>
                    <?= $n === 1 ? 'Falta 1 decisão destacada' : "Faltam $n decisões destacadas" ?>
                    acima. Responda e envie de novo.
                </div>
            <?php else: ?>
                <div class="alerta ok">
                    Tudo respondido.
                    <?= $revisao->aCriar() ?> certificado<?= $revisao->aCriar() === 1 ? '' : 's' ?>
                    <?= $revisao->aCriar() === 1 ? 'será criado' : 'serão criados' ?><?php
                        if ($revisao->aIgnorar() > 0): ?>,
                        <?= $revisao->aIgnorar() ?>
                        linha<?= $revisao->aIgnorar() === 1 ? '' : 's' ?>
                        <?= $revisao->aIgnorar() === 1 ? 'será ignorada' : 'serão ignoradas' ?><?php
                        endif; ?>.
                </div>
            <?php endif; ?>

            <button type="submit" name="etapa" value="revisar" class="secundario">Conferir de novo</button>

            <?php if ($revisao->podeGravarParcial()): ?>
                <?php
                // Committing part of a batch is offered because the alternative
                // is worse: an answer nobody has yet — "is this the same
                // Maria?" — holds up every other row in the sheet, and the
                // operator's way out is to edit the paste by hand. The rows
                // that go in are still one transaction with one lote_id, so
                // what landed is as identifiable as ever; the rest come back
                // as a sheet.
                ?>
                <button type="submit" name="etapa" value="gravar_parcial">
                    Criar <?= $revisao->aCriarProntas() ?>
                    resolvida<?= $revisao->aCriarProntas() === 1 ? '' : 's' ?>
                    e deixar <?= count($revisao->naoProntas()) ?> para depois
                </button>
            <?php endif; ?>

            <?php
            // Never disabled. A disabled button is the one control that cannot
            // tell you why it will not work: choosing a radio does not
            // re-enable it, there is no script here to do so, and pressing it
            // does nothing at all — which is exactly how somebody answers a
            // question and then finds the page ignoring them. It submits, and
            // the server, which re-validates anyway, says what is missing.
            ?>
            <button type="submit" name="etapa" value="gravar">
                Criar <?= $revisao->aCriar() ?> certificado<?= $revisao->aCriar() === 1 ? '' : 's' ?>
            </button>
        </div>
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
