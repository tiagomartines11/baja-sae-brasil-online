<?php
/**
 * Lists the tournament configs and brackets the BAJAAPP-37 fixes may leave
 * wrong, for a person to decide what to do with each. Changes nothing.
 *
 *     php scripts/check-tournament-configs.php
 *
 * 1. Provas whose seeding.classification has a criterion with order "asc"
 *    and a negated formula: inverted twice now that "asc" is honoured.
 * 2. Brackets whose match for third place holds a car that won its
 *    semifinal: filled by the old single-race bug.
 *
 * What each finding means is in src/Baja/Torneio/Verificacao.php.
 *
 * The database comes from the same environment variables the app uses
 * (PHPBB_DB_HOST, PHPBB_DB_USER, PHPBB_DB_PASS; see src/config.php). The
 * connection is switched to read-only first, as the results snapshots do.
 */

use Baja\Model\ProvaQuery;
use Baja\Model\TournamentQuery;
use Baja\ResultsSnapshot\Capture\ReadOnlyDatabase;
use Baja\Torneio\Verificacao;

$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';
require_once $projectRoot . '/src/config.php';
ReadOnlyDatabase::enforce();

echo "1. Seeding criteria with order \"asc\" and a negated formula\n\n";
$encontrados = 0;
foreach (ProvaQuery::create()->orderByEventoId()->orderByProvaId()->find() as $prova) {
    $params = $prova->getParams();
    if (!is_object($params)) {
        continue;
    }
    foreach (Verificacao::criteriosInvertidos($params) as $c) {
        $frozen = !empty($params->status->frozen) ? 'frozen' : 'not frozen';
        printf("   %s %s  criterion %d (%s): %s   [classification %s]\n",
            $prova->getEventoId(), $prova->getProvaId(), $c['criterio'] + 1, $c['title'] ?? '-', $c['formula'], $frozen);
        $encontrados++;
    }
}
echo $encontrados === 0 ? "   none\n" : '';

echo "\n2. Third-place matches holding a semifinal's winner\n\n";
$chaves = [];
foreach (TournamentQuery::create()->orderByEventoId()->orderByProvaId()->orderByMatchId()->find() as $m) {
    $chaves[$m->getEventoId() . ' ' . $m->getProvaId()][] = $m;
}
$encontrados = 0;
foreach ($chaves as $chave => $matches) {
    foreach (Verificacao::terceiroLugarComVencedor($matches) as $a) {
        printf("   %s  match %d slot %d: car %d, winner of semifinal %d   [%s]\n",
            $chave, $a['match'], $a['slot'], $a['carro'], $a['semifinal'],
            $a['decidido'] ? 'third place DECIDED' : 'third place not decided yet');
        $encontrados++;
    }
}
echo $encontrados === 0 ? "   none\n" : '';
