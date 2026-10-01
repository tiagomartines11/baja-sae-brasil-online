<?php
namespace Baja\Juiz;

use Baja\Model\Map\TimerTableMap;
use Propel\Runtime\Propel;

// State sync for timer.php. Every device showing a timer polls this, and posts
// the actions taken on it. A device that was offline posts its backlog when it
// reconnects, so each action carries the time it happened and the state it
// produced: the log gets every action, the stored state is the latest one.

const ACOES = ['iniciar', 'pausar', 'retomar', 'parar', 'proxima', 'anterior', 'ir_para', 'reiniciar_etapa'];

header('Content-Type: application/json');
header('Cache-Control: no-store');

list($timer, $quem) = TimerAcesso::resolver();
if (!$timer) {
    http_response_code(403);
    die(json_encode(['ok' => false]));
}

$input = json_decode(file_get_contents('php://input'), true);
$acoes = (is_array($input) && isset($input['acoes']) && is_array($input['acoes'])) ? $input['acoes'] : [];

if (count($acoes) && count($timer->getConfig()->etapas ?? [])) {
    $con = Propel::getWriteConnection(TimerTableMap::DATABASE_NAME);
    $con->beginTransaction();
    try {
        // Lock the row: two devices posting at once must not drop each other's log entries.
        $lock = $con->prepare('SELECT 1 FROM timer WHERE evento_id = ? AND timer_id = ? FOR UPDATE');
        $lock->execute([$timer->getEventoId(), $timer->getTimerId()]);
        $timer->reload(false, $con);

        $etapas = $timer->getConfig()->etapas ?? [];
        $state = $timer->getState();
        $vistos = array_column(array_slice($timer->getLog(), -100), 'id');
        $agora = (int)round(microtime(true) * 1000);

        foreach ($acoes as $a) {
            if (!is_array($a) || !in_array(@$a['acao'], ACOES, true) || !isset($a['state']) || !is_array($a['state'])) continue;
            $id = substr((string)@$a['id'], 0, 16);
            if ($id === '' || in_array($id, $vistos, true)) continue;
            $vistos[] = $id;

            $s = $a['state'];
            $step = max(0, min(count($etapas) - 1, (int)@$s['step']));
            $novo = [
                'status' => in_array(@$s['status'], ['running', 'paused', 'stopped'], true) ? $s['status'] : 'stopped',
                'step' => $step,
                'started_at' => (int)@$s['started_at'],
                'remaining_ms' => max(0, min($etapas[$step]->tempo * 1000, (int)@$s['remaining_ms'])),
                // A device with a wrong clock must not be able to win every later comparison.
                'at' => min((int)@$a['at'], $agora + 2000),
            ];

            $timer->appendLog($quem, $a['acao'], ['etapa' => $step + 1, 'id' => $id], intdiv($novo['at'], 1000));
            if (!$state || $novo['at'] > $state['at']) $state = $novo;
        }

        $timer->setState($state);
        $timer->save($con);
        $con->commit();
    } catch (\Exception $e) {
        $con->rollBack();
        http_response_code(500);
        die(json_encode(['ok' => false]));
    }
}

$config = $timer->getConfig();
$hash = md5(json_encode($config));

$out = [
    'ok' => true,
    'now' => (int)round(microtime(true) * 1000),
    'state' => $timer->getState(),
    'hash' => $hash,
];
if (@$_REQUEST['h'] !== $hash) $out['config'] = $config;

echo json_encode($out);
