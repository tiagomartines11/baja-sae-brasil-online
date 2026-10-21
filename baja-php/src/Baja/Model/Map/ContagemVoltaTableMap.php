<?php

namespace Baja\Model\Map;

use Baja\Model\ContagemVolta;
use Baja\Model\ContagemVoltaQuery;
use Propel\Runtime\Propel;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\InstancePoolTrait;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\DataFetcher\DataFetcherInterface;
use Propel\Runtime\Exception\PropelException;
use Propel\Runtime\Map\RelationMap;
use Propel\Runtime\Map\TableMap;
use Propel\Runtime\Map\TableMapTrait;


/**
 * This class defines the structure of the 'contagem_volta' table.
 *
 *
 *
 * This map class is used by Propel to do runtime db structure discovery.
 * For example, the createSelectSql() method checks the type of a given column used in an
 * ORDER BY clause to know whether it needs to apply SQL to make the ORDER BY case-insensitive
 * (i.e. if it's a text column type).
 */
class ContagemVoltaTableMap extends TableMap
{
    use InstancePoolTrait;
    use TableMapTrait;

    /**
     * The (dot-path) name of this class
     */
    public const CLASS_NAME = 'Baja.Model.Map.ContagemVoltaTableMap';

    /**
     * The default database name for this class
     */
    public const DATABASE_NAME = 'resultados';

    /**
     * The table name for this class
     */
    public const TABLE_NAME = 'contagem_volta';

    /**
     * The PHP name of this class (PascalCase)
     */
    public const TABLE_PHP_NAME = 'ContagemVolta';

    /**
     * The related Propel class for this table
     */
    public const OM_CLASS = '\\Baja\\Model\\ContagemVolta';

    /**
     * A class that can be returned by this tableMap
     */
    public const CLASS_DEFAULT = 'Baja.Model.ContagemVolta';

    /**
     * The total number of columns
     */
    public const NUM_COLUMNS = 10;

    /**
     * The number of lazy-loaded columns
     */
    public const NUM_LAZY_LOAD_COLUMNS = 0;

    /**
     * The number of columns to hydrate (NUM_COLUMNS - NUM_LAZY_LOAD_COLUMNS)
     */
    public const NUM_HYDRATE_COLUMNS = 10;

    /**
     * the column name for the evento_id field
     */
    public const COL_EVENTO_ID = 'contagem_volta.evento_id';

    /**
     * the column name for the prova_id field
     */
    public const COL_PROVA_ID = 'contagem_volta.prova_id';

    /**
     * the column name for the equipe_numero field
     */
    public const COL_EQUIPE_NUMERO = 'contagem_volta.equipe_numero';

    /**
     * the column name for the numero_volta field
     */
    public const COL_NUMERO_VOLTA = 'contagem_volta.numero_volta';

    /**
     * the column name for the ts_ms field
     */
    public const COL_TS_MS = 'contagem_volta.ts_ms';

    /**
     * the column name for the dispersao_ms field
     */
    public const COL_DISPERSAO_MS = 'contagem_volta.dispersao_ms';

    /**
     * the column name for the fontes field
     */
    public const COL_FONTES = 'contagem_volta.fontes';

    /**
     * the column name for the tempo_volta_ms field
     */
    public const COL_TEMPO_VOLTA_MS = 'contagem_volta.tempo_volta_ms';

    /**
     * the column name for the elegivel_melhor_volta field
     */
    public const COL_ELEGIVEL_MELHOR_VOLTA = 'contagem_volta.elegivel_melhor_volta';

    /**
     * the column name for the estado field
     */
    public const COL_ESTADO = 'contagem_volta.estado';

    /**
     * The default string format for model objects of the related table
     */
    public const DEFAULT_STRING_FORMAT = 'YAML';

    /**
     * holds an array of fieldnames
     *
     * first dimension keys are the type constants
     * e.g. self::$fieldNames[self::TYPE_PHPNAME][0] = 'Id'
     *
     * @var array<string, mixed>
     */
    protected static $fieldNames = [
        self::TYPE_PHPNAME       => ['EventoId', 'ProvaId', 'EquipeNumero', 'NumeroVolta', 'TsMs', 'DispersaoMs', 'Fontes', 'TempoVoltaMs', 'ElegivelMelhorVolta', 'Estado', ],
        self::TYPE_CAMELNAME     => ['eventoId', 'provaId', 'equipeNumero', 'numeroVolta', 'tsMs', 'dispersaoMs', 'fontes', 'tempoVoltaMs', 'elegivelMelhorVolta', 'estado', ],
        self::TYPE_COLNAME       => [ContagemVoltaTableMap::COL_EVENTO_ID, ContagemVoltaTableMap::COL_PROVA_ID, ContagemVoltaTableMap::COL_EQUIPE_NUMERO, ContagemVoltaTableMap::COL_NUMERO_VOLTA, ContagemVoltaTableMap::COL_TS_MS, ContagemVoltaTableMap::COL_DISPERSAO_MS, ContagemVoltaTableMap::COL_FONTES, ContagemVoltaTableMap::COL_TEMPO_VOLTA_MS, ContagemVoltaTableMap::COL_ELEGIVEL_MELHOR_VOLTA, ContagemVoltaTableMap::COL_ESTADO, ],
        self::TYPE_FIELDNAME     => ['evento_id', 'prova_id', 'equipe_numero', 'numero_volta', 'ts_ms', 'dispersao_ms', 'fontes', 'tempo_volta_ms', 'elegivel_melhor_volta', 'estado', ],
        self::TYPE_NUM           => [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, ]
    ];

