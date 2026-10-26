<?php
/**
 * The PHP half of the event archives (BAJAAPP-67), driven by
 * baja-infra/scripts/backup-run.sh through the `results-archive` compose
 * service. Not for running by hand, except to look:
 *
 *     php scripts/results-archive.php pending
 *         Prints one line per request to archive now: "<id>\t<event>".
 *         Cancels, first, the requests whose event is no longer finalizado.
 *
 *     php scripts/results-archive.php snapshot <id> <dir>
 *         Writes <dir>/request.json and runs
 *             results-snapshot.php --update --dir <dir>/snapshots --events <event>
 *         as the read-only account in ARCHIVE_DB_USER / ARCHIVE_DB_PASS.
 *
 *     php scripts/results-archive.php done <id> <folder>
 *     php scripts/results-archive.php failed <id> <message>
 *         Records the outcome of one request.
 *
 * The queue itself is read and written as the app's own account
 * (PHPBB_DB_USER, see src/config.php). The snapshot never is: it runs in a
 * child process whose only database credentials are the read-only ones,
 * and results-snapshot.php also switches every session to read-only. A
 * missing ARCHIVE_DB_USER is refused rather than replaced by the app's
 * account. Everything is explained in baja-infra/docs/backup-restore.md,
 * "Event archives".
 *
 * Output meant for backup-run.sh goes to stdout; everything else to stderr.
 * Exit codes: 0 done, 1 failed, 2 bad usage.
 */

use Baja\ResultsArchive\Queue;

$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';
require_once $projectRoot . '/src/config.php';

$fail = static function (string $message, int $code = 1): never {
    fwrite(STDERR, 'results-archive: ' . $message . "\n");
    exit($code);
};

$command = $argv[1] ?? '';
$queue = Queue::forApp();

switch ($command) {
    case 'pending':
        foreach ($queue->pending(Queue::nowMs()) as $request) {
            fwrite(STDOUT, $request['arquivamento_id'] . "\t" . $request['evento_id'] . "\n");
        }
        exit(0);

    case 'snapshot':
        $id = (int) ($argv[2] ?? 0);
        $dir = rtrim((string) ($argv[3] ?? ''), '/');
        if ($id <= 0 || $dir === '' || !is_dir($dir)) {
            $fail('usage: snapshot <id> <existing dir>', 2);
        }
        $request = $queue->find($id);
        if ($request === null || $request['estado'] !== Queue::PENDENTE) {
            $fail('request ' . $id . ' is not pending');
        }
        $user = getenv('ARCHIVE_DB_USER');
        if ($user === false || $user === '') {
            $fail('ARCHIVE_DB_USER is not set; the snapshot only runs as the read-only account');
        }

        $written = file_put_contents($dir . '/request.json', json_encode([
            'arquivamento_id' => (int) $request['arquivamento_id'],
            'evento_id' => (string) $request['evento_id'],
            'pedido_em_utc' => gmdate('Y-m-d\TH:i:s\Z', intdiv((int) $request['pedido_em_ms'], 1000)),
            'pedido_por' => $request['pedido_por'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
        if ($written === false) {
            $fail('cannot write ' . $dir . '/request.json');
        }

        // The child gets this process's environment with the database
        // account swapped for the read-only one.
        $env = getenv();
        $env['PHPBB_DB_USER'] = $user;
        $env['PHPBB_DB_PASS'] = (string) getenv('ARCHIVE_DB_PASS');
        $process = proc_open(
            [PHP_BINARY, $projectRoot . '/scripts/results-snapshot.php',
                '--update', '--dir', $dir . '/snapshots', '--events', (string) $request['evento_id']],
            [0 => ['file', '/dev/null', 'r'], 1 => STDERR, 2 => STDERR],
            $pipes,
            $projectRoot,
            $env
        );
        if (!is_resource($process)) {
            $fail('cannot start results-snapshot.php');
        }
        $code = proc_close($process);
        if ($code !== 0) {
            $fail('results-snapshot.php exited with ' . $code);
        }
        exit(0);

    case 'done':
        $id = (int) ($argv[2] ?? 0);
        $folder = (string) ($argv[3] ?? '');
        if ($id <= 0 || $folder === '') {
            $fail('usage: done <id> <folder>', 2);
        }
        try {
            $queue->markArchived($id, $folder, Queue::nowMs());
        } catch (RuntimeException $e) {
            $fail($e->getMessage());
        }
        exit(0);

    case 'failed':
        $id = (int) ($argv[2] ?? 0);
        if ($id <= 0) {
            $fail('usage: failed <id> <message>', 2);
        }
        $queue->markFailed($id, (string) ($argv[3] ?? 'unknown error'));
        exit(0);

    default:
        $fail('usage: pending | snapshot <id> <dir> | done <id> <folder> | failed <id> <message>', 2);
}
