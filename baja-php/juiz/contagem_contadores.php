<?php
namespace Baja\Juiz;

use Baja\Contagem\Access\ContagemAccess;
use Baja\Contagem\Access\CounterLinks;
use Baja\Contagem\Engine\Data\Source;
use Baja\Contagem\Review\Pagina;
use Baja\Contagem\Review\SourceAdmin;
use InvalidArgumentException;

// Who counts on which source. Open to the prova's controller, who does not
// need to be an admin: the only thing this page can grant is a counter link
// for this prova.

$prova = Pagina::provaOrRedirect();
ContagemAccess::requireController($prova);

$errorMessage = null;
$messages = [
    'vinculado' => 'Usuário vinculado.',
    'removido' => 'Vínculo removido. Os dispositivos dessa pessoa nesta fonte foram revogados.',
];

if (Pagina::isPost()) {
    Pagina::requireCsrfPost($prova);
    try {
        $fonte = SourceAdmin::requireSource($prova, $_POST['fonte_id'] ?? null);
        if ($fonte->getTipo() !== Source::TIPO_MANUAL) {
            throw new InvalidArgumentException('Só fontes manuais têm contadores.');
        }
        $username = is_string($_POST['usuario'] ?? null) ? trim($_POST['usuario']) : '';
        if ($username === '' || mb_strlen($username) > 45) {
            throw new InvalidArgumentException('Informe o nome de usuário do fórum.');
        }

        $action = $_POST['acao'] ?? '';
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

Pagina::printHeader($prova, 'Contadores');
?>
<?php if ($confirmation !== null) { ?><p class="contagem-ok"><?= $h($confirmation) ?></p><?php } ?>
<?php if ($errorMessage !== null) { ?><p class="contagem-erro"><?= $h($errorMessage) ?></p><?php } ?>

<p class="contagem-dica">
    Cada pessoa só consegue abrir a contagem das fontes em que está vinculada.
    Use o nome de usuário do fórum, exatamente como a pessoa faz login.
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
<?php
Pagina::printFooter();
