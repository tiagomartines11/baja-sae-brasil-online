<?php
namespace Baja\Juiz;

use Baja\Contagem\Access\ContagemAccess;
use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Contagem\Engine\RaceTimeline;
use Baja\Contagem\Review\ConfirmationNeeded;
use Baja\Contagem\Review\CsvExport;
use Baja\Contagem\Review\Pagina;
use Baja\Contagem\Review\RaceControl;
use Baja\Contagem\Review\RaceEventRules;
use Baja\Contagem\Review\RaceEvents;
use Baja\Contagem\Service\Clock;
use Baja\Contagem\Service\ContagemService;
use Baja\Contagem\Service\LockBusy;
use Baja\Session;
use InvalidArgumentException;

// Race control: start, red flag, restart, finish, correcting, cancelling or
// restoring any of them, and the maintenance actions.

$prova = Pagina::provaOrRedirect();
ContagemAccess::requireController($prova);

$service = ContagemService::forProva($prova);
$errorMessage = null;
// Set when an action answered "ask the person first": shown with a button that sends the form again.
$question = null;
// Wiping a race that was started is for administrators only.
$isAdmin = Session::hasPermission('admin');

if (Pagina::isPost()) {
    Pagina::requireCsrfPost($prova);
    try {
        $messageCode = RaceControl::handle($service, $_POST, Pagina::username(), $isAdmin);
        Pagina::redirectAfterPost('contagem_controle.php', $prova, ['msg' => $messageCode]);
    } catch (ConfirmationNeeded $needed) {
        $question = $needed;
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
$ocorrencias = RaceEvents::toOcorrencias($events);
$situations = RaceEvents::situations($ocorrencias);
$eventsById = [];
foreach ($events as $event) {
    $eventsById[(int) $event->getOcorrenciaId()] = $event;
}
// The engine's own list of what counts; everything else is history.
$inForceIds = [];
foreach ($timeline->eventosEmVigor as $ocorrencia) {
    $inForceIds[$ocorrencia->id] = true;
}
$eventsInForce = [];
$eventsOfHistory = [];
foreach (array_reverse($events) as $event) {
    $eventId = (int) $event->getOcorrenciaId();
    if (isset($inForceIds[$eventId]) || $situations[$eventId] === RaceEvents::SUPERADA) {
        $eventsInForce[] = $event;
    } else {
        $eventsOfHistory[] = $event;
    }
}
$situationLabels = [
    RaceEvents::EM_VIGOR => '',
    RaceEvents::CORRIGIDA => ' (corrigida: substituída por um novo horário)',
    RaceEvents::CANCELADA => ' (cancelada)',
    RaceEvents::SUPERADA => ' (anterior: volta a valer se a mais recente for cancelada)',
];
// The start whose cancellation would leave the race with no start at all
// (not a corrected one: cancelling that brings the earlier time back).
$onlyStartId = null;
foreach (RaceEvents::inForce($ocorrencias) as $ocorrencia) {
    if (RaceEventRules::cancellingLeavesNoStart($ocorrencias, $ocorrencia)) {
        $onlyStartId = $ocorrencia->id;
    }
}
// What pressing "Largar agora" again would leave before the new start.
$leftOutByRestart = $timeline->largadaMs === null
    ? ['registros' => 0, 'carros' => 0]
    : RaceEventRules::entriesLeftBeforeStart($prova, $timeline->largadaMs, Clock::nowMs());
$restartQuestion = 'A corrida já largou. Substituir o horário da largada por agora?'
    . ($leftOutByRestart['registros'] > 0
        ? ' ' . RaceEventRules::describeEntriesLeftOut($leftOutByRestart) . ' ficariam antes da nova largada e deixariam de contar.'
        : '');
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
<?php if ($question !== null) { ?>
    <div class="contagem-aviso contagem-pergunta" role="alert">
        <p><strong>Nada foi alterado ainda.</strong> <?= $h($question->getMessage()) ?></p>
        <?= Pagina::confirmationForm($prova, $action, $_POST, $question) ?>
    </div>
<?php } ?>
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
          <?php if ($timeline->largadaMs !== null) { ?>onsubmit="return confirm(<?= $h(json_encode($restartQuestion)) ?>);"<?php } ?>>
        <?= $csrf ?>
        <input type="hidden" name="acao" value="largar_agora">
        <?php // Sent only by a page that knew about the start and asked: the server refuses a second start without them. ?>
        <?php if ($timeline->largadaMs !== null) { ?>
            <input type="hidden" name="confirmar" value="1">
            <input type="hidden" name="confirmar_registros" value="1">
        <?php } ?>
        <button type="submit" class="botao-grande botao-verde">Largar agora</button>
        <?php if ($timeline->largadaMs !== null) { ?>
            <span class="contagem-aviso">A corrida já tem largada: isto substitui o horário atual. Pode ser desfeito cancelando a nova largada na lista abaixo.</span>
        <?php } ?>
    </form>

    <form method="POST" action="<?= $h($action) ?>" class="contagem-acao">
        <?= $csrf ?>
        <input type="hidden" name="acao" value="registrar">
        <input type="hidden" name="tipo" value="<?= $h(Ocorrencia::LARGADA) ?>">
        <?php // Typing a time and pressing "Salvar" is deliberate enough to replace a start. If entries
              // would fall before the new start, the server answers with a question instead of saving. ?>
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
            Nada é apagado: corrigir grava um novo horário, cancelar desfaz o registro (use quando o botão foi apertado por engano)
            e restaurar desfaz um cancelamento. Cancelar uma correção traz o horário anterior de volta.
        </p>
        <?php
        $eventForm = function (string $acao, int $eventId, string $button, ?string $question, string $extra = '') use ($h, $action, $csrf): string {
            return '<form method="POST" action="' . $h($action) . '"'
                . ($question !== null ? ' onsubmit="return confirm(' . $h(json_encode($question)) . ');"' : '') . '>'
                . $csrf . '<input type="hidden" name="acao" value="' . $h($acao) . '">'
                . '<input type="hidden" name="ocorrencia_id" value="' . $eventId . '">' . $extra
                . '<button type="submit">' . $h($button) . '</button></form>';
        };
        $eventRows = function (array $rows) use ($h, $situations, $situationLabels, $eventsById, $eventForm, $onlyStartId): void {
            foreach ($rows as $event) {
                $eventId = (int) $event->getOcorrenciaId();
                $situation = $situations[$eventId];
                $label = mb_strtolower(RaceControl::EVENT_LABELS[$event->getTipo()] ?? '');
                $isCancellation = in_array($situation, [RaceEvents::CANCELAMENTO, RaceEvents::CANCELAMENTO_DESFEITO], true);
                if ($isCancellation) {
                    $target = $eventsById[(int) $event->getSubstituiId()] ?? null;
                    $targetLabel = $target === null ? 'ocorrência' : (RaceControl::EVENT_LABELS[$target->getTipo()] ?? 'um cancelamento (restauração)');
                    $typeText = 'Cancelamento de ' . mb_strtolower($targetLabel)
                        . ($situation === RaceEvents::CANCELAMENTO_DESFEITO ? ' (desfeito: a ocorrência foi restaurada)' : '');
                } else {
                    $typeText = (RaceControl::EVENT_LABELS[$event->getTipo()] ?? $event->getTipo()) . $situationLabels[$situation];
                }
                $isOut = in_array($situation, [RaceEvents::CORRIGIDA, RaceEvents::CANCELADA, RaceEvents::CANCELAMENTO_DESFEITO], true);
                $time = '<input type="datetime-local" name="horario" step="1" required value="'
                    . $h(date('Y-m-d\TH:i:s', intdiv((int) $event->getTsMs(), 1000))) . '">';
                $cancelQuestion = $eventId === $onlyStartId
                    ? 'Esta é a ÚNICA largada. Cancelá-la deixa a corrida sem largada: a contagem de todos os carros volta a zero e os resultados publicados são zerados. Para mudar o horário use "Corrigir". Cancelar mesmo assim?'
                    : 'Cancelar esta ocorrência (' . $label . ')? Ela deixa de valer, como se não tivesse sido registrada.';
                ?>
                <tr<?= $isOut ? ' class="linha-substituida"' : '' ?>>
                    <td><?= $h($typeText) ?></td>
                    <td><?= $h(Pagina::formatClock((int) $event->getTsMs())) ?></td>
                    <td><?= $h($event->getUsuario()) ?></td>
                    <td><?= $h(Pagina::formatClock((int) $event->getCriadoEmMs())) ?></td>
                    <td class="sem-risco">
                        <?= $situation === RaceEvents::EM_VIGOR ? $eventForm('corrigir', $eventId, 'Corrigir', null, $time) : '' ?>
                    </td>
                    <td class="sem-risco">
                        <?php if ($situation === RaceEvents::EM_VIGOR || $situation === RaceEvents::SUPERADA) {
                            echo $eventForm('cancelar', $eventId, 'Cancelar', $cancelQuestion,
                                $eventId === $onlyStartId ? '<input type="hidden" name="confirmar_sem_largada" value="1">' : '');
                        } elseif ($situation === RaceEvents::CANCELADA) {
                            echo $eventForm('restaurar', $eventId, 'Restaurar', 'Restaurar esta ocorrência (' . $label . ')? Ela volta a valer com o horário que tinha.');
                        } ?>
                    </td>
                </tr>
            <?php }
        };
        $tableHead = '<thead><tr><th>Tipo</th><th>Horário</th><th>Por</th><th>Registrada em</th>'
            . '<th class="sorter-false">Corrigir horário</th><th class="sorter-false">Cancelar / restaurar</th></tr></thead>';
        ?>
        <h3>Em vigor</h3>
        <?php if ($eventsInForce === []) { ?>
            <p class="contagem-aviso">Nenhuma ocorrência em vigor: a corrida está sem largada.</p>
        <?php } else { ?>
            <table class="tablesorter contagem-tabela"><?= $tableHead ?><tbody><?php $eventRows($eventsInForce) ?></tbody></table>
        <?php } ?>
        <?php if ($eventsOfHistory !== []) { ?>
            <h3>Histórico: corrigidas, canceladas e cancelamentos</h3>
            <p class="contagem-dica">Estas linhas NÃO valem para a corrida. Uma ocorrência cancelada pode ser restaurada.</p>
            <table class="tablesorter contagem-tabela"><?= $tableHead ?><tbody><?php $eventRows($eventsOfHistory) ?></tbody></table>
        <?php } ?>
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