    /**
     * holds an array of keys for quick access to the fieldnames array
     *
     * first dimension keys are the type constants
     * e.g. self::$fieldKeys[self::TYPE_PHPNAME]['Id'] = 0
     *
     * @var array<string, mixed>
     */
    protected static $fieldKeys = [
        self::TYPE_PHPNAME       => ['EventoId' => 0, 'ProvaId' => 1, 'EquipeNumero' => 2, 'NumeroVolta' => 3, 'TsMs' => 4, 'DispersaoMs' => 5, 'Fontes' => 6, 'TempoVoltaMs' => 7, 'ElegivelMelhorVolta' => 8, 'Estado' => 9, ],
        self::TYPE_CAMELNAME     => ['eventoId' => 0, 'provaId' => 1, 'equipeNumero' => 2, 'numeroVolta' => 3, 'tsMs' => 4, 'dispersaoMs' => 5, 'fontes' => 6, 'tempoVoltaMs' => 7, 'elegivelMelhorVolta' => 8, 'estado' => 9, ],
        self::TYPE_COLNAME       => [ContagemVoltaTableMap::COL_EVENTO_ID => 0, ContagemVoltaTableMap::COL_PROVA_ID => 1, ContagemVoltaTableMap::COL_EQUIPE_NUMERO => 2, ContagemVoltaTableMap::COL_NUMERO_VOLTA => 3, ContagemVoltaTableMap::COL_TS_MS => 4, ContagemVoltaTableMap::COL_DISPERSAO_MS => 5, ContagemVoltaTableMap::COL_FONTES => 6, ContagemVoltaTableMap::COL_TEMPO_VOLTA_MS => 7, ContagemVoltaTableMap::COL_ELEGIVEL_MELHOR_VOLTA => 8, ContagemVoltaTableMap::COL_ESTADO => 9, ],
        self::TYPE_FIELDNAME     => ['evento_id' => 0, 'prova_id' => 1, 'equipe_numero' => 2, 'numero_volta' => 3, 'ts_ms' => 4, 'dispersao_ms' => 5, 'fontes' => 6, 'tempo_volta_ms' => 7, 'elegivel_melhor_volta' => 8, 'estado' => 9, ],
        self::TYPE_NUM           => [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, ]
    ];

