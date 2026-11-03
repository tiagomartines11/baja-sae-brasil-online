<?php
namespace Baja\Juiz;

use Baja\Auth\Csrf;
use Baja\Programa\ProgramaAdmin;
use Baja\Programa\ProgramaRecusado;
use Baja\Rbac\Authorization;
use Baja\Rbac\Permission\Permission;
use Baja\Rbac\Scope\ScopeNode;
use Baja\Site\Html;

// The competition programs (BAJAAPP-90): the list, a new one (?novo=true),
// one program's settings (?id=CODE). Only for programa.manage on the
// platform: a program's parent decides whose grants reach it.
//
// Baja\Programa\ProgramaAdmin keeps the rules (the code never changes, no
// loops, no delete, an audit row for every change); this page shows them.

$user = Authorization::require(Permission::PROGRAMA_MANAGE, ScopeNode::platform());

// Every act writes: refused without the form's token, before anything else.
if (isset($_REQUEST['act'])) Csrf::enforce();

$admin = ProgramaAdmin::forApp();
$programas = $admin->all();
$porCodigo = array_column($programas, null, 'programa_id');

$novo = ($_REQUEST['novo'] ?? '') === 'true';
$codigo = is_string($_REQUEST['id'] ?? null) ? $_REQUEST['id'] : null;
if ($codigo !== null && !isset($porCodigo[$codigo])) { header("Location: admin_programas.php"); exit; }
$programa = $codigo === null ? null : $porCodigo[$codigo];

$texto = fn(string $campo): string => is_string($_POST[$campo] ?? null) ? $_POST[$campo] : '';
$erro = null;
// Set when the save would move the program under another parent and the
// person has not confirmed yet: the effect, in words.
$confirmar = null;

// The order is a whole number; anything else is refused before saving.
$ordemDoFormulario = function () use ($texto, &$erro): ?int {
    $ordem = filter_var(trim($texto('ordem')), FILTER_VALIDATE_INT);
    if ($ordem === false) {
        $erro = 'A ordem deve ser um número inteiro.';
        return null;
    }
    return $ordem;
};

if ($novo && @$_REQUEST['act'] === 'create') {
    $ordem = $ordemDoFormulario();
    if ($ordem !== null) {
        try {
            $admin->create((int) $user->getUserId(), trim($texto('programa_id')), $texto('nome'), $texto('parent_id'), $ordem);
            header("Location: admin_programas.php?ok=criado"); exit;
        } catch (ProgramaRecusado $recusado) {
            $erro = $recusado->getMessage();
        }
    }
}

if ($programa !== null && @$_REQUEST['act'] === 'update') {
    $ordem = $ordemDoFormulario();
    $novoPai = $texto('parent_id') === '' ? null : $texto('parent_id');
    if (isset($_POST['programa_id']) && $_POST['programa_id'] !== $programa['programa_id']) {
        // The form never sends the code. A request that does is trying to
        // rename the program, which would orphan its grants and audit rows.
        $erro = 'O código de um programa não pode ser mudado: os papéis dados nele e o registro de auditoria guardam o código. Crie um programa novo, se for o caso.';
    } elseif ($ordem !== null) {
        // The parent the person confirmed, sent back by the confirmation
        // step ('' for the top). Changing the parent again asks again.
        $paiConfirmado = is_string($_POST['confirmar_pai'] ?? null) ? $_POST['confirmar_pai'] : null;
        if ($novoPai !== $programa['parent_id'] && $paiConfirmado !== ($novoPai ?? '')) {
            // Moving a program changes who may do what in it: ask first.
            $eventos = $admin->eventsMovedWith($programa['programa_id']);
            $confirmar = 'Mudar o programa de cima: '
                . ($novoPai === null
                    ? $programa['programa_id'] . ' passa a ficar no topo, e só os papéis dados na plataforma o alcançarão.'
                    : 'quem tem papel em ' . $novoPai . ' (ou acima dele) passará a ter esse papel também em ' . $programa['programa_id'] . ' e nos seus eventos.')
                . ($programa['parent_id'] === null ? '' : ' Os papéis dados em ' . $programa['parent_id'] . ' deixarão de valer para ele.')
                . ' Isso afeta ' . $eventos . ($eventos === 1 ? ' evento' : ' eventos') . ' (os deste programa e dos que estão abaixo dele).';
        } else {
            try {
                $admin->update((int) $user->getUserId(), $programa['programa_id'], $texto('nome'), $novoPai, $ordem, ($_POST['ativo'] ?? '') === '1');
                header("Location: admin_programas.php?id=" . rawurlencode($programa['programa_id']) . "&ok=salvo"); exit;
            } catch (ProgramaRecusado $recusado) {
                $erro = $recusado->getMessage();
            }
        }
    }
}

