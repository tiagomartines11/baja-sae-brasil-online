<?php
declare(strict_types=1);

namespace Baja\Contagem\Review;

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

    public const LABELS = [
        self::PASSAGENS => 'Passagens (registros brutos)',
        self::DECISOES => 'Decisões',
        self::VOLTAS => 'Voltas',
    ];

    private const QUERIES = [
        // ts_efetivo_ms is the time the engine uses; see the contract on
        // entries typed before the device knew its clock offset.
        self::PASSAGENS => '
            SELECT p.passagem_id, p.fonte_id, f.nome AS fonte, p.dispositivo_id, d.usuario,
                   p.uuid_cliente, p.equipe_numero, p.ts_dispositivo_ms, p.desvio_relogio_ms, p.ts_ms,
                   COALESCE(p.ts_ms, p.ts_dispositivo_ms + COALESCE(d.primeiro_desvio_ms, 0)) AS ts_efetivo_ms,
                   p.digitacao_ms, p.modo_entrada, p.recebido_em_ms
              FROM contagem_passagem p
              JOIN contagem_fonte f ON f.fonte_id = p.fonte_id
              JOIN contagem_dispositivo d ON d.dispositivo_id = p.dispositivo_id
             WHERE f.evento_id = :evento AND f.prova_id = :prova
             ORDER BY ts_efetivo_ms, p.passagem_id',
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
        if (!isset(self::QUERIES[$type])) {
            throw new InvalidArgumentException('Tipo de exportação desconhecido.');
        }

        $statement = Propel::getConnection('resultados')->prepare(self::QUERIES[$type]);
        $statement->execute([':evento' => $prova->getEventoId(), ':prova' => $prova->getProvaId()]);

        $fileName = sprintf('contagem-%s-%s-%s.csv', $prova->getEventoId(), $prova->getProvaId(), $type);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '', $fileName) . '"');
        header('Cache-Control: no-store');

        $output = fopen('php://output', 'w');
        // Byte-order mark: without it Excel reads the accents wrong.
        fwrite($output, "\xEF\xBB\xBF");

        $wroteHeader = false;
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
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
        if (is_string($value) && !is_numeric($value) && preg_match('/^[=+\-@\t\r]/', $value) === 1) {
            return "'" . $value;
        }
        return $value;
    }
}