    /**
     * Holds a list of column names and their normalized version.
     *
     * @var array<string>
     */
    protected $normalizedColumnNameMap = [
        'EventoId' => 'EVENTO_ID',
        'ContagemVolta.EventoId' => 'EVENTO_ID',
        'eventoId' => 'EVENTO_ID',
        'contagemVolta.eventoId' => 'EVENTO_ID',
        'ContagemVoltaTableMap::COL_EVENTO_ID' => 'EVENTO_ID',
        'COL_EVENTO_ID' => 'EVENTO_ID',
        'evento_id' => 'EVENTO_ID',
        'contagem_volta.evento_id' => 'EVENTO_ID',
        'ProvaId' => 'PROVA_ID',
        'ContagemVolta.ProvaId' => 'PROVA_ID',
        'provaId' => 'PROVA_ID',
        'contagemVolta.provaId' => 'PROVA_ID',
        'ContagemVoltaTableMap::COL_PROVA_ID' => 'PROVA_ID',
        'COL_PROVA_ID' => 'PROVA_ID',
        'prova_id' => 'PROVA_ID',
        'contagem_volta.prova_id' => 'PROVA_ID',
        'EquipeNumero' => 'EQUIPE_NUMERO',
        'ContagemVolta.EquipeNumero' => 'EQUIPE_NUMERO',
        'equipeNumero' => 'EQUIPE_NUMERO',
        'contagemVolta.equipeNumero' => 'EQUIPE_NUMERO',
        'ContagemVoltaTableMap::COL_EQUIPE_NUMERO' => 'EQUIPE_NUMERO',
        'COL_EQUIPE_NUMERO' => 'EQUIPE_NUMERO',
        'equipe_numero' => 'EQUIPE_NUMERO',
        'contagem_volta.equipe_numero' => 'EQUIPE_NUMERO',
        'NumeroVolta' => 'NUMERO_VOLTA',
        'ContagemVolta.NumeroVolta' => 'NUMERO_VOLTA',
        'numeroVolta' => 'NUMERO_VOLTA',
        'contagemVolta.numeroVolta' => 'NUMERO_VOLTA',
        'ContagemVoltaTableMap::COL_NUMERO_VOLTA' => 'NUMERO_VOLTA',
        'COL_NUMERO_VOLTA' => 'NUMERO_VOLTA',
        'numero_volta' => 'NUMERO_VOLTA',
        'contagem_volta.numero_volta' => 'NUMERO_VOLTA',
        'TsMs' => 'TS_MS',
        'ContagemVolta.TsMs' => 'TS_MS',
        'tsMs' => 'TS_MS',
        'contagemVolta.tsMs' => 'TS_MS',
        'ContagemVoltaTableMap::COL_TS_MS' => 'TS_MS',
        'COL_TS_MS' => 'TS_MS',
        'ts_ms' => 'TS_MS',
        'contagem_volta.ts_ms' => 'TS_MS',
        'DispersaoMs' => 'DISPERSAO_MS',
        'ContagemVolta.DispersaoMs' => 'DISPERSAO_MS',
        'dispersaoMs' => 'DISPERSAO_MS',
        'contagemVolta.dispersaoMs' => 'DISPERSAO_MS',
        'ContagemVoltaTableMap::COL_DISPERSAO_MS' => 'DISPERSAO_MS',
        'COL_DISPERSAO_MS' => 'DISPERSAO_MS',
        'dispersao_ms' => 'DISPERSAO_MS',
        'contagem_volta.dispersao_ms' => 'DISPERSAO_MS',
        'Fontes' => 'FONTES',
        'ContagemVolta.Fontes' => 'FONTES',
        'fontes' => 'FONTES',
        'contagemVolta.fontes' => 'FONTES',
        'ContagemVoltaTableMap::COL_FONTES' => 'FONTES',
        'COL_FONTES' => 'FONTES',
        'contagem_volta.fontes' => 'FONTES',
        'TempoVoltaMs' => 'TEMPO_VOLTA_MS',
        'ContagemVolta.TempoVoltaMs' => 'TEMPO_VOLTA_MS',
        'tempoVoltaMs' => 'TEMPO_VOLTA_MS',
        'contagemVolta.tempoVoltaMs' => 'TEMPO_VOLTA_MS',
        'ContagemVoltaTableMap::COL_TEMPO_VOLTA_MS' => 'TEMPO_VOLTA_MS',
        'COL_TEMPO_VOLTA_MS' => 'TEMPO_VOLTA_MS',
        'tempo_volta_ms' => 'TEMPO_VOLTA_MS',
        'contagem_volta.tempo_volta_ms' => 'TEMPO_VOLTA_MS',
        'ElegivelMelhorVolta' => 'ELEGIVEL_MELHOR_VOLTA',
        'ContagemVolta.ElegivelMelhorVolta' => 'ELEGIVEL_MELHOR_VOLTA',
        'elegivelMelhorVolta' => 'ELEGIVEL_MELHOR_VOLTA',
        'contagemVolta.elegivelMelhorVolta' => 'ELEGIVEL_MELHOR_VOLTA',
        'ContagemVoltaTableMap::COL_ELEGIVEL_MELHOR_VOLTA' => 'ELEGIVEL_MELHOR_VOLTA',
        'COL_ELEGIVEL_MELHOR_VOLTA' => 'ELEGIVEL_MELHOR_VOLTA',
        'elegivel_melhor_volta' => 'ELEGIVEL_MELHOR_VOLTA',
        'contagem_volta.elegivel_melhor_volta' => 'ELEGIVEL_MELHOR_VOLTA',
        'Estado' => 'ESTADO',
        'ContagemVolta.Estado' => 'ESTADO',
        'estado' => 'ESTADO',
        'contagemVolta.estado' => 'ESTADO',
        'ContagemVoltaTableMap::COL_ESTADO' => 'ESTADO',
        'COL_ESTADO' => 'ESTADO',
        'contagem_volta.estado' => 'ESTADO',
    ];

