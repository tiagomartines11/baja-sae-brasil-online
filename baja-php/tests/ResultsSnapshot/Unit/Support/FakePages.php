<?php
declare(strict_types=1);

namespace Baja\Tests\ResultsSnapshot\Unit\Support;

/**
 * Small, made-up results pages for the tests, printed the way
 * resultados/prova.php and resultados/torneio.php print theirs: the same
 * table markup (including the page's own quirks, such as `<th ">Equipe`),
 * inside a menu, header and footer that the tests can change.
 *
 * The team names are invented. Nothing here comes from the database.
 */
final class FakePages
{
    /** Three teams: position, car number, team, school, total, medal class. */
    public const TEAMS = [
        ['pos' => 1, 'num' => 12, 'team' => 'Equipe Alfa', 'school' => 'Escola Um', 'total' => '377.64'],
        ['pos' => 2, 'num' => 7, 'team' => 'Equipe Beta', 'school' => 'Escola Dois', 'total' => '301.20'],
        ['pos' => 3, 'num' => 33, 'team' => 'Equipe Gama', 'school' => 'Escola Três', 'total' => '250.00'],
    ];

    /**
     * A results page like prova.php's.
     *
     * @param list<array{pos: int, num: int, team: string, school: string, total: string}> $teams
     * @param array{menu?: string, css?: string, updatedAt?: string, indent?: string} $chrome
     *        what may change without any result changing: the menu text, the
     *        stylesheet URL, the "last updated" time, the source indentation
     */
    public static function prova(array $teams = self::TEAMS, array $chrome = []): string
    {
        $menu = $chrome['menu'] ?? 'Segurança | Projeto | Dinâmicas';
        $css = $chrome['css'] ?? 'css/theme.blue.css?version=3';
        $updatedAt = $chrome['updatedAt'] ?? '2024-03-24 17:02:11';
        $indent = $chrome['indent'] ?? '        ';

        $medals = [1 => 'ouro', 2 => 'prata', 3 => 'bronze'];
        $rows = '';
        foreach ($teams as $t) {
            $posCell = isset($medals[$t['pos']])
                ? "<td class='{$medals[$t['pos']]}'><p style='color:transparent'>{$t['pos']}º</p></td>"
                : "<td>{$t['pos']}º</td>";
            $rows .= "$indent<tr>$posCell<td>{$t['num']}</td>"
                . '<td style="text-align: left">' . $t['team'] . '<br /><i class="nomeEscola">' . $t['school'] . '</i></td>'
                . "<td><b>{$t['total']}</b></td></tr>\n";
        }

        return <<<HTML
            <!DOCTYPE html>
            <html><head>
            <title>Baja SAE BRASIL - Geral</title>
            <link class="theme" rel="stylesheet" href="$css">
            <script src="js/jquery.tablesorter.js"></script>
            </head><body class="body-blue">
            <div class="top-header"><ul class="nav"><li>$menu</li></ul></div>
            <script id="js">$(function(){ $("table").tablesorter(); });</script>
            <table id="myTable" class="tablesorter">
            {$indent}<thead><tr>
            {$indent}<th style="width:20px;"><img src="img/trofeu.png"></th><th style=""  >#</th><th ">Equipe<br /><p class="nomeEscola">Escola</p></th><th style=""  >Total</th>
            {$indent}</tr></thead>
            <tbody>
            $rows</tbody>
            <tfoot><th colspan="4">Prova Finalizada<br />Última Atualização: $updatedAt</th></tfoot>
            </table>
            </body></html>
            HTML;
    }

    /**
     * A one-match bracket like torneio.php's: "Final", best of 3, the winner
     * marked by a class on its row.
     */
    public static function torneio(string $winnerClassOn = 'first'): string
    {
        $first = $winnerClassOn === 'first' ? ' tournament-bracket__team--winner' : '';
        $second = $winnerClassOn === 'second' ? ' tournament-bracket__team--winner' : '';
        return <<<HTML
            <html><head><meta http-equiv="refresh" content="5" /></head><body>
            <div class="container"><h1>Super Prime</h1>
            <div class="tournament-bracket tournament-bracket--rounded">
            <div class="tournament-bracket__round tournament-bracket__round--0">
              <h3 class="tournament-bracket__round-title">Final</h3>
              <ul class="tournament-bracket__list"><li class="tournament-bracket__item"><div class="tournament-bracket__match">
                <table class="tournament-bracket__table">
                  <caption class="tournament-bracket__caption"><time>Melhor de 3</time></caption>
                  <thead class="sr-only"><tr><th>Vitórias</th><th>Equipe</th></tr></thead>
                  <tbody class="tournament-bracket__content">
                    <tr class="tournament-bracket__team$first"><td class="tournament-bracket__score"><abbr>12 - Alfa</abbr></td><td class="tournament-bracket__score">2</td></tr>
                    <tr class="tournament-bracket__team$second"><td class="tournament-bracket__score"><abbr>7 - Beta</abbr></td><td class="tournament-bracket__score">1</td></tr>
                  </tbody>
                </table>
              </div></li></ul>
            </div>
            </div></div>
            HTML;
    }
}
