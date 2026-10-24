<?php
declare(strict_types=1);

namespace Baja\ResultsCheck;

/**
 * The text the check prints: event, then resultado (or prova), then each
 * kind of problem, then the problems themselves.
 *
 *     === 26BR ===
 *
 *     26BR_REL  Relatório de Projeto  (49 teams)
 *       Formula raises an error
 *         column "Pontos" val
 *           formula: '<strong>'+ROUND($RELPTS,2)+'<strong>'
 *           error:   TypeError: Unsupported operand types: string + float   (49 of 49 teams)
 *           fix:     '<strong>'.ROUND($RELPTS,2).'</strong>'
 */
final class Report
{
    /**
     * @param list<array{event: string, resultados: list<array>, provas: list<array>}> $events
     *        resultados: {resultado, nome, teams, findings, fix: ?FixProposal, skipped: ?string}
     *        provas:     {prova, nome, teams, findings}
     */
    public static function render(array $events, ?string $sqlFile): string
    {
        $out = [];
        $count = ['resultados' => 0, 'skipped' => 0, 'noTeams' => 0, 'provas' => 0, 'withProblems' => 0, 'provasWithProblems' => 0, 'fixes' => 0];
        $kinds = [];
        foreach ($events as $event) {
            $blocks = [];
            foreach ($event['resultados'] as $resultado) {
                $count['resultados']++;
                if ($resultado['skipped'] !== null) {
                    $count['skipped']++;
                    continue;
                }
                if ($resultado['teams'] === 0) {
                    $count['noTeams']++;
                }
                if ($resultado['findings'] === [] && $resultado['fix'] === null) {
                    continue;
                }
                $count['withProblems']++;
                $count['fixes'] += ($resultado['fix'] !== null && $resultado['fix']->changes !== []) ? 1 : 0;
                foreach ($resultado['findings'] as $finding) {
                    $kinds[$finding->problem] = ($kinds[$finding->problem] ?? 0) + 1;
                }
                $blocks[] = self::block($resultado['resultado'], $resultado['nome'], $resultado['teams'], $resultado['findings'], $resultado['fix']);
            }
            $provaBlocks = [];
            foreach ($event['provas'] as $prova) {
                $count['provas']++;
                if ($prova['findings'] !== []) {
                    $count['provasWithProblems']++;
                    $provaBlocks[] = self::block($prova['prova'], $prova['nome'], $prova['teams'], $prova['findings'], null);
                }
            }
            if ($provaBlocks !== []) {
                $blocks[] = "Provas (report only: saving a prova recomputes its stored points, BAJAAPP-56)\n\n"
                    . implode("\n", array_map(fn($block) => self::indent($block, '  '), $provaBlocks));
            }
            $out[] = '=== ' . $event['event'] . ' ===' . ($blocks === [] ? '  no problems' : "\n\n" . implode("\n", $blocks));
        }

        $summary = [
            sprintf(
                'Checked %d events: %d resultados (%d tournaments skipped, %d with no teams so formulas not run), %d provas.',
                count($events),
                $count['resultados'],
                $count['skipped'],
                $count['noTeams'],
                $count['provas'],
            ),
            sprintf('%d resultados and %d provas have problems.', $count['withProblems'], $count['provasWithProblems']),
        ];
        foreach ([Finding::FORMULA_ERROR, Finding::RANKING, Finding::EMPTY_COLUMN] as $kind) {
            $summary[] = sprintf('  %-28s %d in resultados', $kind . ':', $kinds[$kind] ?? 0);
        }
        $summary[] = $sqlFile === null
            ? sprintf('%d resultados have a mechanical fix; --sql <file> writes it.', $count['fixes'])
            : sprintf('%d resultados have a mechanical fix, written to %s.', $count['fixes'], $sqlFile);
        $summary[] = 'Everything without a "fix:" line needs a person. Nothing was written to the database.';

        return implode("\n", $out) . "\n\n" . implode("\n", $summary) . "\n";
    }

    /**
     * @param list<Finding> $findings
     */
    private static function block(string $id, string $nome, int $teams, array $findings, ?FixProposal $fix): string
    {
        $lines = [$id . '  ' . $nome . '  (' . $teams . ' team' . ($teams === 1 ? '' : 's') . ')'];
        $byKind = [];
        foreach ($findings as $finding) {
            $byKind[$finding->problem][] = $finding;
        }
        foreach ([Finding::FORMULA_ERROR, Finding::RANKING, Finding::EMPTY_COLUMN] as $kind) {
            if (!isset($byKind[$kind])) {
                continue;
            }
            $lines[] = '  ' . $kind;
            // One formula can raise several errors (PHP words "string +
            // float" and "string + int" apart): they are listed under it.
            $byPlace = [];
            foreach ($byKind[$kind] as $finding) {
                $byPlace[$finding->where . "\n" . json_encode($finding->formula)][] = $finding;
            }
            foreach ($byPlace as $same) {
                array_push($lines, ...self::place($same, $fix));
            }
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * @param non-empty-list<Finding> $findings same problem, place and formula
     * @return list<string>
     */
    private static function place(array $findings, ?FixProposal $fix): array
    {
        $first = $findings[0];
        if ($first->problem === Finding::RANKING) {
            return array_map(fn(Finding $finding) => '    ' . $finding->detail, $findings);
        }
        $lines = ['    ' . $first->where, '      formula: ' . self::formula($first->formula)];
        foreach ($findings as $finding) {
            $lines[] = $finding->problem === Finding::FORMULA_ERROR
                ? '      error:   ' . self::oneLine($finding->detail) . '   (' . $finding->teams . ' of ' . $finding->ofTeams . ' teams)'
                : '      ' . $finding->detail;
        }
        foreach ($fix->changes ?? [] as $change) {
            if ($change['where'] === $first->where) {
                $lines[] = '      fix:     ' . self::oneLine($change['new']);
            }
        }
        foreach ($fix->notVerified ?? [] as $change) {
            if ($change['where'] === $first->where) {
                $lines[] = '      no fix:  replacing "+" next to text gives ' . self::oneLine($change['new']);
                $lines[] = '               which still fails: ' . self::oneLine($change['error']);
            }
        }
        return $lines;
    }

    private static function formula(mixed $formula): string
    {
        return is_string($formula) ? self::oneLine($formula) : (string)json_encode($formula, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function oneLine(string $text): string
    {
        return preg_replace('/\s*[\r\n]+\s*/', ' ', $text) ?? $text;
    }

    private static function indent(string $text, string $by): string
    {
        return preg_replace('/^(?=.)/m', $by, $text) ?? $text;
    }
}