    /**
     * Initialize the table attributes and columns
     * Relations are not initialized by this method since they are lazy loaded
     *
     * @return void
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function initialize(): void
    {
        // attributes
        $this->setName('contagem_volta');
        $this->setPhpName('ContagemVolta');
        $this->setIdentifierQuoting(false);
        $this->setClassName('\\Baja\\Model\\ContagemVolta');
        $this->setPackage('Baja.Model');
        $this->setUseIdGenerator(false);
        // columns
        $this->addForeignPrimaryKey('evento_id', 'EventoId', 'CHAR' , 'prova', 'evento_id', true, 4, null);
        $this->addForeignPrimaryKey('prova_id', 'ProvaId', 'CHAR' , 'prova', 'prova_id', true, 3, null);
        $this->addPrimaryKey('equipe_numero', 'EquipeNumero', 'INTEGER', true, null, null);
        $this->addPrimaryKey('numero_volta', 'NumeroVolta', 'INTEGER', true, null, null);
        $this->addColumn('ts_ms', 'TsMs', 'BIGINT', true, null, null);
        $this->addColumn('dispersao_ms', 'DispersaoMs', 'INTEGER', true, null, 0);
        $this->addColumn('fontes', 'Fontes', 'INTEGER', true, null, 0);
        $this->addColumn('tempo_volta_ms', 'TempoVoltaMs', 'BIGINT', false, null, null);
        $this->addColumn('elegivel_melhor_volta', 'ElegivelMelhorVolta', 'BOOLEAN', true, 1, false);
        $this->addColumn('estado', 'Estado', 'VARCHAR', true, 10, 'contada');
    }

    /**
     * Build the RelationMap objects for this table relationships
     *
     * @return void
     */
    public function buildRelations(): void
    {
        $this->addRelation('Prova', '\\Baja\\Model\\Prova', RelationMap::MANY_TO_ONE, array (
  0 =>
  array (
    0 => ':evento_id',
    1 => ':evento_id',
  ),
  1 =>
  array (
    0 => ':prova_id',
    1 => ':prova_id',
  ),
), 'CASCADE', 'CASCADE', null, false);
    }

    /**
     * Adds an object to the instance pool.
     *
     * Propel keeps cached copies of objects in an instance pool when they are retrieved
     * from the database. In some cases you may need to explicitly add objects
     * to the cache in order to ensure that the same objects are always returned by find*()
     * and findPk*() calls.
     *
     * @param \Baja\Model\ContagemVolta $obj A \Baja\Model\ContagemVolta object.
     * @param string|null $key Key (optional) to use for instance map (for performance boost if key was already calculated externally).
     *
     * @return void
     */
    public static function addInstanceToPool(ContagemVolta $obj, ?string $key = null): void
    {
        if (Propel::isInstancePoolingEnabled()) {
            if (null === $key) {
                $key = serialize([(null === $obj->getEventoId() || is_scalar($obj->getEventoId()) || is_callable([$obj->getEventoId(), '__toString']) ? (string) $obj->getEventoId() : $obj->getEventoId()), (null === $obj->getProvaId() || is_scalar($obj->getProvaId()) || is_callable([$obj->getProvaId(), '__toString']) ? (string) $obj->getProvaId() : $obj->getProvaId()), (null === $obj->getEquipeNumero() || is_scalar($obj->getEquipeNumero()) || is_callable([$obj->getEquipeNumero(), '__toString']) ? (string) $obj->getEquipeNumero() : $obj->getEquipeNumero()), (null === $obj->getNumeroVolta() || is_scalar($obj->getNumeroVolta()) || is_callable([$obj->getNumeroVolta(), '__toString']) ? (string) $obj->getNumeroVolta() : $obj->getNumeroVolta())]);
            } // if key === null
            self::$instances[$key] = $obj;
        }
    }

    /**
     * Removes an object from the instance pool.
     *
     * Propel keeps cached copies of objects in an instance pool when they are retrieved
     * from the database.  In some cases -- especially when you override doDelete
     * methods in your stub classes -- you may need to explicitly remove objects
     * from the cache in order to prevent returning objects that no longer exist.
     *
     * @param mixed $value A \Baja\Model\ContagemVolta object or a primary key value.
     *
     * @return void
     */
    public static function removeInstanceFromPool($value): void
    {
        if (Propel::isInstancePoolingEnabled() && null !== $value) {
            if (is_object($value) && $value instanceof \Baja\Model\ContagemVolta) {
                $key = serialize([(null === $value->getEventoId() || is_scalar($value->getEventoId()) || is_callable([$value->getEventoId(), '__toString']) ? (string) $value->getEventoId() : $value->getEventoId()), (null === $value->getProvaId() || is_scalar($value->getProvaId()) || is_callable([$value->getProvaId(), '__toString']) ? (string) $value->getProvaId() : $value->getProvaId()), (null === $value->getEquipeNumero() || is_scalar($value->getEquipeNumero()) || is_callable([$value->getEquipeNumero(), '__toString']) ? (string) $value->getEquipeNumero() : $value->getEquipeNumero()), (null === $value->getNumeroVolta() || is_scalar($value->getNumeroVolta()) || is_callable([$value->getNumeroVolta(), '__toString']) ? (string) $value->getNumeroVolta() : $value->getNumeroVolta())]);

            } elseif (is_array($value) && count($value) === 4) {
                // assume we've been passed a primary key";
                $key = serialize([(null === $value[0] || is_scalar($value[0]) || is_callable([$value[0], '__toString']) ? (string) $value[0] : $value[0]), (null === $value[1] || is_scalar($value[1]) || is_callable([$value[1], '__toString']) ? (string) $value[1] : $value[1]), (null === $value[2] || is_scalar($value[2]) || is_callable([$value[2], '__toString']) ? (string) $value[2] : $value[2]), (null === $value[3] || is_scalar($value[3]) || is_callable([$value[3], '__toString']) ? (string) $value[3] : $value[3])]);
            } elseif ($value instanceof Criteria) {
                self::$instances = [];

                return;
            } else {
                $e = new PropelException("Invalid value passed to removeInstanceFromPool().  Expected primary key or \Baja\Model\ContagemVolta object; got " . (is_object($value) ? get_class($value) . ' object.' : var_export($value, true)));
                throw $e;
            }

            unset(self::$instances[$key]);
        }
    }