// What the form shows: as typed after a refusal or before a confirmation,
// otherwise as stored.
$enviado = isset($_REQUEST['act']);
$valor = fn(string $campo, $salvo): string => $enviado ? $texto($campo) : (string) $salvo;
$ativoMarcado = $enviado ? ($_POST['ativo'] ?? '') === '1' : ($programa['ativo'] ?? true);

// An inactive program, as the code reads programa.ativo today.
$sobreInativo = 'Um programa inativo não é oferecido em evento.php para um evento novo ou para mudar o programa de um evento; os eventos que já estão nele continuam nele. Nada mais muda: os papéis dados nele continuam valendo, e ele continua na lista de lugares da página de papéis.';

Template::printHeader("Programas", false, false);

?>
<div style="max-width: 1000px; margin: 0 auto; height:100vh;">
<?php if (!$novo && $programa === null) { ?>

    <table id="programas" class="tablesorter">
        <thead>
            <tr style="height: 50px;">
                <th colspan="7" class="sorter-false">
                    <span style="float:left;"><a href="admin.php" style="color: white; font-size: 12px;">&nbsp;Voltar</a></span>
                    <span style="font-size: 28px;">Programas</span>
                </th>
            </tr>
            <tr>
                <th>Código</th>
                <th>Nome</th>
                <th>Acima</th>
                <th>Ordem</th>
                <th>Ativo</th>
                <th>Eventos</th>
                <th class="sorter-false">Detalhes</th>
            </tr>
        </thead>
        <tbody>
<?php if (($_GET['ok'] ?? '') === 'criado') { ?>
            <tr><td colspan="7" id="ok" style="color: green; font-weight: bold;">Programa criado.</td></tr>
<?php } ?>
<?php foreach ($programas as $p) { ?>
            <tr data-programa="<?= Html::e($p['programa_id']) ?>">
                <td><?= Html::e($p['programa_id']) ?></td>
                <td><?= Html::e($p['nome']) ?></td>
                <td><?= Html::e($p['parent_id'] ?? '') ?></td>
                <td><?= (int) $p['ordem'] ?></td>
                <td><?= $p['ativo'] ? 'sim' : 'não' ?></td>
                <td><?= (int) $p['eventos'] ?></td>
                <td><a href="admin_programas.php?id=<?= Html::e(rawurlencode($p['programa_id'])) ?>">&nbsp;✏️&nbsp;</a></td>
            </tr>
<?php } ?>
        </tbody>
        <tfoot>
            <tr>
                <th colspan="7" style="height: 30px">
                    <input type="button" onclick="location.href='admin_programas.php?novo=true';" value="Novo Programa"/>
                </th>
            </tr>
            <tr>
                <th colspan="7" style="font-size: 12px; font-weight: normal;"><?= Html::e($sobreInativo) ?></th>
            </tr>
        </tfoot>
    </table>

<?php } else { ?>

<?php if ($novo) { ?>
    <form action="admin_programas.php?novo=true&amp;act=create" method="POST">
<?php } else { ?>
    <form action="admin_programas.php?id=<?= Html::e(rawurlencode($programa['programa_id'])) ?>&amp;act=update" method="POST">
<?php } ?>
        <?= Csrf::field() ?>
        <table id="programa" class="tablesorter" style="margin-bottom: 0;">
            <thead>
                <tr style="height: 50px">
                    <th colspan="2" style="vertical-align: middle;" class="sorter-false">
                        <span style="float:left"><a href="admin_programas.php" style="color: white; font-size: 12px;">&nbsp;Voltar</a></span>
                        <span style="font-size: 28px;"><?= $novo ? 'Novo Programa' : Html::e($programa['nome']) ?></span>
                    </th>
                </tr>
            </thead>
            <tbody>
                <?php if ($erro !== null) { ?>
                <tr>
                    <td colspan="2" id="erro" style="color: red; font-weight: bold;">Programa não salvo: <?= Html::e($erro) ?></td>
                </tr>
                <?php } elseif ($confirmar !== null) { ?>
                <tr>
                    <td colspan="2" id="confirmar" style="color: #a60; font-weight: bold;"><?= Html::e($confirmar) ?> Confira e clique em "Confirmar e salvar", ou em Voltar para desistir.</td>
                </tr>
                <?php } elseif (($_GET['ok'] ?? '') === 'salvo') { ?>
                <tr>
                    <td colspan="2" id="ok" style="color: green; font-weight: bold;">Programa salvo.</td>
                </tr>
                <?php } ?>
                <tr>
                    <td>Código</td>
<?php if ($novo) { ?>
                    <td><input style="width:300px;" type="text" name="programa_id" maxlength="16" pattern="[A-Z0-9_]{1,16}" required value="<?= Html::field($texto('programa_id')) ?>" />
                        <span style="font-size: 12px; margin-left: 8px;">Letras maiúsculas, dígitos e "_", até 16. Não pode ser mudado depois.</span></td>
<?php } else { ?>
                    <?php // Shown, never sent: the code cannot change (ProgramaAdmin). ?>
                    <td><input style="width:300px;" type="text" disabled value="<?= Html::field($programa['programa_id']) ?>" />
                        <span style="font-size: 12px; margin-left: 8px;">Não pode ser mudado: os papéis dados neste programa guardam o código.</span></td>
<?php } ?>
                </tr>
                <tr>
                    <td>Nome</td>
                    <td><input style="width:700px;" type="text" name="nome" maxlength="<?= ProgramaAdmin::NAME_MAX ?>" required value="<?= Html::field($valor('nome', $programa['nome'] ?? '')) ?>" /></td>
                </tr>
                <tr>
                    <td>Acima</td>
                    <td><select name="parent_id">
                        <option value="">(nenhum: no topo)</option>
                        <?php
                        // Every program but this one; the loop check on save refuses one of its own descendants.
                        $paiEscolhido = $valor('parent_id', $programa['parent_id'] ?? '');
                        foreach ($programas as $p) {
                            if ($programa !== null && $p['programa_id'] === $programa['programa_id']) continue; ?>
                        <option value="<?= Html::e($p['programa_id']) ?>" <?= $p['programa_id'] === $paiEscolhido ? 'selected' : '' ?>><?= Html::e($p['programa_id'] . ' — ' . $p['nome']) ?></option>
                        <?php } ?>
                    </select>
                        <span style="font-size: 12px; margin-left: 8px;">Quem tem papel no programa de cima tem esse papel também neste e nos seus eventos.</span></td>
                </tr>
                <tr>
                    <td>Ordem</td>
                    <td><input type="number" name="ordem" step="1" required value="<?= Html::field($valor('ordem', $programa['ordem'] ?? 0)) ?>" />
                        <span style="font-size: 12px; margin-left: 8px;">Posição nas listas de programas (menor primeiro).</span></td>
                </tr>
<?php if (!$novo) { ?>
                <tr>
                    <td>Ativo?</td>
                    <td><input type="checkbox" name="ativo" value="1" <?= $ativoMarcado ? 'checked' : '' ?> />
                        <span style="font-size: 12px; margin-left: 8px;"><?= Html::e($sobreInativo) ?> Não há como apagar um programa.</span></td>
                </tr>
                <tr>
                    <td>Eventos</td>
                    <td><?= (int) $programa['eventos'] ?></td>
                </tr>
<?php } ?>
            </tbody>
            <tfoot>
                <tr>
<?php if ($confirmar !== null) { ?>
                    <th style="height: 30px;" colspan="2"><input type="hidden" name="confirmar_pai" value="<?= Html::field($texto('parent_id')) ?>" /><input type="submit" value="Confirmar e salvar" /></th>
<?php } else { ?>
                    <th style="height: 30px;" colspan="2"><input type="submit" value="<?= $novo ? 'Salvar' : 'Atualizar' ?>" /></th>
<?php } ?>
                </tr>
            </tfoot>
        </table>
    </form>

<?php } ?>
</div>

<?php
Template::printFooter();
