<?php
/**
 * Delete closed certificate reports past their retention period.
 *
 * Usage, from the repository root:
 *
 *   docker compose exec --user "$(id -u):$(id -g)" baja-app \
 *       php scripts/purge-certificado-requerimentos.php [--dry-run] [--days=730]
 *
 * Why this exists at all. `certificado_requerimento` holds a CPF, a full legal
 * name, an email address and sometimes a phone number, for people who are not
 * users of this system and never agreed to be in it — they wrote in because
 * something about their own certificate was wrong. LGPD Art. 15 III ends the
 * processing when the purpose is met, and Art. 16 keeps a record only while
 * it is needed. The purpose here is met when the report is closed; what
 * survives it is the change made to `participantes`, which carries its own
 * audit trail and is the thing anybody actually asks about later.
 *
 * Keeping a closed report forever is not neutral. It is a growing store of
 * identity documents whose only remaining function is to be breached.
 *
 * What is NOT deleted:
 *   - Open reports, at any age. An old one that nobody answered is a problem
 *     to fix, not a row to quietly drop.
 *   - Anything in `participantes`. Certificates are not touched by this, and
 *     a certificate correctly issued stays verifiable — see
 *     Baja\Certificado\Requerimento\Caso.
 *
 * The default of two years is a starting point, not a legal opinion. Whoever
 * owns the retention policy should set --days to whatever it actually says,
 * and this script is the place that number becomes real.
 *
 * Idempotent, and safe to run repeatedly: it only ever deletes rows already
 * past the cutoff, so a second run finds nothing. Meant for cron — the same
 * sidecar pattern as phpBB's periodic tasks (baja-infra/phpbb-baja/cron-loop.sh).
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/config.php';

use Baja\Model\CertificadoRequerimentoQuery;
use Propel\Runtime\ActiveQuery\Criteria;

$options = getopt('', ['dry-run', 'days::']);
$dryRun  = array_key_exists('dry-run', $options);
$days    = max(1, (int) ($options['days'] ?? 730));

$corte = new DateTime('-' . $days . ' days');

/*
 * Closed means resolved or refused. A report still in `aberto` or
 * `em_analise` is live work and is never swept, however old — and if this
 * script starts reporting that there are many of those, that is the finding.
 */
$consulta = CertificadoRequerimentoQuery::create()
    ->filterByStatus(['resolvido', 'recusado'], Criteria::IN)
    ->filterByResolvidoEm($corte, Criteria::LESS_THAN);

$alvo = $consulta->count();

$abertos = CertificadoRequerimentoQuery::create()
    ->filterByStatus(['aberto', 'em_analise'], Criteria::IN)
    ->filterByCriadoEm($corte, Criteria::LESS_THAN)
    ->count();

printf(
    "certificado_requerimento: %d closed report%s older than %d days%s\n",
    $alvo,
    $alvo === 1 ? '' : 's',
    $days,
    $dryRun ? ' (dry run, nothing deleted)' : ''
);

if ($abertos > 0) {
    printf(
        "  note: %d report%s older than %d days %s still open and %s not swept.\n",
        $abertos,
        $abertos === 1 ? '' : 's',
        $days,
        $abertos === 1 ? 'is' : 'are',
        $abertos === 1 ? 'was' : 'were'
    );
}

if ($alvo === 0 || $dryRun) {
    exit(0);
}

$removidos = $consulta->delete();

printf("  deleted %d row%s.\n", $removidos, $removidos === 1 ? '' : 's');

exit(0);
