<?php
namespace Baja\Juiz;

use Baja\Contagem\Access\ContagemAccess;
use Baja\Contagem\Engine\Data\Source;
use Baja\Contagem\Review\Pagina;
use Baja\Contagem\Review\SettingsForm;
use Baja\Contagem\Review\SourceAdmin;
use Baja\Contagem\Service\ContagemService;
use InvalidArgumentException;

// Sources and settings: create and edit sources, devices and API tokens,
// engine settings, import of paper totals. Every change reruns the engine.

$prova = Pagina::provaOrRedirect();
ContagemAccess::requireController($prova);

$service = ContagemService::forProva($prova);
$errorMessage = null;
$newApiToken = null;
$messages = [
    'criada' => 'Fonte criada.',
    'salva' => 'Fonte salva.',
    'revogado' => 'Dispositivo revogado.',
    'configuracoes' => 'Configurações salvas. A contagem foi recalculada.',
    'totais' => 'Totais importados.',
];

if (Pagina::isPost()) {
    Pagina::requireCsrfPost($prova);
    try {
        switch ($_POST['acao'] ?? '') {
            case 'criar_fonte':
                SourceAdmin::create($service, $_POST);
                Pagina::redirectAfterPost('contagem_fontes.php', $prova, ['msg' => 'criada']);
            case 'salvar_fonte':
                SourceAdmin::update($service, $_POST);
                Pagina::redirectAfterPost('contagem_fontes.php', $prova, ['msg' => 'salva']);
            case 'revogar_dispositivo':
                SourceAdmin::revokeDevice($service, $_POST);
                Pagina::redirectAfterPost('contagem_fontes.php', $prova, ['msg' => 'revogado']);
            case 'salvar_configuracoes':
                SourceAdmin::saveSettings($service, $_POST);
                Pagina::redirectAfterPost('contagem_fontes.php', $prova, ['msg' => 'configuracoes']);
            case 'importar_totais':
                SourceAdmin::importTotals($service, $_POST, $_FILES['arquivo'] ?? null);
                Pagina::redirectAfterPost('contagem_fontes.php', $prova, ['msg' => 'totais']);
            case 'criar_token':
                // Shown in this response only, never redirected: the token
                // must not end up in a URL or in the browser history.
                $newApiToken = SourceAdmin::createApiToken($service, $_POST);
                break;
            default:
                throw new InvalidArgumentException('Ação desconhecida.');
        }
    } catch (InvalidArgumentException $invalid) {
        $errorMessage = $invalid->getMessage();
    }
}

$h = [Pagina::class, 'h'];
$messageCode = is_string($_GET['msg'] ?? null) ? $_GET['msg'] : '';
$confirmation = $messages[$messageCode] ?? null;
$action = 'contagem_fontes.php?p=' . rawurlencode($prova->getProvaId());
$csrf = Pagina::csrfField($prova);
$sources = SourceAdmin::sources($prova);
$boxSource = SourceAdmin::boxSource($prova);
$settings = $service->getSettings();
$settingValues = $settings->toArray();

$options = function (array $labels, $selected) use ($h): string {
    $html = '';
    foreach ($labels as $value => $label) {
        $html .= '<option value="' . $h($value) . '"' . ((string) $value === (string) $selected ? ' selected' : '') . '>' . $h($label) . '</option>';
    }
    return $html;
};

Pagina::printHeader($prova, 'Fontes e configurações');
?>
<?php if ($confirmation !== null) { ?><p class="contagem-ok"><?= $h($confirmation) ?></p><?php } ?>
<?php if ($errorMessage !== null) { ?><p class="contagem-erro"><?= $h($errorMessage) ?></p><?php } ?>
<?php if ($newApiToken !== null) { ?>
    <div class="contagem-token">
        <strong>Token de API criado.</strong> Copie agora: ele não será mostrado de novo.
        <code><?= $h($newApiToken) ?></code>
        O sistema externo envia para <code>contagem_sync.php</code> com o cabeçalho
        <code>Authorization: Bearer &lt;token&gt;</code> e <code>"mode": "api"</code>.
    </div>
<?php } ?>

