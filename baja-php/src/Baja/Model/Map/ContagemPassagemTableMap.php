<?php

namespace Baja\Model\Map;

use Baja\Model\ContagemPassagem;
use Baja\Model\ContagemPassagemQuery;
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
 * This class defines the structure of the 'contagem_passagem' table.
 *
 *
 *
 * This map class is used by Propel to do runtime db structure discovery.
 * For example, the createSelectSql() method checks the type of a given column used in an
 * ORDER BY clause to know whether it needs to apply SQL to make the ORDER BY case-insensitive
 * (i.e. if it's a text column type).
 */
class ContagemPassagemTableMap extends TableMap
{
    use InstancePoolTrait;
    use TableMapTrait;

    /**
     * The (dot-path) name of this class
     */
    public const CLASS_NAME = 'Baja.Model.Map.ContagemPassagemTableMap';

    /**
     * The default database name for this class
     */
    public const DATABASE_NAME = 'resultados';

    /**
     * The table name for this class
     */
    public const TABLE_NAME = 'contagem_passagem';

    /**
     * The PHP name of this class (PascalCase)
     */
    public const TABLE_PHP_NAME = 'ContagemPassagem';

    /**
     * The related Propel class for this table
     */
    public const OM_CLASS = '\\Baja\\Model\\ContagemPassagem';

    /**
     * A class that can be returned by this tableMap
     */
    public const CLASS_DEFAULT = 'Baja.Model.ContagemPassagem';

    /**
     * The total number of columns
     */
    public const NUM_COLUMNS = 11;

    /**
     * The number of lazy-loaded columns
     */
    public const NUM_LAZY_LOAD_COLUMNS = 0;

    /**
     * The number of columns to hydrate (NUM_COLUMNS - NUM_LAZY_LOAD_COLUMNS)
     */
    public const NUM_HYDRATE_COLUMNS = 11;

    /**
     * the column name for the passagem_id field
     */
    public const COL_PASSAGEM_ID = 'contagem_passagem.passagem_id';

    /**
     * the column name for the fonte_id field
     */
    public const COL_FONTE_ID = 'contagem_passagem.fonte_id';

    /**
     * the column name for the dispositivo_id field
     */
    public const COL_DISPOSITIVO_ID = 'contagem_passagem.dispositivo_id';

    /**
     * the column name for the uuid_cliente field
     */
    public const COL_UUID_CLIENTE = 'contagem_passagem.uuid_cliente';

    /**
     * the column name for the equipe_numero field
     */
    public const COL_EQUIPE_NUMERO = 'contagem_passagem.equipe_numero';

    /**
     * the column name for the ts_dispositivo_ms field
     */
    public const COL_TS_DISPOSITIVO_MS = 'contagem_passagem.ts_dispositivo_ms';

    /**
     * the column name for the desvio_relogio_ms field
     */
    public const COL_DESVIO_RELOGIO_MS = 'contagem_passagem.desvio_relogio_ms';

    /**
     * the column name for the ts_ms field
     */
    public const COL_TS_MS = 'contagem_passagem.ts_ms';

    /**
     * the column name for the digitacao_ms field
     */
    public const COL_DIGITACAO_MS = 'contagem_passagem.digitacao_ms';

    /**
     * the column name for the modo_entrada field
     */
    public const COL_MODO_ENTRADA = 'contagem_passagem.modo_entrada';

    /**
     * the column name for the recebido_em_ms field
     */
    public const COL_RECEBIDO_EM_MS = 'contagem_passagem.recebido_em_ms';

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
        self::TYPE_PHPNAME       => ['PassagemId', 'FonteId', 'DispositivoId', 'UuidCliente', 'EquipeNumero', 'TsDispositivoMs', 'DesvioRelogioMs', 'TsMs', 'DigitacaoMs', 'ModoEntrada', 'RecebidoEmMs', ],
        self::TYPE_CAMELNAME     => ['passagemId', 'fonteId', 'dispositivoId', 'uuidCliente', 'equipeNumero', 'tsDispositivoMs', 'desvioRelogioMs', 'tsMs', 'digitacaoMs', 'modoEntrada', 'recebidoEmMs', ],
        self::TYPE_COLNAME       => [ContagemPassagemTableMap::COL_PASSAGEM_ID, ContagemPassagemTableMap::COL_FONTE_ID, ContagemPassagemTableMap::COL_DISPOSITIVO_ID, ContagemPassagemTableMap::COL_UUID_CLIENTE, ContagemPassagemTableMap::COL_EQUIPE_NUMERO, ContagemPassagemTableMap::COL_TS_DISPOSITIVO_MS, ContagemPassagemTableMap::COL_DESVIO_RELOGIO_MS, ContagemPassagemTableMap::COL_TS_MS, ContagemPassagemTableMap::COL_DIGITACAO_MS, ContagemPassagemTableMap::COL_MODO_ENTRADA, ContagemPassagemTableMap::COL_RECEBIDO_EM_MS, ],
        self::TYPE_FIELDNAME     => ['passagem_id', 'fonte_id', 'dispositivo_id', 'uuid_cliente', 'equipe_numero', 'ts_dispositivo_ms', 'desvio_relogio_ms', 'ts_ms', 'digitacao_ms', 'modo_entrada', 'recebido_em_ms', ],
        self::TYPE_NUM           => [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, ]
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
        self::TYPE_PHPNAME       => ['PassagemId' => 0, 'FonteId' => 1, 'DispositivoId' => 2, 'UuidCliente' => 3, 'EquipeNumero' => 4, 'TsDispositivoMs' => 5, 'DesvioRelogioMs' => 6, 'TsMs' => 7, 'DigitacaoMs' => 8, 'ModoEntrada' => 9, 'RecebidoEmMs' => 10, ],
        self::TYPE_CAMELNAME     => ['passagemId' => 0, 'fonteId' => 1, 'dispositivoId' => 2, 'uuidCliente' => 3, 'equipeNumero' => 4, 'tsDispositivoMs' => 5, 'desvioRelogioMs' => 6, 'tsMs' => 7, 'digitacaoMs' => 8, 'modoEntrada' => 9, 'recebidoEmMs' => 10, ],
        self::TYPE_COLNAME       => [ContagemPassagemTableMap::COL_PASSAGEM_ID => 0, ContagemPassagemTableMap::COL_FONTE_ID => 1, ContagemPassagemTableMap::COL_DISPOSITIVO_ID => 2, ContagemPassagemTableMap::COL_UUID_CLIENTE => 3, ContagemPassagemTableMap::COL_EQUIPE_NUMERO => 4, ContagemPassagemTableMap::COL_TS_DISPOSITIVO_MS => 5, ContagemPassagemTableMap::COL_DESVIO_RELOGIO_MS => 6, ContagemPassagemTableMap::COL_TS_MS => 7, ContagemPassagemTableMap::COL_DIGITACAO_MS => 8, ContagemPassagemTableMap::COL_MODO_ENTRADA => 9, ContagemPassagemTableMap::COL_RECEBIDO_EM_MS => 10, ],
        self::TYPE_FIELDNAME     => ['passagem_id' => 0, 'fonte_id' => 1, 'dispositivo_id' => 2, 'uuid_cliente' => 3, 'equipe_numero' => 4, 'ts_dispositivo_ms' => 5, 'desvio_relogio_ms' => 6, 'ts_ms' => 7, 'digitacao_ms' => 8, 'modo_entrada' => 9, 'recebido_em_ms' => 10, ],
        self::TYPE_NUM           => [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, ]
    ];

    /**
     * Holds a list of column names and their normalized version.
     *
     * @var array<string>
     */
    protected $normalizedColumnNameMap = [
        'PassagemId' => 'PASSAGEM_ID',
        'ContagemPassagem.PassagemId' => 'PASSAGEM_ID',
        'passagemId' => 'PASSAGEM_ID',
        'contagemPassagem.passagemId' => 'PASSAGEM_ID',
        'ContagemPassagemTableMap::COL_PASSAGEM_ID' => 'PASSAGEM_ID',
        'COL_PASSAGEM_ID' => 'PASSAGEM_ID',
        'passagem_id' => 'PASSAGEM_ID',
        'contagem_passagem.passagem_id' => 'PASSAGEM_ID',
        'FonteId' => 'FONTE_ID',
        'ContagemPassagem.FonteId' => 'FONTE_ID',
        'fonteId' => 'FONTE_ID',
        'contagemPassagem.fonteId' => 'FONTE_ID',
        'ContagemPassagemTableMap::COL_FONTE_ID' => 'FONTE_ID',
        'COL_FONTE_ID' => 'FONTE_ID',
        'fonte_id' => 'FONTE_ID',
        'contagem_passagem.fonte_id' => 'FONTE_ID',
        'DispositivoId' => 'DISPOSITIVO_ID',
        'ContagemPassagem.DispositivoId' => 'DISPOSITIVO_ID',
        'dispositivoId' => 'DISPOSITIVO_ID',
        'contagemPassagem.dispositivoId' => 'DISPOSITIVO_ID',
        'ContagemPassagemTableMap::COL_DISPOSITIVO_ID' => 'DISPOSITIVO_ID',
        'COL_DISPOSITIVO_ID' => 'DISPOSITIVO_ID',
        'dispositivo_id' => 'DISPOSITIVO_ID',
        'contagem_passagem.dispositivo_id' => 'DISPOSITIVO_ID',
        'UuidCliente' => 'UUID_CLIENTE',
        'ContagemPassagem.UuidCliente' => 'UUID_CLIENTE',
        'uuidCliente' => 'UUID_CLIENTE',
        'contagemPassagem.uuidCliente' => 'UUID_CLIENTE',
        'ContagemPassagemTableMap::COL_UUID_CLIENTE' => 'UUID_CLIENTE',
        'COL_UUID_CLIENTE' => 'UUID_CLIENTE',
        'uuid_cliente' => 'UUID_CLIENTE',
        'contagem_passagem.uuid_cliente' => 'UUID_CLIENTE',
        'EquipeNumero' => 'EQUIPE_NUMERO',
        'ContagemPassagem.EquipeNumero' => 'EQUIPE_NUMERO',
        'equipeNumero' => 'EQUIPE_NUMERO',
        'contagemPassagem.equipeNumero' => 'EQUIPE_NUMERO',
        'ContagemPassagemTableMap::COL_EQUIPE_NUMERO' => 'EQUIPE_NUMERO',
        'COL_EQUIPE_NUMERO' => 'EQUIPE_NUMERO',
        'equipe_numero' => 'EQUIPE_NUMERO',
        'contagem_passagem.equipe_numero' => 'EQUIPE_NUMERO',
        'TsDispositivoMs' => 'TS_DISPOSITIVO_MS',
        'ContagemPassagem.TsDispositivoMs' => 'TS_DISPOSITIVO_MS',
        'tsDispositivoMs' => 'TS_DISPOSITIVO_MS',
        'contagemPassagem.tsDispositivoMs' => 'TS_DISPOSITIVO_MS',
        'ContagemPassagemTableMap::COL_TS_DISPOSITIVO_MS' => 'TS_DISPOSITIVO_MS',
        'COL_TS_DISPOSITIVO_MS' => 'TS_DISPOSITIVO_MS',
        'ts_dispositivo_ms' => 'TS_DISPOSITIVO_MS',
        'contagem_passagem.ts_dispositivo_ms' => 'TS_DISPOSITIVO_MS',
        'DesvioRelogioMs' => 'DESVIO_RELOGIO_MS',
        'ContagemPassagem.DesvioRelogioMs' => 'DESVIO_RELOGIO_MS',
        'desvioRelogioMs' => 'DESVIO_RELOGIO_MS',
        'contagemPassagem.desvioRelogioMs' => 'DESVIO_RELOGIO_MS',
        'ContagemPassagemTableMap::COL_DESVIO_RELOGIO_MS' => 'DESVIO_RELOGIO_MS',
        'COL_DESVIO_RELOGIO_MS' => 'DESVIO_RELOGIO_MS',
        'desvio_relogio_ms' => 'DESVIO_RELOGIO_MS',
        'contagem_passagem.desvio_relogio_ms' => 'DESVIO_RELOGIO_MS',
        'TsMs' => 'TS_MS',
        'ContagemPassagem.TsMs' => 'TS_MS',
        'tsMs' => 'TS_MS',
        'contagemPassagem.tsMs' => 'TS_MS',
        'ContagemPassagemTableMap::COL_TS_MS' => 'TS_MS',
        'COL_TS_MS' => 'TS_MS',
        'ts_ms' => 'TS_MS',
        'contagem_passagem.ts_ms' => 'TS_MS',
        'DigitacaoMs' => 'DIGITACAO_MS',
        'ContagemPassagem.DigitacaoMs' => 'DIGITACAO_MS',
        'digitacaoMs' => 'DIGITACAO_MS',
        'contagemPassagem.digitacaoMs' => 'DIGITACAO_MS',
        'ContagemPassagemTableMap::COL_DIGITACAO_MS' => 'DIGITACAO_MS',
        'COL_DIGITACAO_MS' => 'DIGITACAO_MS',
        'digitacao_ms' => 'DIGITACAO_MS',
        'contagem_passagem.digitacao_ms' => 'DIGITACAO_MS',
        'ModoEntrada' => 'MODO_ENTRADA',
        'ContagemPassagem.ModoEntrada' => 'MODO_ENTRADA',
        'modoEntrada' => 'MODO_ENTRADA',
        'contagemPassagem.modoEntrada' => 'MODO_ENTRADA',
        'ContagemPassagemTableMap::COL_MODO_ENTRADA' => 'MODO_ENTRADA',
        'COL_MODO_ENTRADA' => 'MODO_ENTRADA',
        'modo_entrada' => 'MODO_ENTRADA',
        'contagem_passagem.modo_entrada' => 'MODO_ENTRADA',
        'RecebidoEmMs' => 'RECEBIDO_EM_MS',
        'ContagemPassagem.RecebidoEmMs' => 'RECEBIDO_EM_MS',
        'recebidoEmMs' => 'RECEBIDO_EM_MS',
        'contagemPassagem.recebidoEmMs' => 'RECEBIDO_EM_MS',
        'ContagemPassagemTableMap::COL_RECEBIDO_EM_MS' => 'RECEBIDO_EM_MS',
        'COL_RECEBIDO_EM_MS' => 'RECEBIDO_EM_MS',
        'recebido_em_ms' => 'RECEBIDO_EM_MS',
        'contagem_passagem.recebido_em_ms' => 'RECEBIDO_EM_MS',
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
        $this->setName('contagem_passagem');
        $this->setPhpName('ContagemPassagem');
        $this->setIdentifierQuoting(false);
        $this->setClassName('\\Baja\\Model\\ContagemPassagem');
        $this->setPackage('Baja.Model');
        $this->setUseIdGenerator(true);
        // columns
        $this->addPrimaryKey('passagem_id', 'PassagemId', 'BIGINT', true, null, null);
        $this->addForeignKey('fonte_id', 'FonteId', 'INTEGER', 'contagem_fonte', 'fonte_id', true, null, null);
        $this->addForeignKey('dispositivo_id', 'DispositivoId', 'INTEGER', 'contagem_dispositivo', 'dispositivo_id', true, null, null);
        $this->addColumn('uuid_cliente', 'UuidCliente', 'CHAR', true, 36, null);
        $this->addColumn('equipe_numero', 'EquipeNumero', 'INTEGER', true, null, null);
        $this->addColumn('ts_dispositivo_ms', 'TsDispositivoMs', 'BIGINT', true, null, null);
        $this->addColumn('desvio_relogio_ms', 'DesvioRelogioMs', 'BIGINT', false, null, null);
        $this->addColumn('ts_ms', 'TsMs', 'BIGINT', false, null, null);
        $this->addColumn('digitacao_ms', 'DigitacaoMs', 'INTEGER', false, null, null);
        $this->addColumn('modo_entrada', 'ModoEntrada', 'VARCHAR', true, 12, null);
        $this->addColumn('recebido_em_ms', 'RecebidoEmMs', 'BIGINT', true, null, null);
    }

    /**
     * Build the RelationMap objects for this table relationships
     *
     * @return void
     */
    public function buildRelations(): void
    {
        $this->addRelation('ContagemFonte', '\\Baja\\Model\\ContagemFonte', RelationMap::MANY_TO_ONE, array (
  0 =>
  array (
    0 => ':fonte_id',
    1 => ':fonte_id',
  ),
), 'CASCADE', 'CASCADE', null, false);
        $this->addRelation('ContagemDispositivo', '\\Baja\\Model\\ContagemDispositivo', RelationMap::MANY_TO_ONE, array (
  0 =>
  array (
    0 => ':dispositivo_id',
    1 => ':dispositivo_id',
  ),
), 'CASCADE', 'CASCADE', null, false);
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
        if ($row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('PassagemId', TableMap::TYPE_PHPNAME, $indexType)] === null) {
            return null;
        }

        return null === $row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('PassagemId', TableMap::TYPE_PHPNAME, $indexType)] || is_scalar($row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('PassagemId', TableMap::TYPE_PHPNAME, $indexType)]) || is_callable([$row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('PassagemId', TableMap::TYPE_PHPNAME, $indexType)], '__toString']) ? (string) $row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('PassagemId', TableMap::TYPE_PHPNAME, $indexType)] : $row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('PassagemId', TableMap::TYPE_PHPNAME, $indexType)];
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
        return (string) $row[
            $indexType == TableMap::TYPE_NUM
                ? 0 + $offset
                : self::translateFieldName('PassagemId', TableMap::TYPE_PHPNAME, $indexType)
        ];
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
        return $withPrefix ? ContagemPassagemTableMap::CLASS_DEFAULT : ContagemPassagemTableMap::OM_CLASS;
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
     * @return array (ContagemPassagem object, last column rank)
     */
    public static function populateObject(array $row, int $offset = 0, string $indexType = TableMap::TYPE_NUM): array
    {
        $key = ContagemPassagemTableMap::getPrimaryKeyHashFromRow($row, $offset, $indexType);
        if (null !== ($obj = ContagemPassagemTableMap::getInstanceFromPool($key))) {
            // We no longer rehydrate the object, since this can cause data loss.
            // See http://www.propelorm.org/ticket/509
            // $obj->hydrate($row, $offset, true); // rehydrate
            $col = $offset + ContagemPassagemTableMap::NUM_HYDRATE_COLUMNS;
        } else {
            $cls = ContagemPassagemTableMap::OM_CLASS;
            /** @var ContagemPassagem $obj */
            $obj = new $cls();
            $col = $obj->hydrate($row, $offset, false, $indexType);
            ContagemPassagemTableMap::addInstanceToPool($obj, $key);
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
            $key = ContagemPassagemTableMap::getPrimaryKeyHashFromRow($row, 0, $dataFetcher->getIndexType());
            if (null !== ($obj = ContagemPassagemTableMap::getInstanceFromPool($key))) {
                // We no longer rehydrate the object, since this can cause data loss.
                // See http://www.propelorm.org/ticket/509
                // $obj->hydrate($row, 0, true); // rehydrate
                $results[] = $obj;
            } else {
                /** @var ContagemPassagem $obj */
                $obj = new $cls();
                $obj->hydrate($row);
                $results[] = $obj;
                ContagemPassagemTableMap::addInstanceToPool($obj, $key);
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
            $criteria->addSelectColumn(ContagemPassagemTableMap::COL_PASSAGEM_ID);
            $criteria->addSelectColumn(ContagemPassagemTableMap::COL_FONTE_ID);
            $criteria->addSelectColumn(ContagemPassagemTableMap::COL_DISPOSITIVO_ID);
            $criteria->addSelectColumn(ContagemPassagemTableMap::COL_UUID_CLIENTE);
            $criteria->addSelectColumn(ContagemPassagemTableMap::COL_EQUIPE_NUMERO);
            $criteria->addSelectColumn(ContagemPassagemTableMap::COL_TS_DISPOSITIVO_MS);
            $criteria->addSelectColumn(ContagemPassagemTableMap::COL_DESVIO_RELOGIO_MS);
            $criteria->addSelectColumn(ContagemPassagemTableMap::COL_TS_MS);
            $criteria->addSelectColumn(ContagemPassagemTableMap::COL_DIGITACAO_MS);
            $criteria->addSelectColumn(ContagemPassagemTableMap::COL_MODO_ENTRADA);
            $criteria->addSelectColumn(ContagemPassagemTableMap::COL_RECEBIDO_EM_MS);
        } else {
            $criteria->addSelectColumn($alias . '.passagem_id');
            $criteria->addSelectColumn($alias . '.fonte_id');
            $criteria->addSelectColumn($alias . '.dispositivo_id');
            $criteria->addSelectColumn($alias . '.uuid_cliente');
            $criteria->addSelectColumn($alias . '.equipe_numero');
            $criteria->addSelectColumn($alias . '.ts_dispositivo_ms');
            $criteria->addSelectColumn($alias . '.desvio_relogio_ms');
            $criteria->addSelectColumn($alias . '.ts_ms');
            $criteria->addSelectColumn($alias . '.digitacao_ms');
            $criteria->addSelectColumn($alias . '.modo_entrada');
            $criteria->addSelectColumn($alias . '.recebido_em_ms');
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
            $criteria->removeSelectColumn(ContagemPassagemTableMap::COL_PASSAGEM_ID);
            $criteria->removeSelectColumn(ContagemPassagemTableMap::COL_FONTE_ID);
            $criteria->removeSelectColumn(ContagemPassagemTableMap::COL_DISPOSITIVO_ID);
            $criteria->removeSelectColumn(ContagemPassagemTableMap::COL_UUID_CLIENTE);
            $criteria->removeSelectColumn(ContagemPassagemTableMap::COL_EQUIPE_NUMERO);
            $criteria->removeSelectColumn(ContagemPassagemTableMap::COL_TS_DISPOSITIVO_MS);
            $criteria->removeSelectColumn(ContagemPassagemTableMap::COL_DESVIO_RELOGIO_MS);
            $criteria->removeSelectColumn(ContagemPassagemTableMap::COL_TS_MS);
            $criteria->removeSelectColumn(ContagemPassagemTableMap::COL_DIGITACAO_MS);
            $criteria->removeSelectColumn(ContagemPassagemTableMap::COL_MODO_ENTRADA);
            $criteria->removeSelectColumn(ContagemPassagemTableMap::COL_RECEBIDO_EM_MS);
        } else {
            $criteria->removeSelectColumn($alias . '.passagem_id');
            $criteria->removeSelectColumn($alias . '.fonte_id');
            $criteria->removeSelectColumn($alias . '.dispositivo_id');
            $criteria->removeSelectColumn($alias . '.uuid_cliente');
            $criteria->removeSelectColumn($alias . '.equipe_numero');
            $criteria->removeSelectColumn($alias . '.ts_dispositivo_ms');
            $criteria->removeSelectColumn($alias . '.desvio_relogio_ms');
            $criteria->removeSelectColumn($alias . '.ts_ms');
            $criteria->removeSelectColumn($alias . '.digitacao_ms');
            $criteria->removeSelectColumn($alias . '.modo_entrada');
            $criteria->removeSelectColumn($alias . '.recebido_em_ms');
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
        return Propel::getServiceContainer()->getDatabaseMap(ContagemPassagemTableMap::DATABASE_NAME)->getTable(ContagemPassagemTableMap::TABLE_NAME);
    }

    /**
     * Performs a DELETE on the database, given a ContagemPassagem or Criteria object OR a primary key value.
     *
     * @param mixed $values Criteria or ContagemPassagem object or primary key or array of primary keys
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
            $con = Propel::getServiceContainer()->getWriteConnection(ContagemPassagemTableMap::DATABASE_NAME);
        }

        if ($values instanceof Criteria) {
            // rename for clarity
            $criteria = $values;
        } elseif ($values instanceof \Baja\Model\ContagemPassagem) { // it's a model object
            // create criteria based on pk values
            $criteria = $values->buildPkeyCriteria();
        } else { // it's a primary key, or an array of pks
            $criteria = new Criteria(ContagemPassagemTableMap::DATABASE_NAME);
            $criteria->add(ContagemPassagemTableMap::COL_PASSAGEM_ID, (array) $values, Criteria::IN);
        }

        $query = ContagemPassagemQuery::create()->mergeWith($criteria);

        if ($values instanceof Criteria) {
            ContagemPassagemTableMap::clearInstancePool();
        } elseif (!is_object($values)) { // it's a primary key, or an array of pks
            foreach ((array) $values as $singleval) {
                ContagemPassagemTableMap::removeInstanceFromPool($singleval);
            }
        }

        return $query->delete($con);
    }

    /**
     * Deletes all rows from the contagem_passagem table.
     *
     * @param ConnectionInterface $con the connection to use
     * @return int The number of affected rows (if supported by underlying database driver).
     */
    public static function doDeleteAll(?ConnectionInterface $con = null): int
    {
        return ContagemPassagemQuery::create()->doDeleteAll($con);
    }

    /**
     * Performs an INSERT on the database, given a ContagemPassagem or Criteria object.
     *
     * @param mixed $criteria Criteria or ContagemPassagem object containing data that is used to create the INSERT statement.
     * @param ConnectionInterface $con the ConnectionInterface connection to use
     * @return mixed The new primary key.
     * @throws \Propel\Runtime\Exception\PropelException Any exceptions caught during processing will be
     *                         rethrown wrapped into a PropelException.
     */
    public static function doInsert($criteria, ?ConnectionInterface $con = null)
    {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(ContagemPassagemTableMap::DATABASE_NAME);
        }

        if ($criteria instanceof Criteria) {
            $criteria = clone $criteria; // rename for clarity
        } else {
            $criteria = $criteria->buildCriteria(); // build Criteria from ContagemPassagem object
        }

        if ($criteria->containsKey(ContagemPassagemTableMap::COL_PASSAGEM_ID) && $criteria->keyContainsValue(ContagemPassagemTableMap::COL_PASSAGEM_ID) ) {
            throw new PropelException('Cannot insert a value for auto-increment primary key ('.ContagemPassagemTableMap::COL_PASSAGEM_ID.')');
        }


        // Set the correct dbName
        $query = ContagemPassagemQuery::create()->mergeWith($criteria);

        // use transaction because $criteria could contain info
        // for more than one table (I guess, conceivably)
        return $con->transaction(function () use ($con, $query) {
            return $query->doInsert($con);
        });
    }

}
