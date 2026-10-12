<?php
namespace Baja\Juiz;

use Baja\Contagem\Access\ContagemAccess;
use Baja\Contagem\Review\Pagina;
use Baja\Contagem\Review\SourceAdmin;
use Baja\Contagem\Service\ContagemService;
use Baja\Contagem\Service\LockBusy;
use Baja\Session;

// Entry point of the lap counting area. Decides where each kind of user
// goes: the controller gets the hub, a counter goes to the source(s) a
// controller linked them to and to nothing else.

Session::permissionCheck('index');

$prova = Pagina::provaOrRedirect();
$h = [Pagina::class, 'h'];
$query = '?p=' . rawurlencode($prova->getProvaId());

if (ContagemAccess::isController($prova)) {
    $service = ContagemService::forProva($prova);
    $errorMessage = null;
    if (Pagina::isPost()) {
        Pagina::requireCsrfPost($prova);
        if (($_POST['acao'] ?? '') === 'marcar_aptos') {
            try {
                // Only blank fields are filled. A car somebody marked NÃO
                // was looked at and refused: it stays as it is and is named.
                $outcome = $service->markAllPresentAsClassified(Pagina::username());
                Pagina::redirectAfterPost('contagem.php', $prova, [
                    'aptos' => (string) count($outcome['marcados']),
                    'mantidos' => implode(',', $outcome['ja_nao']),
                ]);
            } catch (LockBusy $busy) {
                $errorMessage = 'A contagem está ocupada com outra operação. Nada foi alterado: tente de novo.';
            }
        }
    }
    $roster = $service->carRoster();
    $classificationField = $roster->classificationField();
    $markedNow = filter_var($_GET['aptos'] ?? null, FILTER_VALIDATE_INT);
    // Car numbers only: this comes back from the URL.
    $leftAloneNow = is_string($_GET['mantidos'] ?? null) && preg_match('/^\d{1,5}(,\d{1,5})*$/', $_GET['mantidos']) === 1
        ? explode(',', $_GET['mantidos'])
        : [];

    Pagina::printHeader($prova, 'Contagem');
    ?>
    <?php if ($errorMessage !== null) { ?><p class="contagem-erro"><?= $h($errorMessage) ?></p><?php } ?>
    <?php if ($markedNow !== false && $markedNow !== null) { ?>
        <p class="contagem-ok"><?= (int) $markedNow ?> carro(s) marcado(s) como apto(s).<?= $markedNow > 0 ? ' A contagem foi recalculada.' : '' ?>
            <?php if ($leftAloneNow !== []) { ?>
                <strong>Não alterado(s), porque o campo já estava preenchido com outro valor (por exemplo NÃO): <?= $h(implode(', ', $leftAloneNow)) ?>.</strong>
            <?php } ?>
        </p>
    <?php } ?>
    <?php if ($classificationField !== null) {
        $unclassified = $roster->unclassified();
        // Not classified for two different reasons: nobody filled the field
        // in (the button below does), or somebody filled in something that
        // is not SIM (the button leaves those alone).
        $markedNo = array_values(array_intersect($roster->markedOtherwise(), array_keys($unclassified)));
        $blank = array_values(array_diff(array_keys($unclassified), $markedNo)); ?>
        <section class="contagem-bloco">
            <h2>Carros classificados</h2>
            <p>
                Participa da corrida o carro presente e com <strong><?= $h($classificationField) ?> = SIM</strong>:
                <strong><?= count($roster->classified()) ?></strong> classificado(s),
                <strong><?= count($blank) ?></strong> presente(s) com o campo em branco<?= $blank === [] ? '' : ' (' . $h(implode(', ', $blank)) . ')' ?>.
                Uma passagem digitada para um carro não classificado é guardada, mas não conta e gera um alerta na revisão.
            </p>
            <?php if ($markedNo !== []) { ?>
                <p class="contagem-aviso">
                    <strong><?= count($markedNo) ?></strong> presente(s) marcado(s) como NÃO apto(s) (campo preenchido com outro valor que não SIM):
                    <strong><?= $h(implode(', ', $markedNo)) ?></strong>.
                    O botão abaixo NÃO altera esses carros; para liberar um deles, edite o campo dele em Campos manuais.
                </p>
            <?php } ?>
            <form method="POST" action="contagem.php<?= $h($query) ?>" class="contagem-acao"
                  onsubmit="return confirm(<?= $h(json_encode('Marcar como apto cada carro presente com o campo em branco (' . count($blank) . ')? Carros marcados NÃO não são alterados. Cada alteração fica no histórico da prova.')) ?>);">
                <?= Pagina::csrfField($prova) ?>
                <input type="hidden" name="acao" value="marcar_aptos">
                <button type="submit" <?= $blank === [] ? 'disabled' : '' ?>>Marcar todos os presentes como aptos</button>
                <span class="contagem-dica">Para desmarcar um carro, edite o campo <?= $h($classificationField) ?> dele em
                    <a href="dashboard.php<?= $h($query) ?>">Campos manuais</a>.</span>
            </form>
        </section>
    <?php } ?>
    <section class="contagem-bloco">
        <h2>Contagem de voltas</h2>
        <ul class="contagem-links">
            <li><a href="contagem_revisao.php<?= $h($query) ?>">Revisão</a> <span>alertas, tabela de carros e decisões</span></li>
            <li><a href="contagem_controle.php<?= $h($query) ?>">Controle de corrida</a> <span>largada, bandeira vermelha, encerramento</span></li>
            <li><a href="contagem_contadores.php<?= $h($query) ?>">Contadores</a> <span>quem conta em cada fonte</span></li>
            <li><a href="contagem_fontes.php<?= $h($query) ?>">Fontes e configurações</a></li>
            <li><a href="contagem_box.php<?= $h($query) ?>">Registro de box<?= $service->getSettings()->abastecimento ? ' e abastecimento' : '' ?></a> <span>entrada e saída, uma pessoa em cada ponta</span></li>
            <li><a href="dashboard.php<?= $h($query) ?>">Campos manuais</a> <span>desclassificação e outros campos digitados</span></li>
        </ul>
    </section>
    <section class="contagem-bloco">
        <h2>Contar como…</h2>
        <?php $manualSources = SourceAdmin::manualSources($prova, true); ?>
        <?php if ($manualSources === []) { ?>
            <p>Nenhuma fonte manual ativa. Crie uma em <a href="contagem_fontes.php<?= $h($query) ?>">Fontes e configurações</a>.</p>
        <?php } else { ?>
            <ul class="contagem-links">
                <?php foreach ($manualSources as $fonte) { ?>
                    <li><a href="contagem_contar.php<?= $h($query) ?>&amp;f=<?= (int) $fonte->getFonteId() ?>"><?= $h($fonte->getNome()) ?></a></li>
                <?php } ?>
            </ul>
        <?php } ?>
    </section>
    <?php
    Pagina::printFooter();
    exit;
}

