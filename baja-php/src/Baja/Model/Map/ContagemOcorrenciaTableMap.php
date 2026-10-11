<?php

namespace Baja\Model\Map;

use Baja\Model\ContagemOcorrencia;
use Baja\Model\ContagemOcorrenciaQuery;
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
 * This class defines the structure of the 'contagem_ocorrencia' table.
 *
 *
 *
 * This map class is used by Propel to do runtime db structure discovery.
 * For example, the createSelectSql() method checks the type of a given column used in an
 * ORDER BY clause to know whether it needs to apply SQL to make the ORDER BY case-insensitive
 * (i.e. if it's a text column type).
 */
class ContagemOcorrenciaTableMap extends TableMap
{
    use InstancePoolTrait;
    use TableMapTrait;

    /**
     * The (dot-path) name of this class
     */
    public const CLASS_NAME = 'Baja.Model.Map.ContagemOcorrenciaTableMap';

    /**
     * The default database name for this class
     */
    public const DATABASE_NAME = 'resultados';

    /**
     * The table name for this class
     */
    public const TABLE_NAME = 'contagem_ocorrencia';

    /**
     * The PHP name of this class (PascalCase)
     */
    public const TABLE_PHP_NAME = 'ContagemOcorrencia';

    /**
     * The related Propel class for this table
     */
    public const OM_CLASS = '\\Baja\\Model\\ContagemOcorrencia';

    /**
     * A class that can be returned by this tableMap
     */
    public const CLASS_DEFAULT = 'Baja.Model.ContagemOcorrencia';

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
     * the column name for the ocorrencia_id field
     */
    public const COL_OCORRENCIA_ID = 'contagem_ocorrencia.ocorrencia_id';

    /**
     * the column name for the evento_id field
     */
    public const COL_EVENTO_ID = 'contagem_ocorrencia.evento_id';

    /**
     * the column name for the prova_id field
     */
    public const COL_PROVA_ID = 'contagem_ocorrencia.prova_id';

    /**
     * the column name for the tipo field
     */
    public const COL_TIPO = 'contagem_ocorrencia.tipo';

    /**
     * the column name for the equipe_numero field
     */
    public const COL_EQUIPE_NUMERO = 'contagem_ocorrencia.equipe_numero';

    /**
     * the column name for the ts_ms field
     */
    public const COL_TS_MS = 'contagem_ocorrencia.ts_ms';

    /**
     * the column name for the usuario field
     */
    public const COL_USUARIO = 'contagem_ocorrencia.usuario';

    /**
     * the column name for the criado_em_ms field
     */
    public const COL_CRIADO_EM_MS = 'contagem_ocorrencia.criado_em_ms';

    /**
     * the column name for the substitui_id field
     */
    public const COL_SUBSTITUI_ID = 'contagem_ocorrencia.substitui_id';

    /**
     * the column name for the uuid_cliente field
     */
    public const COL_UUID_CLIENTE = 'contagem_ocorrencia.uuid_cliente';

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
        self::TYPE_PHPNAME       => ['OcorrenciaId', 'EventoId', 'ProvaId', 'Tipo', 'EquipeNumero', 'TsMs', 'Usuario', 'CriadoEmMs', 'SubstituiId', 'UuidCliente', ],
        self::TYPE_CAMELNAME     => ['ocorrenciaId', 'eventoId', 'provaId', 'tipo', 'equipeNumero', 'tsMs', 'usuario', 'criadoEmMs', 'substituiId', 'uuidCliente', ],
        self::TYPE_COLNAME       => [ContagemOcorrenciaTableMap::COL_OCORRENCIA_ID, ContagemOcorrenciaTableMap::COL_EVENTO_ID, ContagemOcorrenciaTableMap::COL_PROVA_ID, ContagemOcorrenciaTableMap::COL_TIPO, ContagemOcorrenciaTableMap::COL_EQUIPE_NUMERO, ContagemOcorrenciaTableMap::COL_TS_MS, ContagemOcorrenciaTableMap::COL_USUARIO, ContagemOcorrenciaTableMap::COL_CRIADO_EM_MS, ContagemOcorrenciaTableMap::COL_SUBSTITUI_ID, ContagemOcorrenciaTableMap::COL_UUID_CLIENTE, ],
        self::TYPE_FIELDNAME     => ['ocorrencia_id', 'evento_id', 'prova_id', 'tipo', 'equipe_numero', 'ts_ms', 'usuario', 'criado_em_ms', 'substitui_id', 'uuid_cliente', ],
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
        self::TYPE_PHPNAME       => ['OcorrenciaId' => 0, 'EventoId' => 1, 'ProvaId' => 2, 'Tipo' => 3, 'EquipeNumero' => 4, 'TsMs' => 5, 'Usuario' => 6, 'CriadoEmMs' => 7, 'SubstituiId' => 8, 'UuidCliente' => 9, ],
        self::TYPE_CAMELNAME     => ['ocorrenciaId' => 0, 'eventoId' => 1, 'provaId' => 2, 'tipo' => 3, 'equipeNumero' => 4, 'tsMs' => 5, 'usuario' => 6, 'criadoEmMs' => 7, 'substituiId' => 8, 'uuidCliente' => 9, ],
        self::TYPE_COLNAME       => [ContagemOcorrenciaTableMap::COL_OCORRENCIA_ID => 0, ContagemOcorrenciaTableMap::COL_EVENTO_ID => 1, ContagemOcorrenciaTableMap::COL_PROVA_ID => 2, ContagemOcorrenciaTableMap::COL_TIPO => 3, ContagemOcorrenciaTableMap::COL_EQUIPE_NUMERO => 4, ContagemOcorrenciaTableMap::COL_TS_MS => 5, ContagemOcorrenciaTableMap::COL_USUARIO => 6, ContagemOcorrenciaTableMap::COL_CRIADO_EM_MS => 7, ContagemOcorrenciaTableMap::COL_SUBSTITUI_ID => 8, ContagemOcorrenciaTableMap::COL_UUID_CLIENTE => 9, ],
        self::TYPE_FIELDNAME     => ['ocorrencia_id' => 0, 'evento_id' => 1, 'prova_id' => 2, 'tipo' => 3, 'equipe_numero' => 4, 'ts_ms' => 5, 'usuario' => 6, 'criado_em_ms' => 7, 'substitui_id' => 8, 'uuid_cliente' => 9, ],
        self::TYPE_NUM           => [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, ]
    ];

    /**
     * Holds a list of column names and their normalized version.
     *
     * @var array<string>
     */
    protected $normalizedColumnNameMap = [
        'OcorrenciaId' => 'OCORRENCIA_ID',
        'ContagemOcorrencia.OcorrenciaId' => 'OCORRENCIA_ID',
        'ocorrenciaId' => 'OCORRENCIA_ID',
        'contagemOcorrencia.ocorrenciaId' => 'OCORRENCIA_ID',
        'ContagemOcorrenciaTableMap::COL_OCORRENCIA_ID' => 'OCORRENCIA_ID',
        'COL_OCORRENCIA_ID' => 'OCORRENCIA_ID',
        'ocorrencia_id' => 'OCORRENCIA_ID',
        'contagem_ocorrencia.ocorrencia_id' => 'OCORRENCIA_ID',
        'EventoId' => 'EVENTO_ID',
        'ContagemOcorrencia.EventoId' => 'EVENTO_ID',
        'eventoId' => 'EVENTO_ID',
        'contagemOcorrencia.eventoId' => 'EVENTO_ID',
        'ContagemOcorrenciaTableMap::COL_EVENTO_ID' => 'EVENTO_ID',
        'COL_EVENTO_ID' => 'EVENTO_ID',
        'evento_id' => 'EVENTO_ID',
        'contagem_ocorrencia.evento_id' => 'EVENTO_ID',
        'ProvaId' => 'PROVA_ID',
        'ContagemOcorrencia.ProvaId' => 'PROVA_ID',
        'provaId' => 'PROVA_ID',
        'contagemOcorrencia.provaId' => 'PROVA_ID',
        'ContagemOcorrenciaTableMap::COL_PROVA_ID' => 'PROVA_ID',
        'COL_PROVA_ID' => 'PROVA_ID',
        'prova_id' => 'PROVA_ID',
        'contagem_ocorrencia.prova_id' => 'PROVA_ID',
        'Tipo' => 'TIPO',
        'ContagemOcorrencia.Tipo' => 'TIPO',
        'tipo' => 'TIPO',
        'contagemOcorrencia.tipo' => 'TIPO',
        'ContagemOcorrenciaTableMap::COL_TIPO' => 'TIPO',
        'COL_TIPO' => 'TIPO',
        'contagem_ocorrencia.tipo' => 'TIPO',
        'EquipeNumero' => 'EQUIPE_NUMERO',
        'ContagemOcorrencia.EquipeNumero' => 'EQUIPE_NUMERO',
        'equipeNumero' => 'EQUIPE_NUMERO',
        'contagemOcorrencia.equipeNumero' => 'EQUIPE_NUMERO',
        'ContagemOcorrenciaTableMap::COL_EQUIPE_NUMERO' => 'EQUIPE_NUMERO',
        'COL_EQUIPE_NUMERO' => 'EQUIPE_NUMERO',
        'equipe_numero' => 'EQUIPE_NUMERO',
        'contagem_ocorrencia.equipe_numero' => 'EQUIPE_NUMERO',
        'TsMs' => 'TS_MS',
        'ContagemOcorrencia.TsMs' => 'TS_MS',
        'tsMs' => 'TS_MS',
        'contagemOcorrencia.tsMs' => 'TS_MS',
        'ContagemOcorrenciaTableMap::COL_TS_MS' => 'TS_MS',
        'COL_TS_MS' => 'TS_MS',
        'ts_ms' => 'TS_MS',
        'contagem_ocorrencia.ts_ms' => 'TS_MS',
        'Usuario' => 'USUARIO',
        'ContagemOcorrencia.Usuario' => 'USUARIO',
        'usuario' => 'USUARIO',
        'contagemOcorrencia.usuario' => 'USUARIO',
        'ContagemOcorrenciaTableMap::COL_USUARIO' => 'USUARIO',
        'COL_USUARIO' => 'USUARIO',
        'contagem_ocorrencia.usuario' => 'USUARIO',
        'CriadoEmMs' => 'CRIADO_EM_MS',
        'ContagemOcorrencia.CriadoEmMs' => 'CRIADO_EM_MS',
        'criadoEmMs' => 'CRIADO_EM_MS',
        'contagemOcorrencia.criadoEmMs' => 'CRIADO_EM_MS',
        'ContagemOcorrenciaTableMap::COL_CRIADO_EM_MS' => 'CRIADO_EM_MS',
        'COL_CRIADO_EM_MS' => 'CRIADO_EM_MS',
        'criado_em_ms' => 'CRIADO_EM_MS',
        'contagem_ocorrencia.criado_em_ms' => 'CRIADO_EM_MS',
        'SubstituiId' => 'SUBSTITUI_ID',
        'ContagemOcorrencia.SubstituiId' => 'SUBSTITUI_ID',
        'substituiId' => 'SUBSTITUI_ID',
        'contagemOcorrencia.substituiId' => 'SUBSTITUI_ID',
        'ContagemOcorrenciaTableMap::COL_SUBSTITUI_ID' => 'SUBSTITUI_ID',
        'COL_SUBSTITUI_ID' => 'SUBSTITUI_ID',
        'substitui_id' => 'SUBSTITUI_ID',
        'contagem_ocorrencia.substitui_id' => 'SUBSTITUI_ID',
        'UuidCliente' => 'UUID_CLIENTE',
        'ContagemOcorrencia.UuidCliente' => 'UUID_CLIENTE',
        'uuidCliente' => 'UUID_CLIENTE',
        'contagemOcorrencia.uuidCliente' => 'UUID_CLIENTE',
        'ContagemOcorrenciaTableMap::COL_UUID_CLIENTE' => 'UUID_CLIENTE',
        'COL_UUID_CLIENTE' => 'UUID_CLIENTE',
        'uuid_cliente' => 'UUID_CLIENTE',
        'contagem_ocorrencia.uuid_cliente' => 'UUID_CLIENTE',
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
        $this->setName('contagem_ocorrencia');
        $this->setPhpName('ContagemOcorrencia');
        $this->setIdentifierQuoting(false);
        $this->setClassName('\\Baja\\Model\\ContagemOcorrencia');
        $this->setPackage('Baja.Model');
        $this->setUseIdGenerator(true);
        // columns
        $this->addPrimaryKey('ocorrencia_id', 'OcorrenciaId', 'BIGINT', true, null, null);
        $this->addForeignKey('evento_id', 'EventoId', 'CHAR', 'prova', 'evento_id', true, 4, null);
        $this->addForeignKey('prova_id', 'ProvaId', 'CHAR', 'prova', 'prova_id', true, 3, null);
        $this->addColumn('tipo', 'Tipo', 'VARCHAR', true, 20, null);
        $this->addColumn('equipe_numero', 'EquipeNumero', 'INTEGER', false, null, null);
        $this->addColumn('ts_ms', 'TsMs', 'BIGINT', true, null, null);
        $this->addColumn('usuario', 'Usuario', 'VARCHAR', false, 45, null);
        $this->addColumn('criado_em_ms', 'CriadoEmMs', 'BIGINT', true, null, null);
        $this->addColumn('substitui_id', 'SubstituiId', 'BIGINT', false, null, null);
        $this->addColumn('uuid_cliente', 'UuidCliente', 'CHAR', false, 36, null);
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
        if ($row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('OcorrenciaId', TableMap::TYPE_PHPNAME, $indexType)] === null) {
            return null;
        }

        return null === $row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('OcorrenciaId', TableMap::TYPE_PHPNAME, $indexType)] || is_scalar($row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('OcorrenciaId', TableMap::TYPE_PHPNAME, $indexType)]) || is_callable([$row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('OcorrenciaId', TableMap::TYPE_PHPNAME, $indexType)], '__toString']) ? (string) $row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('OcorrenciaId', TableMap::TYPE_PHPNAME, $indexType)] : $row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('OcorrenciaId', TableMap::TYPE_PHPNAME, $indexType)];
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
                : self::translateFieldName('OcorrenciaId', TableMap::TYPE_PHPNAME, $indexType)
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
        return $withPrefix ? ContagemOcorrenciaTableMap::CLASS_DEFAULT : ContagemOcorrenciaTableMap::OM_CLASS;
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
     * @return array (ContagemOcorrencia object, last column rank)
     */
    public static function populateObject(array $row, int $offset = 0, string $indexType = TableMap::TYPE_NUM): array
    {
        $key = ContagemOcorrenciaTableMap::getPrimaryKeyHashFromRow($row, $offset, $indexType);
        if (null !== ($obj = ContagemOcorrenciaTableMap::getInstanceFromPool($key))) {
            // We no longer rehydrate the object, since this can cause data loss.
            // See http://www.propelorm.org/ticket/509
            // $obj->hydrate($row, $offset, true); // rehydrate
            $col = $offset + ContagemOcorrenciaTableMap::NUM_HYDRATE_COLUMNS;
        } else {
            $cls = ContagemOcorrenciaTableMap::OM_CLASS;
            /** @var ContagemOcorrencia $obj */
            $obj = new $cls();
            $col = $obj->hydrate($row, $offset, false, $indexType);
            ContagemOcorrenciaTableMap::addInstanceToPool($obj, $key);
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
            $key = ContagemOcorrenciaTableMap::getPrimaryKeyHashFromRow($row, 0, $dataFetcher->getIndexType());
            if (null !== ($obj = ContagemOcorrenciaTableMap::getInstanceFromPool($key))) {
                // We no longer rehydrate the object, since this can cause data loss.
                // See http://www.propelorm.org/ticket/509
                // $obj->hydrate($row, 0, true); // rehydrate
                $results[] = $obj;
            } else {
                /** @var ContagemOcorrencia $obj */
                $obj = new $cls();
                $obj->hydrate($row);
                $results[] = $obj;
                ContagemOcorrenciaTableMap::addInstanceToPool($obj, $key);
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
            $criteria->addSelectColumn(ContagemOcorrenciaTableMap::COL_OCORRENCIA_ID);
            $criteria->addSelectColumn(ContagemOcorrenciaTableMap::COL_EVENTO_ID);
            $criteria->addSelectColumn(ContagemOcorrenciaTableMap::COL_PROVA_ID);
            $criteria->addSelectColumn(ContagemOcorrenciaTableMap::COL_TIPO);
            $criteria->addSelectColumn(ContagemOcorrenciaTableMap::COL_EQUIPE_NUMERO);
            $criteria->addSelectColumn(ContagemOcorrenciaTableMap::COL_TS_MS);
            $criteria->addSelectColumn(ContagemOcorrenciaTableMap::COL_USUARIO);
            $criteria->addSelectColumn(ContagemOcorrenciaTableMap::COL_CRIADO_EM_MS);
            $criteria->addSelectColumn(ContagemOcorrenciaTableMap::COL_SUBSTITUI_ID);
            $criteria->addSelectColumn(ContagemOcorrenciaTableMap::COL_UUID_CLIENTE);
        } else {
            $criteria->addSelectColumn($alias . '.ocorrencia_id');
            $criteria->addSelectColumn($alias . '.evento_id');
            $criteria->addSelectColumn($alias . '.prova_id');
            $criteria->addSelectColumn($alias . '.tipo');
            $criteria->addSelectColumn($alias . '.equipe_numero');
            $criteria->addSelectColumn($alias . '.ts_ms');
            $criteria->addSelectColumn($alias . '.usuario');
            $criteria->addSelectColumn($alias . '.criado_em_ms');
            $criteria->addSelectColumn($alias . '.substitui_id');
            $criteria->addSelectColumn($alias . '.uuid_cliente');
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
            $criteria->removeSelectColumn(ContagemOcorrenciaTableMap::COL_OCORRENCIA_ID);
            $criteria->removeSelectColumn(ContagemOcorrenciaTableMap::COL_EVENTO_ID);
            $criteria->removeSelectColumn(ContagemOcorrenciaTableMap::COL_PROVA_ID);
            $criteria->removeSelectColumn(ContagemOcorrenciaTableMap::COL_TIPO);
            $criteria->removeSelectColumn(ContagemOcorrenciaTableMap::COL_EQUIPE_NUMERO);
            $criteria->removeSelectColumn(ContagemOcorrenciaTableMap::COL_TS_MS);
            $criteria->removeSelectColumn(ContagemOcorrenciaTableMap::COL_USUARIO);
            $criteria->removeSelectColumn(ContagemOcorrenciaTableMap::COL_CRIADO_EM_MS);
            $criteria->removeSelectColumn(ContagemOcorrenciaTableMap::COL_SUBSTITUI_ID);
            $criteria->removeSelectColumn(ContagemOcorrenciaTableMap::COL_UUID_CLIENTE);
        } else {
            $criteria->removeSelectColumn($alias . '.ocorrencia_id');
            $criteria->removeSelectColumn($alias . '.evento_id');
            $criteria->removeSelectColumn($alias . '.prova_id');
            $criteria->removeSelectColumn($alias . '.tipo');
            $criteria->removeSelectColumn($alias . '.equipe_numero');
            $criteria->removeSelectColumn($alias . '.ts_ms');
            $criteria->removeSelectColumn($alias . '.usuario');
            $criteria->removeSelectColumn($alias . '.criado_em_ms');
            $criteria->removeSelectColumn($alias . '.substitui_id');
            $criteria->removeSelectColumn($alias . '.uuid_cliente');
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
        return Propel::getServiceContainer()->getDatabaseMap(ContagemOcorrenciaTableMap::DATABASE_NAME)->getTable(ContagemOcorrenciaTableMap::TABLE_NAME);
    }

    /**
     * Performs a DELETE on the database, given a ContagemOcorrencia or Criteria object OR a primary key value.
     *
     * @param mixed $values Criteria or ContagemOcorrencia object or primary key or array of primary keys
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
            $con = Propel::getServiceContainer()->getWriteConnection(ContagemOcorrenciaTableMap::DATABASE_NAME);
        }

        if ($values instanceof Criteria) {
            // rename for clarity
            $criteria = $values;
        } elseif ($values instanceof \Baja\Model\ContagemOcorrencia) { // it's a model object
            // create criteria based on pk values
            $criteria = $values->buildPkeyCriteria();
        } else { // it's a primary key, or an array of pks
            $criteria = new Criteria(ContagemOcorrenciaTableMap::DATABASE_NAME);
            $criteria->add(ContagemOcorrenciaTableMap::COL_OCORRENCIA_ID, (array) $values, Criteria::IN);
        }

        $query = ContagemOcorrenciaQuery::create()->mergeWith($criteria);

        if ($values instanceof Criteria) {
            ContagemOcorrenciaTableMap::clearInstancePool();
        } elseif (!is_object($values)) { // it's a primary key, or an array of pks
            foreach ((array) $values as $singleval) {
                ContagemOcorrenciaTableMap::removeInstanceFromPool($singleval);
            }
        }

        return $query->delete($con);
    }

    /**
     * Deletes all rows from the contagem_ocorrencia table.
     *
     * @param ConnectionInterface $con the connection to use
     * @return int The number of affected rows (if supported by underlying database driver).
     */
    public static function doDeleteAll(?ConnectionInterface $con = null): int
    {
        return ContagemOcorrenciaQuery::create()->doDeleteAll($con);
    }

    /**
     * Performs an INSERT on the database, given a ContagemOcorrencia or Criteria object.
     *
     * @param mixed $criteria Criteria or ContagemOcorrencia object containing data that is used to create the INSERT statement.
     * @param ConnectionInterface $con the ConnectionInterface connection to use
     * @return mixed The new primary key.
     * @throws \Propel\Runtime\Exception\PropelException Any exceptions caught during processing will be
     *                         rethrown wrapped into a PropelException.
     */
    public static function doInsert($criteria, ?ConnectionInterface $con = null)
    {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(ContagemOcorrenciaTableMap::DATABASE_NAME);
        }

        if ($criteria instanceof Criteria) {
            $criteria = clone $criteria; // rename for clarity
        } else {
            $criteria = $criteria->buildCriteria(); // build Criteria from ContagemOcorrencia object
        }

        if ($criteria->containsKey(ContagemOcorrenciaTableMap::COL_OCORRENCIA_ID) && $criteria->keyContainsValue(ContagemOcorrenciaTableMap::COL_OCORRENCIA_ID) ) {
            throw new PropelException('Cannot insert a value for auto-increment primary key ('.ContagemOcorrenciaTableMap::COL_OCORRENCIA_ID.')');
        }


        // Set the correct dbName
        $query = ContagemOcorrenciaQuery::create()->mergeWith($criteria);

        // use transaction because $criteria could contain info
        // for more than one table (I guess, conceivably)
        return $con->transaction(function () use ($con, $query) {
            return $query->doInsert($con);
        });
    }

}
