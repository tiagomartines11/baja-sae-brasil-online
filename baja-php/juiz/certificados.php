<?php

namespace Baja\Juiz;

use Baja\Certificado\Funcao;
use Baja\Certificado\Insercao\Acesso;
use Baja\Certificado\Insercao\Csrf;
use Baja\Certificado\Insercao\Gravador;
use Baja\Certificado\Insercao\Linha;
use Baja\Certificado\Insercao\Problema;
use Baja\Certificado\Insercao\Resultado;
use Baja\Certificado\Insercao\Template;
use Baja\Certificado\Insercao\Validador;
use Baja\Model\EventoQuery;

/**
 * Single-entry certificate creation: one participant, one row.
 *
 * The simple case, and the one that exercises the whole validation service
 * with no parsing in front of it. Everything it decides, the paste flow
 * decides the same way, because both call the same service.
 */

$usuario = Acesso::exigir();

const FORMULARIO = 'certificado-individual';

$eventos = EventoQuery::create()->orderByEventoId('desc')->find();

/** @var Linha|null $linha */
$linha     = null;
$resultado = null;
$erroCsrf  = false;

$enviado = [
    'evento'    => (string) ($_POST['evento'] ?? ''),
    'nome'      => (string) ($_POST['nome'] ?? ''),
    'funcao'    => (string) ($_POST['funcao'] ?? ''),
    'documento' => (string) ($_POST['documento'] ?? ''),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::postValido(FORMULARIO)) {
        // Never act on it, and never explain more than this. The page is
        // reachable again by reloading it.
        $erroCsrf = true;
    } else {
        // The resolutions the operator chose on the previous pass, if this is
        // the second POST. Re-validated from the submitted values rather than
        // trusted from the form: nothing about the row's state survives the
        // round trip except the answers, and the answers are checked against
        // the problems this pass actually found.
        $escolhas = [];
        foreach ((array) ($_POST['resolucao'] ?? []) as $codigo => $escolha) {
            $escolhas[(string) $codigo] = (string) $escolha;
        }

        $validador = new Validador();
        $linha     = $validador->validar([$enviado], [1 => $escolhas])[0];

        if ($linha->podeGravar() && ($_POST['confirmar'] ?? '') === '1') {
            $gravador  = new Gravador((int) $usuario->getUserId());
            $resultado = $gravador->gravar([$linha]);
            $linha     = null;
            $enviado   = ['evento' => $enviado['evento'], 'nome' => '', 'funcao' => '', 'documento' => ''];
        }
    }
}

Template::printHeader('Novo certificado', $usuario);

$e = fn(string $v): string => Template::e($v);

if ($resultado instanceof Resultado): ?>
    <div class="alerta ok">
        <strong>
            <?php if ($resultado->criadas > 0): ?>
                Certificado criado.
            <?php elseif ($resultado->atualizadas > 0): ?>
                Registro existente atualizado.
            <?php else: ?>
                Nada foi criado — a linha foi marcada para ignorar.
            <?php endif; ?>
        </strong>
        <?php if ($resultado->nomesCorrigidos > 0): ?>
            O nome também foi corrigido em <?= (int) $resultado->nomesCorrigidos ?>
            outro<?= $resultado->nomesCorrigidos === 1 ? '' : 's' ?>
            registro<?= $resultado->nomesCorrigidos === 1 ? '' : 's' ?> desta pessoa.
        <?php endif; ?>
        <?php if ($resultado->criadas > 0): ?>
        <br />
        <span class="muted">Lote <code><?= $e($resultado->loteId) ?></code> —
        <a href="lote.php?id=<?= urlencode($resultado->loteId) ?>">ver o que foi criado</a></span>
        <?php endif; ?>
    </div>
<?php endif;

if ($erroCsrf): ?>
    <div class="alerta erro">
        A sessão do formulário expirou e nada foi salvo. Confira os dados e envie de novo.
    </div>