if (!ContagemAccess::isCounter($prova)) {
    die('Você não tem acesso a essa página');
}

$linkedSources = array_values(ContagemAccess::linkedSources($prova, Pagina::username()));

if (count($linkedSources) === 1) {
    // Nothing to choose: straight to counting.
    header('Location: contagem_contar.php' . $query . '&f=' . (int) $linkedSources[0]->getFonteId());
    exit;
}

Template::printHeader('Contagem - ' . $prova->getNome(), false);
echo Pagina::stylesheetTag();
?>
<div class="contagem-pagina">
    <section class="contagem-bloco">
        <h2><?= $h($prova->getNome()) ?></h2>
        <?php if ($linkedSources === []) { ?>
            <p class="contagem-aviso">Você não está vinculado a nenhuma contagem. Procure o responsável pela prova.</p>
        <?php } else { ?>
            <p>Escolha a contagem em que você vai trabalhar:</p>
            <ul class="contagem-links contagem-links-grandes">
                <?php foreach ($linkedSources as $fonte) { ?>
                    <li><a href="contagem_contar.php<?= $h($query) ?>&amp;f=<?= (int) $fonte->getFonteId() ?>"><?= $h($fonte->getNome()) ?></a></li>
                <?php } ?>
            </ul>
        <?php } ?>
        <p><a href="index.php">Voltar</a></p>
    </section>
</div>
<?php
Template::printFooter();
