<?php
namespace Baja\Juiz;

use Baja\Contagem\Access\ContagemAccess;
use Baja\Contagem\Access\CounterLinks;
use Baja\Contagem\Access\PitLoggers;
use Baja\Contagem\Engine\Data\Source;
use Baja\Contagem\Review\Pagina;
use Baja\Contagem\Review\SourceAdmin;
use Baja\Contagem\Service\ContagemService;
use InvalidArgumentException;

// "Usuários": who counts on which source, and who logs the pit lane and the
// fueling station. Open to the prova's controller, who does not need to be
// an admin: the only things this page can grant are a counter link for this
// prova and this prova's pit log code. The file keeps its old name
// (contagem_contadores.php) so that bookmarks keep working.

$prova = Pagina::provaOrRedirect();
ContagemAccess::requireController($prova);

$errorMessage = null;
$messages = [
    'vinculado' => 'Usuário vinculado.',
    'removido' => 'Vínculo removido. Os dispositivos dessa pessoa nesta fonte foram revogados.',
    'box_adicionado' => 'Usuário adicionado ao registro de box e abastecimento.',
    'box_removido' => 'Usuário removido do registro de box e abastecimento. Os aparelhos dele no box foram revogados.',
];

if (Pagina::isPost()) {
    Pagina::requireCsrfPost($prova);
    try {
        $action = $_POST['acao'] ?? '';
        $username = is_string($_POST['usuario'] ?? null) ? trim($_POST['usuario']) : '';
        if ($action === 'box_adicionar' || $action === 'box_remover') {
            if ($username === '' || mb_strlen($username) > 45) {
                throw new InvalidArgumentException('Informe o nome de usuário do fórum.');
            }
            // Throws InvalidArgumentException, in Portuguese, when the forum does not know the username.
            $action === 'box_adicionar' ? PitLoggers::add($prova, $username) : PitLoggers::remove($prova, $username);
            Pagina::redirectAfterPost('contagem_contadores.php', $prova, ['msg' => $action === 'box_adicionar' ? 'box_adicionado' : 'box_removido']);
        }
        $fonte = SourceAdmin::requireSource($prova, $_POST['fonte_id'] ?? null);
        if ($fonte->getTipo() !== Source::TIPO_MANUAL) {
            throw new InvalidArgumentException('Só fontes manuais têm contadores.');
        }
        if ($username === '' || mb_strlen($username) > 45) {
            throw new InvalidArgumentException('Informe o nome de usuário do fórum.');
        }

        if ($action === 'vincular') {
            // Throws InvalidArgumentException, in Portuguese, when the forum does not know the username.
            CounterLinks::link($fonte, $username, Pagina::username());
            Pagina::redirectAfterPost('contagem_contadores.php', $prova, ['msg' => 'vinculado']);
        }
        if ($action === 'remover') {
            CounterLinks::unlink($fonte, $username);
            Pagina::redirectAfterPost('contagem_contadores.php', $prova, ['msg' => 'removido']);
        }
        throw new InvalidArgumentException('Ação desconhecida.');
    } catch (InvalidArgumentException $invalid) {
        $errorMessage = $invalid->getMessage();
    }
}

$h = [Pagina::class, 'h'];
$messageCode = is_string($_GET['msg'] ?? null) ? $_GET['msg'] : '';
$confirmation = $messages[$messageCode] ?? null;
$action = 'contagem_contadores.php?p=' . rawurlencode($prova->getProvaId());
$csrf = Pagina::csrfField($prova);
$manualSources = SourceAdmin::manualSources($prova);

$pitLoggers = PitLoggers::listForProva($prova);
$fuelingIsLogged = ContagemService::forProva($prova)->getSettings()->abastecimento;

Pagina::printHeader($prova, 'Usuários');
?>
<?php if ($confirmation !== null) { ?><p class="contagem-ok"><?= $h($confirmation) ?></p><?php } ?>
<?php if ($errorMessage !== null) { ?><p class="contagem-erro"><?= $h($errorMessage) ?></p><?php } ?>

<p class="contagem-dica">
    Cada pessoa só consegue abrir a contagem das fontes em que está vinculada, e o registro de box e abastecimento
    só se estiver na lista dele. Use o nome de usuário do fórum, exatamente como a pessoa faz login.
</p>