<?php endif; ?>

<div class="card">
    <h1>Novo certificado</h1>
    <p class="muted">
        Um participante por vez. Para uma planilha inteira, use a
        <a href="certificados_lote.php">inserção em lote</a>.
    </p>

    <form method="post" action="certificados.php">
        <?= Csrf::campo(FORMULARIO) ?>

        <div class="campos">
            <div class="field">
                <label for="evento">Evento</label>
                <select id="evento" name="evento" required>
                    <option value="">— escolha o evento —</option>
                    <?php foreach ($eventos as $evento): ?>
                        <option value="<?= $e((string) $evento->getEventoId()) ?>"
                            <?= $enviado['evento'] === (string) $evento->getEventoId() ? 'selected' : '' ?>>
                            <?= $e((string) $evento->getEventoId()) ?> —
                            <?= $e(html_entity_decode((string) $evento->getNome(), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="funcao">Função</label>
                <select id="funcao" name="funcao" required>
                    <option value="">— escolha a função —</option>
                    <?php foreach (Funcao::selectable() as $codigo => $rotulo): ?>
                        <option value="<?= $e($codigo) ?>" <?= $enviado['funcao'] === $codigo ? 'selected' : '' ?>>
                            <?= $e($rotulo) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="field">
            <label for="nome">Nome completo</label>
            <input type="text" id="nome" name="nome" required maxlength="300"
                   value="<?= $e($enviado['nome']) ?>" />
            <p class="muted">
                Como deve sair impresso no certificado. A busca pública exige dois nomes,
                então um nome só deixa o certificado inacessível para quem o recebeu.
            </p>
        </div>

        <div class="field">
            <label for="documento">CPF ou passaporte</label>
            <input type="text" id="documento" name="documento" required maxlength="32"
                   value="<?= $e($enviado['documento']) ?>" />
            <p class="muted">
                Um CPF pode vir com ou sem pontuação, e com ou sem os zeros à esquerda.
                Para quem não tem CPF, o número do passaporte.
            </p>
        </div>

        <?php if ($linha !== null && $linha->problemas() !== []): ?>
            <?php foreach ($linha->erros() as $problema): ?>
                <div class="alerta erro"><?= $e($problema->mensagem) ?></div>
            <?php endforeach; ?>

            <?php foreach ($linha->avisos() as $problema): ?>
                <div class="alerta aviso">
                    <?= $e($problema->mensagem) ?>
                    <div style="margin-top: 8px;">
                        <?php foreach ($problema->resolucoes as $resolucao): ?>
                            <label style="display:block; font-weight: normal;">
                                <input type="radio"
                                       name="resolucao[<?= $e($problema->codigo) ?>]"
                                       value="<?= $e($resolucao) ?>"
                                       <?= $linha->resolucao($problema->codigo) === $resolucao ? 'checked' : '' ?> />
                                <?= $e(Problema::rotuloResolucao($resolucao)) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php
        // The confirm step only appears once there is nothing left to decide.
        // Until then the button re-validates, so the operator never sees
        // "created" next to a warning they had not answered.
        $pronta = $linha !== null && $linha->podeGravar();
        ?>
        <?php if ($pronta): ?>
            <div class="alerta ok">
                <?= $linha->problemas() === []
                    ? 'Nada a resolver. Confira os dados acima e confirme.'
                    : 'Tudo respondido. Confira os dados acima e confirme.' ?>
            </div>
            <input type="hidden" name="confirmar" value="1" />
            <button type="submit">Criar certificado</button>
            <span class="muted" style="margin-left: 12px;">
                <?= $linha->ehIgnorada() ? 'Nada será criado: esta linha foi marcada para ignorar.' : '' ?>
            </span>
        <?php else: ?>
            <button type="submit"><?= $linha === null ? 'Conferir' : 'Conferir de novo' ?></button>
        <?php endif; ?>
    </form>
</div>

<?php Template::printFooter();
