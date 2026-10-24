<?php
declare(strict_types=1);

namespace Baja\ResultsSnapshot\Tasks;

use Baja\ResultsSnapshot\Capture\ChildProcess;
use Baja\ResultsSnapshot\Capture\ErrorText;
use Baja\ResultsSnapshot\Html\ResultsPageReader;

/**
 * One results page, for one resultado, as the public sees it.
 *
 * The page runs unchanged in its own process, through
 * scripts/results-snapshot/page-prepend.php, with what nginx would give it.
 * Its HTML is then reduced to the results table(s), see ResultsPageReader.
 */
final class PageTask extends Task
{
    /** The page file under resultados/ => what the snapshot file name ends with. */
    private const PAGES = [
        'prova.php' => '',
        'torneio.php' => '.torneio',
    ];

    /**
     * @param string $page         one of the keys of PAGES
     * @param bool   $duringEvent  show the event with spoilers = 1 and finalizado = 0,
     *                             as the public sees it while the event is running
     */
    public function __construct(
        private string $page,
        private string $eventoId,
        private string $resultadoId,
        private bool $duringEvent = false,
    ) {
        if (!array_key_exists($page, self::PAGES)) {
            throw new \InvalidArgumentException('Unknown results page: ' . $page);
        }
    }

    public function path(): string
    {
        return $this->eventoId . '/' . $this->resultadoId . self::PAGES[$this->page]
            . ($this->duringEvent ? '.spoilers' : '') . '.json';
    }

    public function child(string $projectRoot, string $resultFile, string $stderrFile): ChildProcess
    {
        return new ChildProcess(
            arguments: [$projectRoot . '/resultados/' . $this->page],
            environment: [
                // The fastcgi_params of baja-app-resultados.conf.template.
                'REDIRECT_EVENT' => $this->eventoId,
                'SKIP_AUTH' => '1',
                // Production error display: errors are logged, not shown.
                'ENV' => 'prod',
                'SNAPSHOT_QUERY' => http_build_query(['id' => $this->resultadoId]),
                'SNAPSHOT_SCRIPT_NAME' => '/' . $this->page,
                'SNAPSHOT_SPOILERS' => $this->duringEvent ? '1' : '0',
            ],
            // php-fpm runs a script from its own folder; relative includes
            // and paths in the pages count on it.
            workingDirectory: $projectRoot . '/resultados',
            resultFile: $resultFile,
            stderrFile: $stderrFile,
            ini: ['auto_prepend_file' => $projectRoot . '/scripts/results-snapshot/page-prepend.php'],
        );
    }

    protected function document(?array $answer, ErrorText $errors): array
    {
        $html = (string)($answer['html'] ?? '');
        $document = [
            'page' => 'resultados/' . $this->page,
            'evento' => $this->eventoId,
            'id' => $this->resultadoId,
            'spoilers' => $this->duringEvent,
        ];
        $status = $answer['status'] ?? null;
        if (is_int($status) && $status >= 300 && $status < 400) {
            // The PHP command line cannot show the Location header itself.
            $document['redirect'] = 'HTTP ' . $status;
        }
        if ($this->page === 'torneio.php') {
            $document['rounds'] = ResultsPageReader::tournamentRounds($html);
        } else {
            $document['tables'] = ResultsPageReader::tables($html);
        }
        return $document + self::problems($answer, $errors);
    }
}