<?php if ($manualSources === []) { ?>
    <p>Nenhuma fonte manual nesta prova. Crie uma em
        <a href="contagem_fontes.php?p=<?= $h(rawurlencode($prova->getProvaId())) ?>">Fontes e configurações</a>.</p>
<?php } ?>

<?php foreach ($manualSources as $fonte) {
    $linkedUsers = CounterLinks::listForSource($fonte); ?>
    <section class="contagem-bloco">
        <h2><?= $h($fonte->getNome()) ?><?= $fonte->getAtiva() ? '' : ' <small>(inativa)</small>' ?></h2>
        <?php if ($linkedUsers === []) { ?>
            <p>Ninguém vinculado ainda.</p>
        <?php } else { ?>
            <table class="tablesorter contagem-tabela">
                <thead><tr><th>Usuário</th><th>Visto contando pela última vez</th><th class="sorter-false"></th></tr></thead>
                <tbody>
                <?php foreach ($linkedUsers as $linked) { ?>
                    <tr>
                        <td><?= $h($linked['usuario']) ?></td>
                        <td><?= $linked['visto_em_ms'] === null ? 'nunca' : $h(Pagina::formatClock((int) $linked['visto_em_ms'])) ?></td>
                        <td>
                            <form method="POST" action="<?= $h($action) ?>"
                                  onsubmit="return confirm('Remover este usuário da fonte? Os dispositivos dele nesta fonte deixam de enviar.');">
                                <?= $csrf ?>
                                <input type="hidden" name="acao" value="remover">
                                <input type="hidden" name="fonte_id" value="<?= (int) $fonte->getFonteId() ?>">
                                <input type="hidden" name="usuario" value="<?= $h($linked['usuario']) ?>">
                                <button type="submit">remover</button>
                            </form>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        <?php } ?>
        <form method="POST" action="<?= $h($action) ?>" class="contagem-acao">
            <?= $csrf ?>
            <input type="hidden" name="acao" value="vincular">
            <input type="hidden" name="fonte_id" value="<?= (int) $fonte->getFonteId() ?>">
            <label>Adicionar usuário:
                <input type="text" name="usuario" maxlength="45" required autocomplete="off" autocapitalize="none"
                       placeholder="usuário do fórum">
            </label>
            <button type="submit">Adicionar</button>
        </form>
    </section>
<?php } ?>
<section class="contagem-bloco">
    <h2><?= $h(ContagemAccess::pitLogLabel($prova)) ?></h2>
    <p class="contagem-dica">
        Quem registra a entrada e a saída do box<?= $fuelingIsLogged ? ' e do abastecimento' : '' ?> no celular.
        O responsável pela prova também pode, sem estar na lista. Estar aqui não dá acesso à contagem de nenhuma fonte.
    </p>
    <?php if ($pitLoggers === []) { ?>
        <p>Ninguém adicionado ainda.</p>
    <?php } else { ?>
        <table class="tablesorter contagem-tabela">
            <thead><tr><th>Usuário</th><th>Visto no box pela última vez</th><th class="sorter-false"></th></tr></thead>
            <tbody>
            <?php foreach ($pitLoggers as $pitLogger) { ?>
                <tr>
                    <td><?= $h($pitLogger['usuario']) ?></td>
                    <td><?= $pitLogger['visto_em_ms'] === null ? 'nunca' : $h(Pagina::formatClock((int) $pitLogger['visto_em_ms'])) ?></td>
                    <td>
                        <form method="POST" action="<?= $h($action) ?>"
                              onsubmit="return confirm('Remover este usuário do registro de box e abastecimento? Os aparelhos dele no box deixam de enviar.');">
                            <?= $csrf ?>
                            <input type="hidden" name="acao" value="box_remover">
                            <input type="hidden" name="usuario" value="<?= $h($pitLogger['usuario']) ?>">
                            <button type="submit">remover</button>
                        </form>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    <?php } ?>
    <form method="POST" action="<?= $h($action) ?>" class="contagem-acao">
        <?= $csrf ?>
        <input type="hidden" name="acao" value="box_adicionar">
        <label>Adicionar usuário:
            <input type="text" name="usuario" maxlength="45" required autocomplete="off" autocapitalize="none"
                   placeholder="usuário do fórum">
        </label>
        <button type="submit">Adicionar</button>
    </form>
</section>
<?php
Pagina::printFooter();