<section class="contagem-bloco">
    <h2>Fontes</h2>
    <?php foreach ($sources as $fonte) {
        $fonteId = (int) $fonte->getFonteId(); ?>
        <form method="POST" action="<?= $h($action) ?>" class="contagem-fonte">
            <?= $csrf ?>
            <input type="hidden" name="acao" value="salvar_fonte">
            <input type="hidden" name="fonte_id" value="<?= $fonteId ?>">
            <label>Nome <input type="text" name="nome" maxlength="45" required value="<?= $h($fonte->getNome()) ?>"></label>
            <label>Tipo <select name="tipo"><?= $options(SourceAdmin::TYPE_LABELS, $fonte->getTipo()) ?></select></label>
            <label>Tempo <select name="qualidade_tempo"><?= $options(SourceAdmin::TIMING_LABELS, $fonte->getQualidadeTempo()) ?></select></label>
            <label>Ordem <input type="number" name="ordem" min="0" max="999" required value="<?= (int) $fonte->getOrdem() ?>"></label>
            <label><input type="checkbox" name="ativa" value="1" <?= $fonte->getAtiva() ? 'checked' : '' ?>> ativa</label>
            <?php if ($settings->fontePrincipalId === $fonteId) { ?><span class="contagem-etiqueta">principal</span><?php } ?>
            <button type="submit">Salvar</button>
        </form>
    <?php } ?>

    <h3>Nova fonte</h3>
    <form method="POST" action="<?= $h($action) ?>" class="contagem-fonte">
        <?= $csrf ?>
        <input type="hidden" name="acao" value="criar_fonte">
        <label>Nome <input type="text" name="nome" maxlength="45" required placeholder="Contagem 4"></label>
        <label>Tipo <select name="tipo"><?= $options(SourceAdmin::TYPE_LABELS, Source::TIPO_MANUAL) ?></select></label>
        <label>Tempo <select name="qualidade_tempo"><?= $options(SourceAdmin::TIMING_LABELS, Source::TEMPO_HUMANA) ?></select></label>
        <button type="submit">Criar</button>
    </form>
    <p class="contagem-dica">
        Fontes não são apagadas: desativar mantém todos os registros e apenas tira a fonte da contagem.
        Quem conta em cada fonte manual é definido em
        <a href="contagem_contadores.php?p=<?= $h(rawurlencode($prova->getProvaId())) ?>">Contadores</a>.
    </p>
</section>

