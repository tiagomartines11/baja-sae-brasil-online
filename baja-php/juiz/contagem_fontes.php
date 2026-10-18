<?php
namespace Baja\Juiz;

use Baja\Contagem\Access\ContagemAccess;
use Baja\Contagem\Engine\Data\Source;
use Baja\Contagem\Review\ConfirmationNeeded;
use Baja\Contagem\Review\Pagina;
use Baja\Contagem\Review\SettingsForm;
use Baja\Contagem\Review\SourceAdmin;
use Baja\Contagem\Review\SourceSettings;
use Baja\Contagem\Service\ContagemService;
use Baja\Contagem\Service\LockBusy;
use Baja\Contagem\Service\ScoringFields;
use Baja\Contagem\Service\TestPreset;
use InvalidArgumentException;

// Sources and settings: create and edit sources, devices and API tokens,
// engine settings, import of paper totals. Every change recounts the race.

$prova = Pagina::provaOrRedirect();
ContagemAccess::requireController($prova);

$service = ContagemService::forProva($prova);
$errorMessage = null;
$newApiToken = null;
// Set when an action answered "ask the person first": shown with a button that sends the form again.
$question = null;
$messages = [
    'criada' => 'Fonte criada.',
    'salva' => 'Fonte salva.',
    'revogado' => 'Dispositivo revogado.',
    'configuracoes' => 'Configurações salvas. A contagem foi recalculada.',
    'totais' => 'Totais importados.',
    'liberado' => 'Usuário liberado: o aparelho dele volta a receber acesso a esta fonte.',
    'predefinicao' => 'Predefinição de teste aplicada. Os valores anteriores ficaram guardados.',
    'restaurado' => 'Valores salvos restaurados. A contagem foi recalculada.',
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
            case 'liberar_usuario':
                SourceSettings::release($service, $_POST);
                Pagina::redirectAfterPost('contagem_fontes.php', $prova, ['msg' => 'liberado']);
            case 'aplicar_predefinicao':
                SourceSettings::applyTestPreset($service, $_POST);
                Pagina::redirectAfterPost('contagem_fontes.php', $prova, ['msg' => 'predefinicao']);
            case 'restaurar_configuracoes':
                SourceSettings::restore($service, $_POST);
                Pagina::redirectAfterPost('contagem_fontes.php', $prova, ['msg' => 'restaurado']);
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
    } catch (ConfirmationNeeded $needed) {
        $question = $needed;
    } catch (InvalidArgumentException $invalid) {
        $errorMessage = $invalid->getMessage();
    } catch (LockBusy $busy) {
        $errorMessage = 'A contagem está ocupada com outra operação. Confira se a alteração foi gravada e, se não, tente de novo.';
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
$presetIsApplied = $service->hasSavedSettings();
$hasSimulatedRows = $service->hasSimulatedRows();
$classificationConflict = ScoringFields::classificationConflict($prova);
// Empty, at no cost, while no source is an observer.
$observerComparisons = SourceAdmin::observerComparisons($service);
$sensorStats = SourceAdmin::sensorStats($service);

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
<?php if ($question !== null) { ?>
    <div class="contagem-aviso contagem-pergunta" role="alert">
        <p><strong>Nada foi alterado ainda.</strong> <?= $h($question->getMessage()) ?></p>
        <?= Pagina::confirmationForm($prova, $action, $_POST, $question) ?>
    </div>
<?php } ?>
<?php if ($classificationConflict !== null) { ?>
    <p class="contagem-erro" role="alert"><strong>O campo de classificação (<?= $h($classificationConflict) ?>) é lido pela fórmula de pontuação.</strong>
        Os pontos podem estar errados. Veja "Campo de classificação" em Configurações, abaixo.</p>
<?php } ?>
<?php if ($newApiToken !== null) { ?>
    <div class="contagem-token">
        <strong>Token de API criado.</strong> Copie agora: ele não será mostrado de novo.
        <code><?= $h($newApiToken) ?></code>
        O sistema externo envia para <code>contagem_sync.php</code> com o cabeçalho
        <code>Authorization: Bearer &lt;token&gt;</code> e <code>"mode": "api"</code>, e
        <code>"time_quality": "exato"</code> em cada registro (sem ele o registro fica com horário desconhecido e nunca conta).
        Um número que o sistema não conseguiu ler vai como <code>"car": null</code>; um sensor de tempo envia sempre assim
        (o número que ele mandar é ignorado).
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
            <?php if ($fonte->getTipo() === Source::TIPO_SENSOR) { ?>
                <input type="hidden" name="qualidade_tempo" value="<?= $h(Source::TEMPO_PRECISA) ?>">
                <span class="contagem-dica">tempo preciso</span>
            <?php } else { ?>
                <label>Tempo <select name="qualidade_tempo"><?= $options(SourceAdmin::TIMING_LABELS, $fonte->getQualidadeTempo()) ?></select></label>
            <?php } ?>
            <label>Ordem <input type="number" name="ordem" min="0" max="999" required value="<?= (int) $fonte->getOrdem() ?>"></label>
            <?php if (SourceAdmin::mayObserve($fonte)) { ?>
                <label>Papel <select name="papel"><?= $options(SourceAdmin::ROLE_LABELS, $fonte->getPapel()) ?></select></label>
            <?php } ?>
            <?php if ($fonte->getTipo() === Source::TIPO_AUTOMATICA) { ?>
                <label>Endereço das imagens <input type="text" name="imagem_base_url" maxlength="255" size="32"
                       placeholder="ex.: http://camera.local/imagens/ (vazio = sem imagens)" value="<?= $h($fonte->getImagemBaseUrl() ?? '') ?>"></label>
            <?php } ?>
            <?php if ($fonte->getTipo() === Source::TIPO_SENSOR) { ?>
                <span class="contagem-dica">marca só o horário da passagem, nunca o número</span>
            <?php } ?>
            <label><input type="checkbox" name="ativa" value="1" <?= $fonte->getAtiva() ? 'checked' : '' ?>> ativa</label>
            <?php if (!$fonte->getAtiva()) { ?>
                <label><input type="checkbox" name="<?= $h(SourceAdmin::CAMPO_CONTAR_PERIODO_DESATIVADA) ?>" value="1">
                    ao reativar, contar também o que ela enviou enquanto estava desativada</label>
            <?php } ?>
            <?php if ($settings->fontePrincipalId === $fonteId) { ?><span class="contagem-etiqueta">principal</span><?php } ?>
            <?php if ($fonte->getPapel() === Source::PAPEL_OBSERVADORA) { ?>
                <span class="contagem-etiqueta">observadora<?= $fonte->getPapelDesdeMs() !== null ? ' desde ' . $h(Pagina::formatClock((int) $fonte->getPapelDesdeMs())) : '' ?></span>
            <?php } elseif ($fonte->getPapelDesdeMs() !== null) { ?>
                <span class="contagem-dica">votante desde <?= $h(Pagina::formatClock((int) $fonte->getPapelDesdeMs())) ?>; o que registrou antes como observadora não conta</span>
            <?php } ?>
            <?php if (!$fonte->getAtiva() && $fonte->getDesativadaEmMs() !== null) { ?>
                <span class="contagem-dica">desativada em <?= $h(Pagina::formatClock((int) $fonte->getDesativadaEmMs())) ?>; o que registrou antes disso continua valendo</span>
            <?php } elseif (!$fonte->getAtiva()) { ?>
                <span class="contagem-dica">criada desativada: nada do que enviar conta até ser ativada</span>
            <?php } ?>
            <button type="submit">Salvar</button>
        </form>
        <?php if (isset($observerComparisons[$fonteId]) && $fonte->getTipo() === Source::TIPO_SENSOR) {
            $comparison = $observerComparisons[$fonteId]; ?>
            <div class="contagem-dica contagem-observacao">
                <strong>Comparação com a contagem oficial<?= $fonte->getPapel() === Source::PAPEL_OBSERVADORA ? '' : ' (do tempo em que era observador)' ?>:</strong>
                <?= (int) $comparison['acertos'] ?> de <?= (int) $comparison['entradas'] ?> marcas coincidem com uma passagem oficial,
                <?= (int) $comparison['faltas'] ?> passagens oficiais sem marca,
                <?= (int) $comparison['falsos_positivos'] ?> marcas sem passagem.
                <?php if ($comparison['desvio_mediano_ms'] !== null) { ?>
                    Diferença para o horário oficial: mediana <?= $h(sprintf('%+.1f', $comparison['desvio_mediano_ms'] / 1000)) ?> s,
                    90% dentro de <?= $h(sprintf('%.1f', $comparison['desvio_p90_ms'] / 1000)) ?> s.
                <?php } ?>
                Detalhe por marca em Controle de corrida, "Exportar CSV".
            </div>
        <?php } elseif (isset($observerComparisons[$fonteId])) {
            $comparison = $observerComparisons[$fonteId]; ?>
            <div class="contagem-dica contagem-observacao">
                <strong>Comparação com a contagem oficial<?= $fonte->getPapel() === Source::PAPEL_OBSERVADORA ? '' : ' (do tempo em que era observadora)' ?>:</strong>
                <?= (int) $comparison['acertos'] ?> acertos de <?= (int) $comparison['entradas'] ?> registros,
                <?= (int) $comparison['faltas'] ?> passagens oficiais não vistas,
                <?= (int) $comparison['falsos_positivos'] ?> falsos positivos,
                <?= (int) $comparison['leituras_trocadas'] ?> números lidos errado<?php
                if (($comparison['trocas_frequentes'] ?? []) !== []) {
                    echo ' (' . $h(implode(', ', array_map(
                        fn(array $troca) => $troca['carro_real'] . ' lido como ' . $troca['carro_lido'] . ' ×' . $troca['vezes'],
                        $comparison['trocas_frequentes']
                    ))) . ')';
                }
                if ((int) ($comparison['ilegiveis'] ?? 0) > 0) {
                    echo ', ' . (int) $comparison['ilegiveis'] . ' ilegíveis, dos quais ' . (int) $comparison['ilegiveis_com_passagem'] . ' coincidem com uma passagem oficial';
                } ?>.
                <?php if ($comparison['desvio_mediano_ms'] !== null) { ?>
                    Diferença para o horário oficial: mediana <?= $h(sprintf('%+.1f', $comparison['desvio_mediano_ms'] / 1000)) ?> s,
                    90% dentro de <?= $h(sprintf('%.1f', $comparison['desvio_p90_ms'] / 1000)) ?> s.
                <?php } ?>
                Detalhe por registro em Controle de corrida, "Exportar CSV".
            </div>
        <?php } ?>
        <?php if (isset($sensorStats[$fonteId])) {
            $sensor = $sensorStats[$fonteId]; ?>
            <div class="contagem-dica contagem-observacao">
                <strong>Marcas do sensor:</strong>
                <?= (int) $sensor['atribuidas'] ?> de <?= (int) $sensor['marcas'] ?> dão o horário de uma passagem,
                <?= (int) $sensor['ambiguas'] ?> ficaram sem passagem por haver mais de um carro perto,
                <?= (int) $sensor['sem_passagem'] ?> sem passagem nenhuma por perto,
                <?= (int) $sensor['passagens_sem_marca'] ?> passagens oficiais sem marca.
                <?php if ($sensor['desvio_mediano_ms'] !== null) { ?>
                    Diferença para os contadores: mediana <?= $h(sprintf('%+.1f', $sensor['desvio_mediano_ms'] / 1000)) ?> s,
                    90% dentro de <?= $h(sprintf('%.1f', $sensor['desvio_p90_ms'] / 1000)) ?> s.
                <?php } ?>
            </div>
        <?php } ?>
    <?php } ?>

    <h3>Nova fonte</h3>
    <form method="POST" action="<?= $h($action) ?>" class="contagem-fonte">
        <?= $csrf ?>
        <input type="hidden" name="acao" value="criar_fonte">
        <label>Nome <input type="text" name="nome" maxlength="45" required placeholder="Contagem 4"></label>
        <label>Tipo <select name="tipo"><?= $options(SourceAdmin::TYPE_LABELS, Source::TIPO_MANUAL) ?></select></label>
        <label>Tempo <select name="qualidade_tempo"><?= $options(SourceAdmin::TIMING_LABELS, Source::TEMPO_HUMANA) ?></select></label>
        <label><input type="checkbox" name="<?= $h(SourceAdmin::CAMPO_CRIAR_DESATIVADA) ?>" value="1"> criar desativada</label>
        <button type="submit">Criar</button>
    </form>
    <p class="contagem-dica">
        Fontes não são apagadas. Desativar uma fonte faz o sistema parar de esperar por ela a partir de agora;
        tudo o que ela registrou até esse momento continua contando. Ao reativá-la, o que ela enviou enquanto
        estava desativada continua fora da contagem, a menos que você marque a opção de contar também esse período.
        Para descartar registros de um período, use "Excluir esta fonte" na tela de revisão.
        Uma fonte automática nova começa como <strong>observadora</strong>: o que ela envia é só comparado com a
        contagem oficial e nunca conta. Promovida a votante, passa a contar a partir daquele momento, nunca para trás.
        Tempo "precisa": <?= $h(SourceAdmin::PRECISE_TIMING_WARNING) ?>
        <?= $h(SourceAdmin::SENSOR_HELP) ?> Como uma fonte automática, começa como observador.
        Quem conta em cada fonte manual é definido em
        <a href="contagem_contadores.php?p=<?= $h(rawurlencode($prova->getProvaId())) ?>">Usuários</a>.
    </p>
</section>

<section class="contagem-bloco">
    <h2>Dispositivos e tokens</h2>
    <?php foreach (array_merge($sources, $boxSource !== null ? [$boxSource] : []) as $fonte) {
        if ($fonte->getTipo() === Source::TIPO_TOTAIS) {
            continue;
        }
        $devices = SourceAdmin::devices($fonte);
        $blockedUsers = SourceSettings::blockedUsers($fonte); ?>
        <h3><?= $h($fonte->getNome()) ?></h3>
        <?php if ($blockedUsers !== []) { ?>
            <div class="contagem-aviso">
                <strong>Bloqueados nesta fonte:</strong>
                <?php foreach ($blockedUsers as $blockedUser) { ?>
                    <form method="POST" action="<?= $h($action) ?>" class="contagem-em-linha"
                          onsubmit="return confirm(<?= $h(json_encode('Liberar ' . $blockedUser . '? O aparelho dessa pessoa volta a poder enviar registros para esta fonte.')) ?>);">
                        <?= $csrf ?>
                        <input type="hidden" name="acao" value="liberar_usuario">
                        <input type="hidden" name="fonte_id" value="<?= (int) $fonte->getFonteId() ?>">
                        <input type="hidden" name="usuario" value="<?= $h($blockedUser) ?>">
                        <?= $h($blockedUser) ?> <button type="submit">liberar</button>
                    </form>
                <?php } ?>
                <br><small><?= $h(SourceSettings::BLOCK_RULE) ?></small>
            </div>
        <?php } ?>
        <?php if ($devices === []) { ?>
            <p>Nenhum dispositivo abriu esta fonte ainda.</p>
        <?php } else { ?>
            <table class="tablesorter contagem-tabela">
                <thead><tr><th>#</th><th>Usuário</th><th>Rótulo</th><th>Criado</th><th>Visto</th><th>Sincronizado até</th><th class="sorter-false"></th></tr></thead>
                <tbody>
                <?php foreach ($devices as $device) {
                    $isRevoked = $device->getRevogadoEmMs() !== null;
                    $closedAtMs = $device->getFechadoEmMs() === null ? null : (int) $device->getFechadoEmMs();
                    // Closed by its counter, and not heard from since (a device that comes back is open again).
                    $isClosed = !$isRevoked && $closedAtMs !== null
                        && ($device->getVistoEmMs() === null || (int) $device->getVistoEmMs() <= $closedAtMs); ?>
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
                                <?php if ($isClosed) { ?>turno encerrado em <?= $h(Pagina::formatClock($closedAtMs)) ?><?php } ?>
                                <form method="POST" action="<?= $h($action) ?>"
                                      onsubmit="return confirm('Revogar este dispositivo? Ele não poderá mais enviar registros, e o usuário dele fica bloqueado nesta fonte até ser liberado nesta página ou vinculado de novo.');">
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
        <?php if ($fonte->getTipo() === Source::TIPO_AUTOMATICA || $fonte->getTipo() === Source::TIPO_SENSOR) { ?>
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
        <?php
        $choiceFields = SettingsForm::CHOICE_FIELDS;
        $choiceField = function (string $key) use ($choiceFields, $settingValues, $options, $h): string {
            [$label, $hint, $choices] = $choiceFields[$key];
            return '<label>' . $h($label)
                . ' <select name="' . $h($key) . '">' . $options($choices, $settingValues[$key]) . '</select>'
                . ' <small>' . $h($hint) . '</small></label>';
        };
        ?>
        <?= $choiceField('modo_publicacao') ?>
        <?php if (!$settings->publishesToScoring()) { ?>
            <p class="contagem-aviso">Publicação desligada: os resultados oficiais não estão sendo atualizados.</p>
        <?php } ?>
        <label>Fonte principal
            <select name="fonte_principal_id">
                <option value="">(nenhuma)</option>
                <?php foreach ($sources as $fonte) {
                    if ($fonte->getTipo() === Source::TIPO_TOTAIS) {
                        continue;
                    } ?>
                    <option value="<?= (int) $fonte->getFonteId() ?>" <?= $settings->fontePrincipalId === (int) $fonte->getFonteId() ? 'selected' : '' ?>><?= $h($fonte->getNome()) ?><?= $fonte->getAtiva() ? '' : ' (inativa)' ?><?= $fonte->getPapel() === Source::PAPEL_OBSERVADORA ? ' (observadora)' : '' ?></option>
                <?php } ?>
            </select>
            <small><?= $h(SettingsForm::PRIMARY_SOURCE_HINT) ?></small>
        </label>
        <?= $choiceField('passagem_inicial') ?>
        <?= $choiceField('apos_encerramento') ?>
        <label><?= $h(SettingsForm::CLASSIFICATION_LABEL) ?>
            <select name="<?= $h(SettingsForm::CLASSIFICATION_FIELD) ?>">
                <?php
                // A field the scoring formula reads is shown, disabled. The
                // one in force stays selectable: a disabled selected option
                // is not sent, and saving would silently clear the field.
                $refusedFields = SourceSettings::refusedClassificationFields($prova, $settings->campoClassificacao);
                foreach (['' => '(nenhum: todo carro presente participa)'] + SourceSettings::classificationChoices($prova, $settings->campoClassificacao) as $value => $label) { ?>
                    <option value="<?= $h($value) ?>"<?= (string) $value === ($settings->campoClassificacao ?? '') ? ' selected' : '' ?><?= in_array((string) $value, $refusedFields, true) ? ' disabled' : '' ?>><?= $h($label) ?></option>
                <?php } ?>
            </select>
            <small><?= $h(SettingsForm::CLASSIFICATION_HINT) ?></small>
        </label>
        <?php if ($classificationConflict !== null) { ?>
            <p class="contagem-aviso contagem-pergunta" role="alert"><strong>Atenção: o campo de classificação é <?= $h($classificationConflict) ?>.</strong>
                <?= $h(ScoringFields::whyNot($classificationConflict)) ?></p>
        <?php } ?>
        <?php foreach (SettingsForm::BOOLEAN_FIELDS as $key => [$label, $hint]) { ?>
            <label><input type="checkbox" name="<?= $h($key) ?>" value="1" <?= !empty($settingValues[$key]) ? 'checked' : '' ?>>
                <?= $h($label) ?>
                <small><?= $h($hint) ?></small>
            </label>
            <?php // Why unticking it would be refused, said before the person tries. ?>
            <?php if ($key === 'permitir_simulacao' && $settings->permitirSimulacao && $presetIsApplied) { ?>
                <p class="contagem-aviso">A predefinição de teste está aplicada: para desligar a simulação use "Restaurar valores salvos", no fim desta página.</p>
            <?php } elseif ($key === 'permitir_simulacao' && $settings->permitirSimulacao && $hasSimulatedRows) { ?>
                <p class="contagem-aviso">Esta prova tem registros de simulação: a simulação só pode ser desligada depois de
                    <a href="contagem_controle.php?p=<?= $h(rawurlencode($prova->getProvaId())) ?>">Zerar corrida</a>.</p>
            <?php } ?>
        <?php } ?>
        <?php foreach (SettingsForm::numberFields() as $key => [$label, $hint]) { ?>
            <label><?= $h($label) ?>
                <input type="text" inputmode="decimal" name="<?= $h($key) ?>" required size="8"
                       value="<?= $h(str_replace('.', ',', (string) $settingValues[$key])) ?>">
                <small><?= $h($hint) ?></small>
            </label>
        <?php } ?>
        <button type="submit">Salvar configurações</button>
    </form>
</section>

<section class="contagem-bloco">
    <h2>Predefinição de teste</h2>
    <p class="contagem-dica">
        Para ensaiar com uma corrida simulada de voltas curtas: ajusta a janela de agrupamento e os outros tempos
        para a volta média informada. Os tempos em vigor ficam guardados e voltam com "Restaurar valores salvos".
        Liga também "Permitir simulação" (a faixa SIMULAÇÃO aparece em todas as páginas). Restaurar devolve só os
        tempos e desliga a simulação; o que foi mudado no resto das configurações nesse meio-tempo fica como está.
    </p>
    <form method="POST" action="<?= $h($action) ?>" class="contagem-acao"
          onsubmit="return confirm('Aplicar a predefinição de teste? Os tempos da contagem mudam agora, a simulação é permitida e a corrida é recalculada. NÃO use durante uma corrida de verdade.');">
        <?= $csrf ?>
        <input type="hidden" name="acao" value="aplicar_predefinicao">
        <label>Volta média (s), de <?= TestPreset::SHORTEST_LAP_S ?> a <?= TestPreset::LONGEST_LAP_S ?>
            <input type="number" name="volta_media_s" min="<?= TestPreset::SHORTEST_LAP_S ?>" max="<?= TestPreset::LONGEST_LAP_S ?>" required value="60" size="6"></label>
        <button type="submit">Aplicar predefinição de teste</button>
    </form>
    <?php if ($presetIsApplied) { ?>
        <form method="POST" action="<?= $h($action) ?>" class="contagem-acao">
            <?= $csrf ?>
            <input type="hidden" name="acao" value="restaurar_configuracoes">
            <button type="submit">Restaurar valores salvos</button>
            <span class="contagem-aviso">Uma predefinição de teste está aplicada: os tempos acima NÃO são os da corrida de verdade.</span>
        </form>
        <?php if ($hasSimulatedRows) { ?>
            <p class="contagem-aviso">Esta prova tem registros de simulação: antes de restaurar, use
                <a href="contagem_controle.php?p=<?= $h(rawurlencode($prova->getProvaId())) ?>">Zerar corrida</a>.</p>
        <?php } ?>
    <?php } ?>
</section>
<?php
Pagina::printFooter();
