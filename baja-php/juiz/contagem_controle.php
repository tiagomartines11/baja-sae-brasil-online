<?php
namespace Baja\Juiz;

use Baja\Contagem\Access\ContagemAccess;
use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Contagem\Engine\RaceTimeline;
use Baja\Contagem\Review\CsvExport;
use Baja\Contagem\Review\Pagina;
use Baja\Contagem\Review\RaceControl;
use Baja\Contagem\Review\RaceEvents;
use Baja\Contagem\Service\Clock;
use Baja\Contagem\Service\ContagemService;
use Baja\Contagem\Service\LockBusy;
use Baja\Session;
use InvalidArgumentException;

// Race control: start, red flag, restart, finish, correcting or cancelling
// any of them, and the maintenance actions.

$prova = Pagina::provaOrRedirect();
ContagemAccess::requireController($prova);

$service = ContagemService::forProva($prova);
$errorMessage = null;
// Wiping a race that was started is for administrators only.
$isAdmin = Session::hasPermission('admin');

if (Pagina::isPost()) {
    Pagina::requireCsrfPost($prova);
    try {
        $messageCode = RaceControl::handle($service, $_POST, Pagina::username(), $isAdmin);
        Pagina::redirectAfterPost('contagem_controle.php', $prova, ['msg' => $messageCode]);
    } catch (InvalidArgumentException $invalid) {
        $errorMessage = $invalid->getMessage();
    } catch (LockBusy $busy) {
        $errorMessage = 'A contagem está ocupada com outra operação. Nada foi alterado: tente de novo.';
    }
}

$h = [Pagina::class, 'h'];
$events = RaceControl::raceEvents($prova);
$timeline = RaceControl::timelineOf($events);
$state = $timeline->stateAt(Clock::nowMs());
$situations = RaceEvents::situations(RaceEvents::toOcorrencias($events));
$eventsById = [];
foreach ($events as $event) {
    $eventsById[(int) $event->getOcorrenciaId()] = $event;
}
$situationLabels = [
    RaceEvents::EM_VIGOR => '',
    RaceEvents::CORRIGIDA => ' (corrigida)',
    RaceEvents::CANCELADA => ' (cancelada)',
    RaceEvents::SUPERADA => ' (anterior: volta a valer se a mais recente for cancelada)',
];
$hasEverStarted = RaceControl::hasEverStarted($prova);
$mayReset = $isAdmin || !$hasEverStarted;
$publishingIsOff = !$service->getSettings()->publishesToScoring();

$stateLabels = [
    RaceTimeline::AGUARDANDO => 'Aguardando a largada',
    RaceTimeline::CORRENDO => 'Corrida em andamento',
    RaceTimeline::BANDEIRA_VERMELHA => 'Bandeira vermelha',
    RaceTimeline::ENCERRADA => 'Corrida encerrada',
];
$messageCode = is_string($_GET['msg'] ?? null) ? $_GET['msg'] : '';
$confirmation = RaceControl::MESSAGES[$messageCode] ?? null;
$action = 'contagem_controle.php?p=' . rawurlencode($prova->getProvaId());
$csrf = Pagina::csrfField($prova);

Pagina::printHeader($prova, 'Controle de corrida');
?>
<?php if ($confirmation !== null) { ?><p class="contagem-ok"><?= $h($confirmation) ?></p><?php } ?>
<?php if ($errorMessage !== null) { ?><p class="contagem-erro"><?= $h($errorMessage) ?></p><?php } ?>
<?php if ($publishingIsOff) { ?>
    <p class="contagem-aviso">Publicação desligada: os resultados oficiais não estão sendo atualizados.</p>
<?php } ?>

