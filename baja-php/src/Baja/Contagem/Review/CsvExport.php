<?php
declare(strict_types=1);

namespace Baja\Contagem\Review;

use Baja\Contagem\Service\ContagemService;
use Baja\Contagem\Service\StopReport;
use Baja\Model\Prova;
use InvalidArgumentException;
use PDO;
use Propel\Runtime\Propel;

/**
 * CSV exports of the raw tables, for whoever has to answer a protest after
 * the race or wants to check the count in a spreadsheet.
 *
 * Rows are streamed straight from the database: a race has tens of thousands
 * of entries and there is no reason to hold them all in memory.
 */
final class CsvExport
{
    public const PASSAGENS = 'passagens';
    public const DECISOES = 'decisoes';
    public const VOLTAS = 'voltas';
    /** Pit lane and fueling stays per car. Not a table: worked out by Service\StopReport. */
    public const PARADAS = 'paradas';

    public const LABELS = [
        self::PASSAGENS => 'Passagens (registros brutos)',
        self::DECISOES => 'Decisões',
        self::VOLTAS => 'Voltas',
        self::PARADAS => 'Paradas (box e abastecimento)',
    ];

    private const QUERIES = [
        // ts_ms is THE time of the pass: worked out by the count page from
        // its server-anchored clock and never changed after it was stored.
        // qualidade_tempo says how far to trust it; the device's own clock
        // and the anchor are there for audit only.
        self::PASSAGENS => '
            SELECT p.passagem_id, p.fonte_id, f.nome AS fonte, p.dispositivo_id, d.usuario,
                   p.uuid_cliente, p.equipe_numero, p.ts_ms, p.qualidade_tempo, p.ts_dispositivo_ms,
                   p.ancora_servidor_ms, p.ancora_rtt_ms, p.ancora_idade_ms,
                   p.digitacao_ms, p.modo_entrada, p.recebido_em_ms
              FROM contagem_passagem p
              JOIN contagem_fonte f ON f.fonte_id = p.fonte_id
              JOIN contagem_dispositivo d ON d.dispositivo_id = p.dispositivo_id
             WHERE f.evento_id = :evento AND f.prova_id = :prova
             ORDER BY p.ts_ms, p.passagem_id',
        self::DECISOES => '
            SELECT decisao_id, tipo, passagem_id, fonte_id, equipe_numero, ts_ms, ts_fim_ms,
                   desfaz_decisao_id, origem, usuario, motivo, criado_em_ms
              FROM contagem_decisao
             WHERE evento_id = :evento AND prova_id = :prova
             ORDER BY decisao_id',
        self::VOLTAS => '
            SELECT equipe_numero, numero_volta, estado, ts_ms, tempo_volta_ms, dispersao_ms,
                   fontes, elegivel_melhor_volta
              FROM contagem_volta
             WHERE evento_id = :evento AND prova_id = :prova
             ORDER BY equipe_numero, numero_volta',
    ];

    /**
     * Sends the download headers and the file, then returns.
     *
     * @throws InvalidArgumentException for an unknown export type (before any output)
     */
    public static function stream(Prova $prova, string $type): void
    {
        if ($type === self::PARADAS) {
            self::send($prova, $type, StopReport::rows(ContagemService::forProva($prova)));
            return;
        }
        if (!isset(self::QUERIES[$type])) {
            throw new InvalidArgumentException('Tipo de exportação desconhecido.');
        }
        $statement = Propel::getConnection('resultados')->prepare(self::QUERIES[$type]);
        $statement->execute([':evento' => $prova->getEventoId(), ':prova' => $prova->getProvaId()]);
        // One row at a time: a race has tens of thousands of entries.
        $oneByOne = (function () use ($statement): \Generator {
            while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                yield $row;
            }
        })();
        self::send($prova, $type, $oneByOne);
    }

    /**
     * @param iterable<array<string, mixed>> $rows each row keyed by column name
     */
    private static function send(Prova $prova, string $type, iterable $rows): void
    {
        $fileName = sprintf('contagem-%s-%s-%s.csv', $prova->getEventoId(), $prova->getProvaId(), $type);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '', $fileName) . '"');
        header('Cache-Control: no-store');

        $output = fopen('php://output', 'w');
        // Byte-order mark: without it Excel reads the accents wrong.
        fwrite($output, "\xEF\xBB\xBF");

        $wroteHeader = false;
        foreach ($rows as $row) {
            if (!$wroteHeader) {
                fputcsv($output, array_keys($row), ';', '"', '');
                $wroteHeader = true;
            }
            fputcsv($output, array_map([self::class, 'safeCell'], $row), ';', '"', '');
        }
        if (!$wroteHeader) {
            fputcsv($output, ['sem dados'], ';', '"', '');
        }
        fclose($output);
    }

    /**
     * Text typed by people (a reason, a username) is opened later in a
     * spreadsheet, which would run a cell starting with "=" as a formula.
     * A leading apostrophe makes the spreadsheet treat it as plain text.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function safeCell($value)
    {
        if (is_bool($value)) {
            return $value ? 'sim' : 'não';
        }
        if (is_string($value) && !is_numeric($value) && preg_match('/^[=+\-@\t\r]/', $value) === 1) {
            return "'" . $value;
        }
        return $value;
    }
}