<section class="contagem-bloco">
    <h2>Dispositivos e tokens</h2>
    <?php foreach (array_merge($sources, $boxSource !== null ? [$boxSource] : []) as $fonte) {
        if ($fonte->getTipo() === Source::TIPO_TOTAIS) {
            continue;
        }
        $devices = SourceAdmin::devices($fonte); ?>
        <h3><?= $h($fonte->getNome()) ?></h3>
        <?php if ($devices === []) { ?>
            <p>Nenhum dispositivo abriu esta fonte ainda.</p>
        <?php } else { ?>
            <table class="tablesorter contagem-tabela">
                <thead><tr><th>#</th><th>Usuário</th><th>Rótulo</th><th>Criado</th><th>Visto</th><th>Sincronizado até</th><th class="sorter-false"></th></tr></thead>
                <tbody>
                <?php foreach ($devices as $device) {
                    $isRevoked = $device->getRevogadoEmMs() !== null; ?>
                    <tr<?= $isRevoked ? ' class="linha-substituida"' : '' ?>>
                        <td><?= (int) $device->getDispositivoId() ?></td>
                        <td><?= $h($device->getUsuario() ?? '(API)') ?></td>
                        <td><?= $h($device->getRotulo()) ?></td>
                        <td><?= $h(Pagina::formatClock((int) $device->getCriadoEmMs())) ?></td>
                        <td><?= $h(Pagina::formatClock($device->getVistoEmMs() === null ? null : (int) $device->getVistoEmMs())) ?></td>
                        <td><?= $h(Pagina::formatClock($device->getSincronizadoAteMs() === null ? null : (int) $device->getSincronizadoAteMs())) ?></td>
                        <td>
                            <?php if ($isRevoked) { ?>
                                revogado em <?= $h(Pagina::formatClock((int) $device->getRevogadoEmMs())) ?>
                            <?php } else { ?>
                                <form method="POST" action="<?= $h($action) ?>"
                                      onsubmit="return confirm('Revogar este dispositivo? Ele não poderá mais enviar registros.');">
                                    <?= $csrf ?>
                                    <input type="hidden" name="acao" value="revogar_dispositivo">
                                    <input type="hidden" name="dispositivo_id" value="<?= (int) $device->getDispositivoId() ?>">
                                    <button type="submit">revogar</button>
                                </form>
                            <?php } ?>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        <?php } ?>
        <?php if ($fonte->getTipo() === Source::TIPO_AUTOMATICA) { ?>
            <form method="POST" action="<?= $h($action) ?>" class="contagem-acao">
                <?= $csrf ?>
                <input type="hidden" name="acao" value="criar_token">
                <input type="hidden" name="fonte_id" value="<?= (int) $fonte->getFonteId() ?>">
                <label>Rótulo do token <input type="text" name="rotulo" maxlength="100" placeholder="Sistema de cronometragem"></label>
                <button type="submit">Criar token de API</button>
            </form>
        <?php } ?>
    <?php } ?>
</section>

<?php foreach ($sources as $fonte) {
    if ($fonte->getTipo() !== Source::TIPO_TOTAIS) {
        continue;
    } ?>
    <section class="contagem-bloco">
        <h2>Importar totais: <?= $h($fonte->getNome()) ?></h2>
        <form method="POST" action="<?= $h($action) ?>" enctype="multipart/form-data" class="contagem-acao contagem-coluna">
            <?= $csrf ?>
            <input type="hidden" name="acao" value="importar_totais">
            <input type="hidden" name="fonte_id" value="<?= (int) $fonte->getFonteId() ?>">
            <label>Cole uma linha por carro, no formato <code>carro, voltas</code>:
                <textarea name="texto" rows="8" cols="30" placeholder="12, 48&#10;41, 47"></textarea>
            </label>
            <label>ou envie um arquivo CSV: <input type="file" name="arquivo" accept=".csv,text/csv,text/plain"></label>
            <button type="submit">Importar (substitui os totais anteriores desta fonte)</button>
        </form>
    </section>
<?php } ?>

<section class="contagem-bloco">
    <h2>Configurações</h2>
    <form method="POST" action="<?= $h($action) ?>" class="contagem-configuracoes">
        <?= $csrf ?>
        <input type="hidden" name="acao" value="salvar_configuracoes">
        <label>Quais passagens viram volta oficial
            <select name="modo_publicacao"><?= $options(SettingsForm::MODE_LABELS, $settings->modoPublicacao) ?></select>
        </label>
        <label>Fonte principal
            <select name="fonte_principal_id">
                <option value="">(nenhuma)</option>
                <?php foreach ($sources as $fonte) {
                    if ($fonte->getTipo() === Source::TIPO_TOTAIS) {
                        continue;
                    } ?>
                    <option value="<?= (int) $fonte->getFonteId() ?>" <?= $settings->fontePrincipalId === (int) $fonte->getFonteId() ? 'selected' : '' ?>><?= $h($fonte->getNome()) ?></option>
                <?php } ?>
            </select>
        </label>
        <?php foreach (SettingsForm::INTEGER_FIELDS + SettingsForm::DECIMAL_FIELDS as $key => [$label, $hint]) { ?>
            <label><?= $h($label) ?>
                <input type="text" inputmode="decimal" name="<?= $h($key) ?>" required size="8"
                       value="<?= $h(str_replace('.', ',', (string) $settingValues[$key])) ?>">
                <small><?= $h($hint) ?></small>
            </label>
        <?php } ?>
        <button type="submit">Salvar configurações</button>
    </form>
</section>
<?php
Pagina::printFooter();
