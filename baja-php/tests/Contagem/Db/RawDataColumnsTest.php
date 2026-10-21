<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Review\CsvExport;
use Baja\Contagem\Service\RaceReset;
use Propel\Runtime\Propel;
use ReflectionClass;

/**
 * "Zerar corrida" and the CSV exports copy the raw tables with fixed column
 * lists. A column added to a table and forgotten in those lists is lost
 * without a sound: at 26SE-follow-up time the archive dropped which late
 * entry a "usar_tempo" joined (passagem_alvo_id) and the machine-vision
 * evidence (imagem_ref, confianca).
 *
 * These tests compare the lists with the live schema, so the next new
 * column cannot be left out silently.
 */
final class RawDataColumnsTest extends DbTestCase
{
    /**
     * SITUATION: a rehearsal is wiped with "Zerar corrida".
     *
     * EXPECTED: every column of every wiped table reaches the archive
     * (evento_id and prova_id are columns of contagem_arquivo itself).
     */
    public function testTheArchiveOfAWipedRaceKeepsEveryColumn(): void
    {
        $tables = (new ReflectionClass(RaceReset::class))->getConstant('TABLES');
        foreach ($tables as $table => $definition) {
            $missing = array_diff($this->columnsOf($table), $definition['columns'], ['evento_id', 'prova_id']);
            $this->assertSame([], array_values($missing), "Zerar corrida does not archive these columns of $table");
        }
    }

    /**
     * SITUATION: someone downloads "Passagens" or "Decisões" to answer a
     * protest.
     *
     * EXPECTED: every column of the table is in the file.
     */
    public function testTheRawCsvExportsCarryEveryColumn(): void
    {
        $queries = (new ReflectionClass(CsvExport::class))->getConstant('QUERIES');
        foreach (['contagem_passagem' => CsvExport::PASSAGENS, 'contagem_decisao' => CsvExport::DECISOES] as $table => $type) {
            $statement = Propel::getConnection('resultados')->prepare($queries[$type]);
            $statement->execute([':evento' => self::EVENTO_ID, ':prova' => self::PROVA_ID]);
            $exported = [];
            for ($i = 0; $i < $statement->columnCount(); $i++) {
                $exported[] = $statement->getColumnMeta($i)['name'];
            }
            $missing = array_diff($this->columnsOf($table), $exported, ['evento_id', 'prova_id']);
            $this->assertSame([], array_values($missing), "The $type export lacks these columns of $table");
        }
    }

    /** @return list<string> */
    private function columnsOf(string $table): array
    {
        $statement = Propel::getConnection('resultados')->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tabela ORDER BY ORDINAL_POSITION'
        );
        $statement->execute([':tabela' => $table]);
        $columns = $statement->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertNotEmpty($columns, $table);
        return $columns;
    }
}