<section class="contagem-bloco">
    <h2>Estado: <span class="estado-corrida estado-<?= $h($state) ?>"><?= $h($stateLabels[$state]) ?></span></h2>
    <p>
        Largada: <strong><?= $h(Pagina::formatClock($timeline->largadaMs)) ?></strong>
        <?php if ($timeline->encerramentoMs !== null) { ?>
            &middot; Encerramento: <strong><?= $h(Pagina::formatClock($timeline->encerramentoMs)) ?></strong>
        <?php } ?>
    </p>

    <?php // No confirmation on the first start: the click is the start time, and a dialog would delay it. ?>
    <form method="POST" action="<?= $h($action) ?>" class="contagem-acao"
          <?php if ($timeline->largadaMs !== null) { ?>onsubmit="return confirm('A corrida já largou. Substituir o horário da largada por agora?');"<?php } ?>>
        <?= $csrf ?>
        <input type="hidden" name="acao" value="largar_agora">
        <?php // Sent only by a page that knew about the start and asked: the server refuses a second start without it. ?>
        <?php if ($timeline->largadaMs !== null) { ?><input type="hidden" name="confirmar" value="1"><?php } ?>
        <button type="submit" class="botao-grande botao-verde">Largar agora</button>
        <?php if ($timeline->largadaMs !== null) { ?>
            <span class="contagem-aviso">A corrida já tem largada: isto substitui o horário atual. Pode ser desfeito cancelando a nova largada na lista abaixo.</span>
        <?php } ?>
    </form>

    <form method="POST" action="<?= $h($action) ?>" class="contagem-acao">
        <?= $csrf ?>
        <input type="hidden" name="acao" value="registrar">
        <input type="hidden" name="tipo" value="<?= $h(Ocorrencia::LARGADA) ?>">
        <?php // Typing a time and pressing "Salvar" is deliberate enough; no second question. ?>
        <input type="hidden" name="confirmar" value="1">
        <label>Informar ou corrigir o horário da largada (horário de Brasília):
            <input type="datetime-local" name="horario" step="1" required
                   value="<?= $timeline->largadaMs !== null ? $h(date('Y-m-d\TH:i:s', intdiv($timeline->largadaMs, 1000))) : '' ?>">
        </label>
        <button type="submit">Salvar horário da largada</button>
    </form>
</section>

<section class="contagem-bloco">
    <h2>Durante a corrida</h2>
    <p class="contagem-dica">Deixe o horário vazio para registrar o momento do clique.</p>
    <?php
    $raceButtons = [
        Ocorrencia::BANDEIRA_VERMELHA => ['Bandeira vermelha', 'botao-vermelho'],
        Ocorrencia::RELARGADA => ['Relargada', 'botao-verde'],
        Ocorrencia::ENCERRAMENTO => ['Encerrar corrida', 'botao-escuro'],
    ];
    $questions = [
        Ocorrencia::BANDEIRA_VERMELHA => 'Registrar bandeira vermelha?',
        Ocorrencia::RELARGADA => 'Registrar relargada?',
        Ocorrencia::ENCERRAMENTO => 'Encerrar a corrida? Depois do encerramento só conta a próxima passagem de cada carro. Pode ser desfeito cancelando o encerramento na lista abaixo.',
    ];
    foreach ($raceButtons as $tipo => [$label, $class]) { ?>
        <form method="POST" action="<?= $h($action) ?>" class="contagem-acao"
              onsubmit="return confirm(<?= $h(json_encode($questions[$tipo])) ?>);">
            <?= $csrf ?>
            <input type="hidden" name="acao" value="registrar">
            <input type="hidden" name="tipo" value="<?= $h($tipo) ?>">
            <input type="hidden" name="confirmar" value="1">
            <button type="submit" class="botao-grande <?= $h($class) ?>"><?= $h($label) ?></button>
            <label>Horário (opcional): <input type="datetime-local" name="horario" step="1"></label>
        </form>
    <?php } ?>
</section>

