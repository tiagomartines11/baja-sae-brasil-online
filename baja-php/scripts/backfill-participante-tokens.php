<?php
/**
 * Give every existing participantes row a certificate token.
 *
 * Usage, from the repository root:
 *
 *   docker compose exec --user "$(id -u):$(id -g)" baja-app \
 *       php scripts/backfill-participante-tokens.php [--dry-run] [--chunk=500]
 *
 * Idempotent: it only ever touches rows where token IS NULL, so a re-run after
 * an interruption picks up where it stopped and a re-run after completion does
 * nothing. Safe to run against a live database — no row is rewritten twice and
 * nothing is deleted.
 *
 * SEQUENCING. Do not run this before the cleaned participant data has been
 * imported. If the import replaces rows rather than updating them, it discards
 * the tokens assigned here, and any certificate downloaded in between carries a
 * /verificar/{token} link that no longer resolves. Import first, backfill after.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/config.php';

use Baja\Certificado\Token;
use Baja\Model\Map\ParticipanteTableMap;
use Baja\Model\ParticipanteQuery;
use Propel\Runtime\Propel;

$options   = getopt('', ['dry-run', 'chunk::']);
$dryRun    = array_key_exists('dry-run', $options);
$chunkSize = max(1, (int) ($options['chunk'] ?? 500));

$connection = Propel::getWriteConnection(ParticipanteTableMap::DATABASE_NAME);

$remaining = ParticipanteQuery::create()->filterByToken(null)->count();
$total     = ParticipanteQuery::create()->count();

printf(
    "participantes: %d rows, %d without a token%s\n",
    $total,
    $remaining,
    $dryRun ? ' (dry run, nothing will be written)' : ''
);

if ($remaining === 0) {
    echo "Nothing to do.\n";
    exit(0);
}

if ($dryRun) {
    printf("Would assign %d tokens in chunks of %d.\n", $remaining, $chunkSize);
    exit(0);
}

$assigned = 0;

/*
 * Re-query each round rather than paginating. The filter is "token IS NULL"
 * and the loop body is what makes rows stop matching it, so an offset would
 * skip a chunk's worth of rows on every iteration after the first.
 */
while (true) {
    $chunk = ParticipanteQuery::create()
        ->filterByToken(null)
        ->limit($chunkSize)
        ->find($connection);

    if (count($chunk) === 0) {
        break;
    }

    /*
     * One transaction per chunk, not per row and not one for the whole table.
     * A 42k-row transaction holds locks for its entire duration on a table
     * that serves live certificate lookups; per-row transactions would pay the
     * commit cost 42,000 times.
     */
    $connection->beginTransaction();
    try {
        foreach ($chunk as $participante) {
            $participante->setToken(Token::generate());
            $participante->save($connection);
            $assigned++;
        }
        $connection->commit();
    } catch (\Throwable $e) {
        $connection->rollBack();
        fwrite(STDERR, sprintf(
            "Failed after %d assignments: %s\n",
            $assigned,
            $e->getMessage()
        ));
        fwrite(STDERR, "Nothing in the failed chunk was written. Re-run to continue.\n");
        exit(1);
    }

    printf("  %d/%d\n", $assigned, $remaining);
}

printf("Assigned %d tokens.\n", $assigned);

$stillMissing = ParticipanteQuery::create()->filterByToken(null)->count();
if ($stillMissing > 0) {
    fwrite(STDERR, sprintf("WARNING: %d rows still have no token.\n", $stillMissing));
    exit(1);
}

echo "Every participantes row now has a token.\n";