    /**
     * Retrieves a string version of the primary key from the DB resultset row that can be used to uniquely identify a row in this table.
     *
     * For tables with a single-column primary key, that simple pkey value will be returned.  For tables with
     * a multi-column primary key, a serialize()d version of the primary key will be returned.
     *
     * @param array $row Resultset row.
     * @param int $offset The 0-based offset for reading from the resultset row.
     * @param string $indexType One of the class type constants TableMap::TYPE_PHPNAME, TableMap::TYPE_CAMELNAME
     *                           TableMap::TYPE_COLNAME, TableMap::TYPE_FIELDNAME, TableMap::TYPE_NUM
     *
     * @return string|null The primary key hash of the row
     */
    public static function getPrimaryKeyHashFromRow(array $row, int $offset = 0, string $indexType = TableMap::TYPE_NUM): ?string
    {
        // If the PK cannot be derived from the row, return NULL.
        if ($row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('EventoId', TableMap::TYPE_PHPNAME, $indexType)] === null && $row[TableMap::TYPE_NUM == $indexType ? 1 + $offset : static::translateFieldName('ProvaId', TableMap::TYPE_PHPNAME, $indexType)] === null && $row[TableMap::TYPE_NUM == $indexType ? 2 + $offset : static::translateFieldName('EquipeNumero', TableMap::TYPE_PHPNAME, $indexType)] === null && $row[TableMap::TYPE_NUM == $indexType ? 3 + $offset : static::translateFieldName('NumeroVolta', TableMap::TYPE_PHPNAME, $indexType)] === null) {
            return null;
        }