<section class="contagem-bloco">
    <h2>Ocorrências registradas</h2>
    <?php if ($events === []) { ?>
        <p>Nenhuma ocorrência ainda.</p>
    <?php } else { ?>
        <p class="contagem-dica">
            Nada é apagado: corrigir grava um novo horário e cancelar desfaz o registro (use quando o botão foi apertado por engano).
            As linhas riscadas ficam como histórico.
        </p>
        <table class="tablesorter contagem-tabela">
            <thead><tr><th>Tipo</th><th>Horário</th><th>Por</th><th>Registrada em</th><th class="sorter-false">Corrigir horário</th><th class="sorter-false">Cancelar</th></tr></thead>
            <tbody>
            <?php foreach (array_reverse($events) as $event) {
                $eventId = (int) $event->getOcorrenciaId();
                $situation = $situations[$eventId];
                $isCancellation = $situation === RaceEvents::CANCELAMENTO;
                $isStruckOut = in_array($situation, [RaceEvents::CORRIGIDA, RaceEvents::CANCELADA], true);
                if ($isCancellation) {
                    $target = $eventsById[(int) $event->getSubstituiId()] ?? null;
                    $typeText = 'Cancelamento de ' . mb_strtolower(RaceControl::EVENT_LABELS[$target !== null ? $target->getTipo() : ''] ?? 'ocorrência');
                } else {
                    $typeText = (RaceControl::EVENT_LABELS[$event->getTipo()] ?? $event->getTipo()) . $situationLabels[$situation];
                } ?>
                <tr<?= $isStruckOut ? ' class="linha-substituida"' : '' ?>>
                    <td><?= $h($typeText) ?></td>
                    <td><?= $h(Pagina::formatClock((int) $event->getTsMs())) ?></td>
                    <td><?= $h($event->getUsuario()) ?></td>
                    <td><?= $h(Pagina::formatClock((int) $event->getCriadoEmMs())) ?></td>
                    <td>
                        <?php if ($situation === RaceEvents::EM_VIGOR) { ?>
                            <form method="POST" action="<?= $h($action) ?>">
                                <?= $csrf ?>
                                <input type="hidden" name="acao" value="corrigir">
                                <input type="hidden" name="ocorrencia_id" value="<?= $eventId ?>">
                                <input type="datetime-local" name="horario" step="1" required
                                       value="<?= $h(date('Y-m-d\TH:i:s', intdiv((int) $event->getTsMs(), 1000))) ?>">
                                <button type="submit">Corrigir</button>
                            </form>
                        <?php } ?>
                    </td>
                    <td>
                        <?php if ($situation === RaceEvents::EM_VIGOR || $situation === RaceEvents::SUPERADA) { ?>
                            <form method="POST" action="<?= $h($action) ?>"
                                  onsubmit="return confirm(<?= $h(json_encode('Cancelar esta ocorrência (' . mb_strtolower(RaceControl::EVENT_LABELS[$event->getTipo()] ?? '') . ')? Ela deixa de valer, como se não tivesse sido registrada.')) ?>);">
                                <?= $csrf ?>
                                <input type="hidden" name="acao" value="cancelar">
                                <input type="hidden" name="ocorrencia_id" value="<?= $eventId ?>">
                                <button type="submit">Cancelar</button>
                            </form>
                        <?php } ?>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    <?php } ?>
</section>

<section class="contagem-bloco">
    <h2>Manutenção</h2>
    <form method="POST" action="<?= $h($action) ?>" class="contagem-acao">
        <?= $csrf ?>
        <input type="hidden" name="acao" value="republicar">
        <button type="submit">Republicar tudo</button>
        <span class="contagem-dica">Regrava voltas, última passagem e melhor volta de todos os carros na pontuação.</span>
    </form>
    <form method="POST" action="<?= $h($action) ?>" class="contagem-acao">
        <?= $csrf ?>
        <input type="hidden" name="acao" value="reconstruir">
        <button type="submit">Reconstruir</button>
        <span class="contagem-dica">Apaga e recalcula a tabela de voltas a partir dos registros brutos. Nenhum registro é perdido.</span>
    </form>
    <p>
        Exportar CSV:
        <?php foreach (CsvExport::LABELS as $type => $label) { ?>
            <a class="contagem-exportar" href="contagem_exportar.php?p=<?= $h(rawurlencode($prova->getProvaId())) ?>&amp;tipo=<?= $h($type) ?>"><?= $h($label) ?></a>
        <?php } ?>
    </p>
</section>

<section class="contagem-bloco contagem-perigo">
    <h2>Zerar corrida</h2>
    <p>
        Apaga <strong>todas</strong> as passagens, decisões, ocorrências, voltas e totais importados desta prova
        e zera as voltas publicadas. Serve para limpar um ensaio antes da corrida de verdade.
    </p>
    <p class="contagem-dica">
        Antes de apagar, tudo é copiado para o arquivo (tabela <code>contagem_arquivo</code>), de onde um
        administrador do sistema consegue recuperar os dados se a corrida errada for zerada.
        A tela não tem um botão para desfazer.
    </p>
    <?php if (!$mayReset) { ?>
        <p class="contagem-aviso">
            Esta corrida já teve largada: só um administrador pode zerá-la.
        </p>
    <?php } else { ?>
        <?php if ($hasEverStarted) { ?>
            <p class="contagem-aviso">Esta corrida já teve largada. Confira se é mesmo um ensaio antes de zerar.</p>
        <?php } ?>
        <form method="POST" action="<?= $h($action) ?>" class="contagem-acao"
              onsubmit="return confirm('Apagar todos os dados de contagem desta prova?');">
            <?= $csrf ?>
            <input type="hidden" name="acao" value="zerar">
            <label>Para confirmar, digite o código da prova (<code><?= $h($prova->getProvaId()) ?></code>):
                <input type="text" name="confirmacao" autocomplete="off" required maxlength="3" size="5">
            </label>
            <button type="submit" class="botao-vermelho">Zerar corrida</button>
        </form>
    <?php } ?>
</section>
<?php
Pagina::printFooter();
