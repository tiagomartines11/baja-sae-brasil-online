<?php
declare(strict_types=1);

namespace Baja\ResultsSnapshot\Jobs;

use Baja\Model\PremiacaoQuery;
use Baja\Premiacao\Resolver;

/**
 * What Baja\Premiacao\Resolver::resolve() returns for one premiação: per
 * category, the headers and the teams that win it. Runs inside the child
 * started by JobTask::premiacao() (scripts/results-snapshot/job.php).
 *
 * Resolver works out the ranking on its own, separately from prova.php;
 * BAJAAPP-54 is about to make both share one builder, so this is half of
 * what that refactor must keep equal.
 */
final class PremiacaoJob
{
    /** @return array<string, mixed> */
    public static function run(string $premiacaoId): array
    {
        $premiacao = PremiacaoQuery::create()->findPk($premiacaoId);
        if ($premiacao === null) {
            throw new \RuntimeException('Premiação not found: ' . $premiacaoId);
        }
        return [
            'premiacao' => $premiacao->getPremiacaoId(),
            'evento' => $premiacao->getEventoId(),
            'nome' => $premiacao->getNome(),
            'categorias' => Resolver::resolve($premiacao),
        ];
    }
}