        return serialize([(null === $row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('EventoId', TableMap::TYPE_PHPNAME, $indexType)] || is_scalar($row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('EventoId', TableMap::TYPE_PHPNAME, $indexType)]) || is_callable([$row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('EventoId', TableMap::TYPE_PHPNAME, $indexType)], '__toString']) ? (string) $row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('EventoId', TableMap::TYPE_PHPNAME, $indexType)] : $row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('EventoId', TableMap::TYPE_PHPNAME, $indexType)]), (null === $row[TableMap::TYPE_NUM == $indexType ? 1 + $offset : static::translateFieldName('ProvaId', TableMap::TYPE_PHPNAME, $indexType)] || is_scalar($row[TableMap::TYPE_NUM == $indexType ? 1 + $offset : static::translateFieldName('ProvaId', TableMap::TYPE_PHPNAME, $indexType)]) || is_callable([$row[TableMap::TYPE_NUM == $indexType ? 1 + $offset : static::translateFieldName('ProvaId', TableMap::TYPE_PHPNAME, $indexType)], '__toString']) ? (string) $row[TableMap::TYPE_NUM == $indexType ? 1 + $offset : static::translateFieldName('ProvaId', TableMap::TYPE_PHPNAME, $indexType)] : $row[TableMap::TYPE_NUM == $indexType ? 1 + $offset : static::translateFieldName('ProvaId', TableMap::TYPE_PHPNAME, $indexType)]), (null === $row[TableMap::TYPE_NUM == $indexType ? 2 + $offset : static::translateFieldName('EquipeNumero', TableMap::TYPE_PHPNAME, $indexType)] || is_scalar($row[TableMap::TYPE_NUM == $indexType ? 2 + $offset : static::translateFieldName('EquipeNumero', TableMap::TYPE_PHPNAME, $indexType)]) || is_callable([$row[TableMap::TYPE_NUM == $indexType ? 2 + $offset : static::translateFieldName('EquipeNumero', TableMap::TYPE_PHPNAME, $indexType)], '__toString']) ? (string) $row[TableMap::TYPE_NUM == $indexType ? 2 + $offset : static::translateFieldName('EquipeNumero', TableMap::TYPE_PHPNAME, $indexType)] : $row[TableMap::TYPE_NUM == $indexType ? 2 + $offset : static::translateFieldName('EquipeNumero', TableMap::TYPE_PHPNAME, $indexType)]), (null === $row[TableMap::TYPE_NUM == $indexType ? 3 + $offset : static::translateFieldName('NumeroVolta', TableMap::TYPE_PHPNAME, $indexType)] || is_scalar($row[TableMap::TYPE_NUM == $indexType ? 3 + $offset : static::translateFieldName('NumeroVolta', TableMap::TYPE_PHPNAME, $indexType)]) || is_callable([$row[TableMap::TYPE_NUM == $indexType ? 3 + $offset : static::translateFieldName('NumeroVolta', TableMap::TYPE_PHPNAME, $indexType)], '__toString']) ? (string) $row[TableMap::TYPE_NUM == $indexType ? 3 + $offset : static::translateFieldName('NumeroVolta', TableMap::TYPE_PHPNAME, $indexType)] : $row[TableMap::TYPE_NUM == $indexType ? 3 + $offset : static::translateFieldName('NumeroVolta', TableMap::TYPE_PHPNAME, $indexType)])]);
    }

    /**
     * Retrieves the primary key from the DB resultset row
     * For tables with a single-column primary key, that simple pkey value will be returned.  For tables with
     * a multi-column primary key, an array of the primary key columns will be returned.
     *
     * @param array $row Resultset row.
     * @param int $offset The 0-based offset for reading from the resultset row.
     * @param string $indexType One of the class type constants TableMap::TYPE_PHPNAME, TableMap::TYPE_CAMELNAME
     *                           TableMap::TYPE_COLNAME, TableMap::TYPE_FIELDNAME, TableMap::TYPE_NUM
     *
     * @return mixed The primary key of the row
     */
    public static function getPrimaryKeyFromRow(array $row, int $offset = 0, string $indexType = TableMap::TYPE_NUM)
    {
            $pks = [];

        $pks[] = (string) $row[
            $indexType == TableMap::TYPE_NUM
                ? 0 + $offset
                : self::translateFieldName('EventoId', TableMap::TYPE_PHPNAME, $indexType)
        ];
        $pks[] = (string) $row[
            $indexType == TableMap::TYPE_NUM
                ? 1 + $offset
                : self::translateFieldName('ProvaId', TableMap::TYPE_PHPNAME, $indexType)
        ];
        $pks[] = (int) $row[
            $indexType == TableMap::TYPE_NUM
                ? 2 + $offset
                : self::translateFieldName('EquipeNumero', TableMap::TYPE_PHPNAME, $indexType)
        ];
        $pks[] = (int) $row[
            $indexType == TableMap::TYPE_NUM
                ? 3 + $offset
                : self::translateFieldName('NumeroVolta', TableMap::TYPE_PHPNAME, $indexType)
        ];

        return $pks;
    }

    /**
     * The class that the tableMap will make instances of.
     *
     * If $withPrefix is true, the returned path
     * uses a dot-path notation which is translated into a path
     * relative to a location on the PHP include_path.
     * (e.g. path.to.MyClass -> 'path/to/MyClass.php')
     *
     * @param bool $withPrefix Whether to return the path with the class name
     * @return string path.to.ClassName
     */
    public static function getOMClass(bool $withPrefix = true): string
    {
        return $withPrefix ? ContagemVoltaTableMap::CLASS_DEFAULT : ContagemVoltaTableMap::OM_CLASS;
    }

    /**
     * Populates an object of the default type or an object that inherit from the default.
     *
     * @param array $row Row returned by DataFetcher->fetch().
     * @param int $offset The 0-based offset for reading from the resultset row.
     * @param string $indexType The index type of $row. Mostly DataFetcher->getIndexType().
                                 One of the class type constants TableMap::TYPE_PHPNAME, TableMap::TYPE_CAMELNAME
     *                           TableMap::TYPE_COLNAME, TableMap::TYPE_FIELDNAME, TableMap::TYPE_NUM.
     *
     * @throws \Propel\Runtime\Exception\PropelException Any exceptions caught during processing will be
     *                         rethrown wrapped into a PropelException.
     * @return array (ContagemVolta object, last column rank)
     */
    public static function populateObject(array $row, int $offset = 0, string $indexType = TableMap::TYPE_NUM): array
    {
        $key = ContagemVoltaTableMap::getPrimaryKeyHashFromRow($row, $offset, $indexType);
        if (null !== ($obj = ContagemVoltaTableMap::getInstanceFromPool($key))) {
            // We no longer rehydrate the object, since this can cause data loss.
            // See http://www.propelorm.org/ticket/509
            // $obj->hydrate($row, $offset, true); // rehydrate
            $col = $offset + ContagemVoltaTableMap::NUM_HYDRATE_COLUMNS;
        } else {
            $cls = ContagemVoltaTableMap::OM_CLASS;
            /** @var ContagemVolta $obj */
            $obj = new $cls();
            $col = $obj->hydrate($row, $offset, false, $indexType);
            ContagemVoltaTableMap::addInstanceToPool($obj, $key);
        }

        return [$obj, $col];
    }

    /**
     * The returned array will contain objects of the default type or
     * objects that inherit from the default.
     *
     * @param DataFetcherInterface $dataFetcher
     * @return array<object>
     * @throws \Propel\Runtime\Exception\PropelException Any exceptions caught during processing will be
     *                         rethrown wrapped into a PropelException.
     */
    public static function populateObjects(DataFetcherInterface $dataFetcher): array
    {
        $results = [];

        // set the class once to avoid overhead in the loop
        $cls = static::getOMClass(false);
        // populate the object(s)
        while ($row = $dataFetcher->fetch()) {
            $key = ContagemVoltaTableMap::getPrimaryKeyHashFromRow($row, 0, $dataFetcher->getIndexType());
            if (null !== ($obj = ContagemVoltaTableMap::getInstanceFromPool($key))) {
                // We no longer rehydrate the object, since this can cause data loss.
                // See http://www.propelorm.org/ticket/509
                // $obj->hydrate($row, 0, true); // rehydrate
                $results[] = $obj;
            } else {
                /** @var ContagemVolta $obj */
                $obj = new $cls();
                $obj->hydrate($row);
                $results[] = $obj;
                ContagemVoltaTableMap::addInstanceToPool($obj, $key);
            } // if key exists
        }

        return $results;
    }
    /**
     * Add all the columns needed to create a new object.
     *
     * Note: any columns that were marked with lazyLoad="true" in the
     * XML schema will not be added to the select list and only loaded
     * on demand.
     *
     * @param Criteria $criteria Object containing the columns to add.
     * @param string|null $alias Optional table alias
     * @throws \Propel\Runtime\Exception\PropelException Any exceptions caught during processing will be
     *                         rethrown wrapped into a PropelException.
     * @return void
     */
    public static function addSelectColumns(Criteria $criteria, ?string $alias = null): void
    {
        if (null === $alias) {
            $criteria->addSelectColumn(ContagemVoltaTableMap::COL_EVENTO_ID);
            $criteria->addSelectColumn(ContagemVoltaTableMap::COL_PROVA_ID);
            $criteria->addSelectColumn(ContagemVoltaTableMap::COL_EQUIPE_NUMERO);
            $criteria->addSelectColumn(ContagemVoltaTableMap::COL_NUMERO_VOLTA);
            $criteria->addSelectColumn(ContagemVoltaTableMap::COL_TS_MS);
            $criteria->addSelectColumn(ContagemVoltaTableMap::COL_DISPERSAO_MS);
            $criteria->addSelectColumn(ContagemVoltaTableMap::COL_FONTES);
            $criteria->addSelectColumn(ContagemVoltaTableMap::COL_TEMPO_VOLTA_MS);
            $criteria->addSelectColumn(ContagemVoltaTableMap::COL_ELEGIVEL_MELHOR_VOLTA);
            $criteria->addSelectColumn(ContagemVoltaTableMap::COL_ESTADO);
        } else {
            $criteria->addSelectColumn($alias . '.evento_id');
            $criteria->addSelectColumn($alias . '.prova_id');
            $criteria->addSelectColumn($alias . '.equipe_numero');
            $criteria->addSelectColumn($alias . '.numero_volta');
            $criteria->addSelectColumn($alias . '.ts_ms');
            $criteria->addSelectColumn($alias . '.dispersao_ms');
            $criteria->addSelectColumn($alias . '.fontes');
            $criteria->addSelectColumn($alias . '.tempo_volta_ms');
            $criteria->addSelectColumn($alias . '.elegivel_melhor_volta');
            $criteria->addSelectColumn($alias . '.estado');
        }
    }

    /**
     * Remove all the columns needed to create a new object.
     *
     * Note: any columns that were marked with lazyLoad="true" in the
     * XML schema will not be removed as they are only loaded on demand.
     *
     * @param Criteria $criteria Object containing the columns to remove.
     * @param string|null $alias Optional table alias
     * @throws \Propel\Runtime\Exception\PropelException Any exceptions caught during processing will be
     *                         rethrown wrapped into a PropelException.
     * @return void
     */
    public static function removeSelectColumns(Criteria $criteria, ?string $alias = null): void
    {
        if (null === $alias) {
            $criteria->removeSelectColumn(ContagemVoltaTableMap::COL_EVENTO_ID);
            $criteria->removeSelectColumn(ContagemVoltaTableMap::COL_PROVA_ID);
            $criteria->removeSelectColumn(ContagemVoltaTableMap::COL_EQUIPE_NUMERO);
            $criteria->removeSelectColumn(ContagemVoltaTableMap::COL_NUMERO_VOLTA);
            $criteria->removeSelectColumn(ContagemVoltaTableMap::COL_TS_MS);
            $criteria->removeSelectColumn(ContagemVoltaTableMap::COL_DISPERSAO_MS);
            $criteria->removeSelectColumn(ContagemVoltaTableMap::COL_FONTES);
            $criteria->removeSelectColumn(ContagemVoltaTableMap::COL_TEMPO_VOLTA_MS);
            $criteria->removeSelectColumn(ContagemVoltaTableMap::COL_ELEGIVEL_MELHOR_VOLTA);
            $criteria->removeSelectColumn(ContagemVoltaTableMap::COL_ESTADO);
        } else {
            $criteria->removeSelectColumn($alias . '.evento_id');
            $criteria->removeSelectColumn($alias . '.prova_id');
            $criteria->removeSelectColumn($alias . '.equipe_numero');
            $criteria->removeSelectColumn($alias . '.numero_volta');
            $criteria->removeSelectColumn($alias . '.ts_ms');
            $criteria->removeSelectColumn($alias . '.dispersao_ms');
            $criteria->removeSelectColumn($alias . '.fontes');
            $criteria->removeSelectColumn($alias . '.tempo_volta_ms');
            $criteria->removeSelectColumn($alias . '.elegivel_melhor_volta');
            $criteria->removeSelectColumn($alias . '.estado');
        }
    }

    /**
     * Returns the TableMap related to this object.
     * This method is not needed for general use but a specific application could have a need.
     * @return TableMap
     * @throws \Propel\Runtime\Exception\PropelException Any exceptions caught during processing will be
     *                         rethrown wrapped into a PropelException.
     */
    public static function getTableMap(): TableMap
    {
        return Propel::getServiceContainer()->getDatabaseMap(ContagemVoltaTableMap::DATABASE_NAME)->getTable(ContagemVoltaTableMap::TABLE_NAME);
    }

    /**
     * Performs a DELETE on the database, given a ContagemVolta or Criteria object OR a primary key value.
     *
     * @param mixed $values Criteria or ContagemVolta object or primary key or array of primary keys
     *              which is used to create the DELETE statement
     * @param ConnectionInterface $con the connection to use
     * @return int The number of affected rows (if supported by underlying database driver).  This includes CASCADE-related rows
     *                         if supported by native driver or if emulated using Propel.
     * @throws \Propel\Runtime\Exception\PropelException Any exceptions caught during processing will be
     *                         rethrown wrapped into a PropelException.
     */
     public static function doDelete($values, ?ConnectionInterface $con = null): int
     {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(ContagemVoltaTableMap::DATABASE_NAME);
        }

        if ($values instanceof Criteria) {
            // rename for clarity
            $criteria = $values;
        } elseif ($values instanceof \Baja\Model\ContagemVolta) { // it's a model object
            // create criteria based on pk values
            $criteria = $values->buildPkeyCriteria();
        } else { // it's a primary key, or an array of pks
            $criteria = new Criteria(ContagemVoltaTableMap::DATABASE_NAME);
            // primary key is composite; we therefore, expect
            // the primary key passed to be an array of pkey values
            if (count($values) == count($values, COUNT_RECURSIVE)) {
                // array is not multi-dimensional
                $values = [$values];
            }
            foreach ($values as $value) {
                $criterion = $criteria->getNewCriterion(ContagemVoltaTableMap::COL_EVENTO_ID, $value[0]);
                $criterion->addAnd($criteria->getNewCriterion(ContagemVoltaTableMap::COL_PROVA_ID, $value[1]));
                $criterion->addAnd($criteria->getNewCriterion(ContagemVoltaTableMap::COL_EQUIPE_NUMERO, $value[2]));
                $criterion->addAnd($criteria->getNewCriterion(ContagemVoltaTableMap::COL_NUMERO_VOLTA, $value[3]));
                $criteria->addOr($criterion);
            }
        }

        $query = ContagemVoltaQuery::create()->mergeWith($criteria);

        if ($values instanceof Criteria) {
            ContagemVoltaTableMap::clearInstancePool();
        } elseif (!is_object($values)) { // it's a primary key, or an array of pks
            foreach ((array) $values as $singleval) {
                ContagemVoltaTableMap::removeInstanceFromPool($singleval);
            }
        }

        return $query->delete($con);
    }

    /**
     * Deletes all rows from the contagem_volta table.
     *
     * @param ConnectionInterface $con the connection to use
     * @return int The number of affected rows (if supported by underlying database driver).
     */
    public static function doDeleteAll(?ConnectionInterface $con = null): int
    {
        return ContagemVoltaQuery::create()->doDeleteAll($con);
    }

    /**
     * Performs an INSERT on the database, given a ContagemVolta or Criteria object.
     *
     * @param mixed $criteria Criteria or ContagemVolta object containing data that is used to create the INSERT statement.
     * @param ConnectionInterface $con the ConnectionInterface connection to use
     * @return mixed The new primary key.
     * @throws \Propel\Runtime\Exception\PropelException Any exceptions caught during processing will be
     *                         rethrown wrapped into a PropelException.
     */
    public static function doInsert($criteria, ?ConnectionInterface $con = null)
    {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(ContagemVoltaTableMap::DATABASE_NAME);
        }

        if ($criteria instanceof Criteria) {
            $criteria = clone $criteria; // rename for clarity
        } else {
            $criteria = $criteria->buildCriteria(); // build Criteria from ContagemVolta object
        }


        // Set the correct dbName
        $query = ContagemVoltaQuery::create()->mergeWith($criteria);

        // use transaction because $criteria could contain info
        // for more than one table (I guess, conceivably)
        return $con->transaction(function () use ($con, $query) {
            return $query->doInsert($con);
        });
    }

}
