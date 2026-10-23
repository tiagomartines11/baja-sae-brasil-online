<?php
declare(strict_types=1);

namespace Baja\Contagem\Review;

use Baja\Contagem\Engine\Data\Source;
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

    /**
     * Each entry of an observer source (and each official pass it missed)
     * against the official count. Not a table: worked out by the engine's
     * ObserverComparator. Offered only while some source is an observer.
     */
    public const OBSERVACAO = 'observacao';

    public const LABELS = [
        self::PASSAGENS => 'Passagens (registros brutos)',
        self::DECISOES => 'Decisões',
        self::VOLTAS => 'Voltas',
        self::PARADAS => 'Paradas (box e abastecimento)',
    ];

    public const OBSERVER_LABEL = 'Fonte observadora x contagem oficial';

    /**
     * Appended to the Passagens export: for a timing sensor's mark, the car
     * whose pass it times (empty for a mark that times none, and for every
     * other entry).
     */
    public const COLUNA_MARCA_CRONOMETRA = 'marca_cronometra_carro';

    /** Appended to the Voltas export: the pass time of the row is a timing sensor's mark ("sim"/"não"). */
    public const COLUNA_TEMPO_DO_SENSOR = 'tempo_do_sensor';

    /**
     * The exports to offer for this prova: the usual ones, plus the
     * observer comparison when some source is or was an observer.
     *
     * @return array<string, string>
     */
    public static function labels(Prova $prova): array
    {
        foreach (SourceAdmin::sources($prova) as $fonte) {
            if (SourceAdmin::hasObserved($fonte)) {
                return self::LABELS + [self::OBSERVACAO => self::OBSERVER_LABEL];
            }
        }
        return self::LABELS;
    }

    private const QUERIES = [
        // ts_ms is THE time of the pass: worked out by the count page from
        // its server-anchored clock and never changed after it was stored.
        // qualidade_tempo says how far to trust it; the device's own clock
        // and the anchor are there for audit only.
        self::PASSAGENS => '
            SELECT p.passagem_id, p.fonte_id, f.nome AS fonte, p.dispositivo_id, d.usuario,
                   p.uuid_cliente, p.equipe_numero, p.ts_ms, p.qualidade_tempo, p.ts_dispositivo_ms,
                   p.ancora_servidor_ms, p.ancora_rtt_ms, p.ancora_idade_ms,
                   p.digitacao_ms, p.modo_entrada, p.recebido_em_ms, p.imagem_ref, p.confianca
              FROM contagem_passagem p
              JOIN contagem_fonte f ON f.fonte_id = p.fonte_id
              JOIN contagem_dispositivo d ON d.dispositivo_id = p.dispositivo_id
             WHERE f.evento_id = :evento AND f.prova_id = :prova
             ORDER BY p.ts_ms, p.passagem_id',
        self::DECISOES => '
            SELECT decisao_id, tipo, passagem_id, passagem_alvo_id, fonte_id, equipe_numero, ts_ms, ts_fim_ms,
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
        if ($type === self::OBSERVACAO) {
            self::send($prova, $type, self::observerRows(ContagemService::forProva($prova)));
            return;
        }
        if (!isset(self::QUERIES[$type])) {
            throw new InvalidArgumentException('Tipo de exportação desconhecido.');
        }
        $timing = in_array($type, [self::PASSAGENS, self::VOLTAS], true) ? self::sensorTiming($prova) : null;
        $statement = Propel::getConnection('resultados')->prepare(self::QUERIES[$type]);
        $statement->execute([':evento' => $prova->getEventoId(), ':prova' => $prova->getProvaId()]);
        // One row at a time: a race has tens of thousands of entries.
        $oneByOne = (function () use ($statement, $type, $timing): \Generator {
            while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                // The columns sensor timing adds come last, so that the
                // ones before keep their places.
                if ($type === self::PASSAGENS) {
                    $row[self::COLUNA_MARCA_CRONOMETRA] = $timing['carro_da_marca'][(int) $row['passagem_id']] ?? null;
                } elseif ($type === self::VOLTAS) {
                    $key = $row['equipe_numero'] . ':' . $row['numero_volta'] . ':' . $row['ts_ms'];
                    $row[self::COLUNA_TEMPO_DO_SENSOR] = isset($timing['voltas'][$key]);
                }
                yield $row;
            }
        })();
        self::send($prova, $type, $oneByOne);
    }

    /**
     * Which passes a timing sensor's marks time, for the exports: the car
     * each mark times, and the laps whose pass time is a mark (by car,
     * number and time, as the rows of contagem_volta). Worked out by a run
     * of the whole engine, only when the prova has a sensor; null otherwise,
     * and nothing is run.
     *
     * @return array{carro_da_marca: array<int, int>, voltas: array<string, true>}|null
     */
    private static function sensorTiming(Prova $prova): ?array
    {
        $anySensor = false;
        foreach (SourceAdmin::sources($prova) as $fonte) {
            $anySensor = $anySensor || $fonte->getTipo() === Source::TIPO_SENSOR;
        }
        if (!$anySensor) {
            return null;
        }
        $result = ContagemService::forProva($prova)->compute();
        $timing = ['carro_da_marca' => [], 'voltas' => []];
        foreach ($result->groups as $group) {
            if ($group->marcaSensor !== null) {
                $timing['carro_da_marca'][$group->marcaSensor->id] = $group->carro;
            }
        }
        foreach ($result->laps as $lap) {
            if ($lap->grupo !== null && $lap->grupo->tempoDoSensor) {
                $timing['voltas'][$lap->carro . ':' . $lap->numeroVolta . ':' . $lap->tsMs] = true;
            }
        }
        return $timing;
    }

    /**
     * The observer comparison, one row per observer entry or missed pass,
     * with the source's name. Runs the whole engine; writes nothing.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function observerRows(ContagemService $service): array
    {
        $result = $service->compute();
        $names = [];
        foreach (SourceAdmin::sources($service->getProva()) as $fonte) {
            $names[(int) $fonte->getFonteId()] = (string) $fonte->getNome();
        }
        $rows = [];
        foreach ($result->observerRows as $row) {
            $rows[] = ['fonte_id' => $row['fonte_id'], 'fonte' => $names[$row['fonte_id']] ?? ''] + $row;
        }
        return $rows;
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
