<?php
/**
 * How long one resultado takes to build with each formula engine.
 *
 *     php scripts/formula-engine-benchmark.php 26BR_GER
 *     php scripts/formula-engine-benchmark.php 26BR_GER --runs 9
 *
 * For each engine (eval, safe, shadow), several fresh PHP processes each
 * load the resultado's config and teams once and the engine's classes (not
 * timed), then build its table with Baja\Results\ResultsBuilder: once
 * "cold", as a page request does (SafeEngine has read none of its
 * formulas yet), then 20 times "warm". It
 * prints the median of each, and checks that every engine built the same
 * table. Read-only. See docs/formula-engine.md.
 */

use Baja\Formula\FormulaEngines;
use Baja\Model\Input;
use Baja\Model\ResultadoQuery;
use Baja\Results\EventStatus;
use Baja\Results\ResultsBuilder;
use Baja\Results\ResultsConfig;
use Baja\Results\TeamInput;
use Baja\Results\ViewerContext;
use Baja\ResultsSnapshot\Capture\ReadOnlyDatabase;

$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';
date_default_timezone_set('America/Sao_Paulo');
foreach (['Pos', 'EquipeNum', 'Equipe'] as $constant) {
    defined($constant) || define($constant, $constant);  // as src/bootstrap.php does
}

$arguments = array_slice($argv, 1);
$id = null;
$runs = 5;
$engine = null;
for ($i = 0; $i < count($arguments); $i++) {
    if ($arguments[$i] === '--runs') {
        $runs = max(1, (int)($arguments[++$i] ?? 5));
    } elseif ($arguments[$i] === '--child') {
        $engine = (string)($arguments[++$i] ?? '');
    } else {
        $id = $arguments[$i];
    }
}
if ($id === null) {
    fwrite(STDERR, "Usage: php scripts/formula-engine-benchmark.php <resultado_id> [--runs <n>]\n");
    exit(2);
}

if ($engine !== null) {
    // One measurement, in this fresh process.
    require_once $projectRoot . '/src/config.php';
    ReadOnlyDatabase::enforce();
    $resultado = ResultadoQuery::create()->findOneByResultadoId($id) ?? exit(2);
    $config = ResultsConfig::fromColunas(json_decode((string)json_encode($resultado->getColunas())));
    $inputs = TeamInput::forResultado($resultado);
    $view = ViewerContext::publicView(new EventStatus(spoilers: false, finished: true));
    Input::setFormulaEngine(FormulaEngines::named($engine, fn() => null));
    // Not timed: loading the engine's classes. On the site php-fpm's opcache
    // keeps them compiled; this CLI process has no opcache and would
    // compile them here, which a page never does. No formula of the
    // resultado is read by this.
    Input::solveFormula([], '0');
    ob_start();  // shadow mode would log a difference here; there should be none

    $start = hrtime(true);
    $table = ResultsBuilder::build($config, $inputs, $view);
    $cold = (hrtime(true) - $start) / 1e6;
    $warm = [];
    for ($i = 0; $i < 20; $i++) {
        $start = hrtime(true);
        ResultsBuilder::build($config, $inputs, $view);
        $warm[] = (hrtime(true) - $start) / 1e6;
    }
    ob_end_clean();
    sort($warm);
    echo json_encode(['cold' => $cold, 'warm' => $warm[10], 'rows' => count($table->rows), 'table' => md5(serialize($table))]), "\n";
    exit(0);
}

$median = static function (array $values): float {
    sort($values);
    return $values[intdiv(count($values), 2)];
};
printf("%s: %d fresh processes per engine, each 1 cold build then 20 warm ones (median ms)\n\n", $id, $runs);
printf("  %-7s %10s %10s\n", 'engine', 'cold', 'warm');
$tables = [];
foreach (['eval', 'safe', 'shadow'] as $name) {
    $cold = [];
    $warm = [];
    for ($run = 0; $run < $runs; $run++) {
        $line = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($id) . ' --child ' . $name);
        $one = json_decode((string)$line, true) ?? exit("The $name run failed: $line\n");
        $cold[] = $one['cold'];
        $warm[] = $one['warm'];
        $tables[$name . ':' . $one['table']] = $one['rows'];
    }
    printf("  %-7s %10.1f %10.1f\n", $name, $median($cold), $median($warm));
}
$same = count(array_unique(array_map(fn($key) => explode(':', $key)[1], array_keys($tables)))) === 1;
printf("\n  %d rows; %s\n", reset($tables), $same ? 'every engine built the same table' : 'THE ENGINES BUILT DIFFERENT TABLES');
exit($same ? 0 : 1);
