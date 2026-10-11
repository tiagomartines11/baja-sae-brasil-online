<?php

namespace Baja\Model\Map;

use Baja\Model\ContagemDispositivo;
use Baja\Model\ContagemDispositivoQuery;
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
 * This class defines the structure of the 'contagem_dispositivo' table.
 *
 *
 *
 * This map class is used by Propel to do runtime db structure discovery.
 * For example, the createSelectSql() method checks the type of a given column used in an
 * ORDER BY clause to know whether it needs to apply SQL to make the ORDER BY case-insensitive
 * (i.e. if it's a text column type).
 */
class ContagemDispositivoTableMap extends TableMap
{
    use InstancePoolTrait;
    use TableMapTrait;

    /**
     * The (dot-path) name of this class
     */
    public const CLASS_NAME = 'Baja.Model.Map.ContagemDispositivoTableMap';

    /**
     * The default database name for this class
     */
    public const DATABASE_NAME = 'resultados';

    /**
     * The table name for this class
     */
    public const TABLE_NAME = 'contagem_dispositivo';

    /**
     * The PHP name of this class (PascalCase)
     */
    public const TABLE_PHP_NAME = 'ContagemDispositivo';

    /**
     * The related Propel class for this table
     */
    public const OM_CLASS = '\\Baja\\Model\\ContagemDispositivo';

    /**
     * A class that can be returned by this tableMap
     */
    public const CLASS_DEFAULT = 'Baja.Model.ContagemDispositivo';

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
     * the column name for the dispositivo_id field
     */
    public const COL_DISPOSITIVO_ID = 'contagem_dispositivo.dispositivo_id';

    /**
     * the column name for the fonte_id field
     */
    public const COL_FONTE_ID = 'contagem_dispositivo.fonte_id';

    /**
     * the column name for the token_hash field
     */
    public const COL_TOKEN_HASH = 'contagem_dispositivo.token_hash';

    /**
     * the column name for the usuario field
     */
    public const COL_USUARIO = 'contagem_dispositivo.usuario';

    /**
     * the column name for the rotulo field
     */
    public const COL_ROTULO = 'contagem_dispositivo.rotulo';

    /**
     * the column name for the criado_em_ms field
     */
    public const COL_CRIADO_EM_MS = 'contagem_dispositivo.criado_em_ms';

    /**
     * the column name for the revogado_em_ms field
     */
    public const COL_REVOGADO_EM_MS = 'contagem_dispositivo.revogado_em_ms';

    /**
     * the column name for the visto_em_ms field
     */
    public const COL_VISTO_EM_MS = 'contagem_dispositivo.visto_em_ms';

    /**
     * the column name for the sincronizado_ate_ms field
     */
    public const COL_SINCRONIZADO_ATE_MS = 'contagem_dispositivo.sincronizado_ate_ms';

    /**
     * the column name for the primeiro_desvio_ms field
     */
    public const COL_PRIMEIRO_DESVIO_MS = 'contagem_dispositivo.primeiro_desvio_ms';

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
        self::TYPE_PHPNAME       => ['DispositivoId', 'FonteId', 'TokenHash', 'Usuario', 'Rotulo', 'CriadoEmMs', 'RevogadoEmMs', 'VistoEmMs', 'SincronizadoAteMs', 'PrimeiroDesvioMs', ],
        self::TYPE_CAMELNAME     => ['dispositivoId', 'fonteId', 'tokenHash', 'usuario', 'rotulo', 'criadoEmMs', 'revogadoEmMs', 'vistoEmMs', 'sincronizadoAteMs', 'primeiroDesvioMs', ],
        self::TYPE_COLNAME       => [ContagemDispositivoTableMap::COL_DISPOSITIVO_ID, ContagemDispositivoTableMap::COL_FONTE_ID, ContagemDispositivoTableMap::COL_TOKEN_HASH, ContagemDispositivoTableMap::COL_USUARIO, ContagemDispositivoTableMap::COL_ROTULO, ContagemDispositivoTableMap::COL_CRIADO_EM_MS, ContagemDispositivoTableMap::COL_REVOGADO_EM_MS, ContagemDispositivoTableMap::COL_VISTO_EM_MS, ContagemDispositivoTableMap::COL_SINCRONIZADO_ATE_MS, ContagemDispositivoTableMap::COL_PRIMEIRO_DESVIO_MS, ],
        self::TYPE_FIELDNAME     => ['dispositivo_id', 'fonte_id', 'token_hash', 'usuario', 'rotulo', 'criado_em_ms', 'revogado_em_ms', 'visto_em_ms', 'sincronizado_ate_ms', 'primeiro_desvio_ms', ],
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
        self::TYPE_PHPNAME       => ['DispositivoId' => 0, 'FonteId' => 1, 'TokenHash' => 2, 'Usuario' => 3, 'Rotulo' => 4, 'CriadoEmMs' => 5, 'RevogadoEmMs' => 6, 'VistoEmMs' => 7, 'SincronizadoAteMs' => 8, 'PrimeiroDesvioMs' => 9, ],
        self::TYPE_CAMELNAME     => ['dispositivoId' => 0, 'fonteId' => 1, 'tokenHash' => 2, 'usuario' => 3, 'rotulo' => 4, 'criadoEmMs' => 5, 'revogadoEmMs' => 6, 'vistoEmMs' => 7, 'sincronizadoAteMs' => 8, 'primeiroDesvioMs' => 9, ],
        self::TYPE_COLNAME       => [ContagemDispositivoTableMap::COL_DISPOSITIVO_ID => 0, ContagemDispositivoTableMap::COL_FONTE_ID => 1, ContagemDispositivoTableMap::COL_TOKEN_HASH => 2, ContagemDispositivoTableMap::COL_USUARIO => 3, ContagemDispositivoTableMap::COL_ROTULO => 4, ContagemDispositivoTableMap::COL_CRIADO_EM_MS => 5, ContagemDispositivoTableMap::COL_REVOGADO_EM_MS => 6, ContagemDispositivoTableMap::COL_VISTO_EM_MS => 7, ContagemDispositivoTableMap::COL_SINCRONIZADO_ATE_MS => 8, ContagemDispositivoTableMap::COL_PRIMEIRO_DESVIO_MS => 9, ],
        self::TYPE_FIELDNAME     => ['dispositivo_id' => 0, 'fonte_id' => 1, 'token_hash' => 2, 'usuario' => 3, 'rotulo' => 4, 'criado_em_ms' => 5, 'revogado_em_ms' => 6, 'visto_em_ms' => 7, 'sincronizado_ate_ms' => 8, 'primeiro_desvio_ms' => 9, ],
        self::TYPE_NUM           => [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, ]
    ];

    /**
     * Holds a list of column names and their normalized version.
     *
     * @var array<string>
     */
    protected $normalizedColumnNameMap = [
        'DispositivoId' => 'DISPOSITIVO_ID',
        'ContagemDispositivo.DispositivoId' => 'DISPOSITIVO_ID',
        'dispositivoId' => 'DISPOSITIVO_ID',
        'contagemDispositivo.dispositivoId' => 'DISPOSITIVO_ID',
        'ContagemDispositivoTableMap::COL_DISPOSITIVO_ID' => 'DISPOSITIVO_ID',
        'COL_DISPOSITIVO_ID' => 'DISPOSITIVO_ID',
        'dispositivo_id' => 'DISPOSITIVO_ID',
        'contagem_dispositivo.dispositivo_id' => 'DISPOSITIVO_ID',
        'FonteId' => 'FONTE_ID',
        'ContagemDispositivo.FonteId' => 'FONTE_ID',
        'fonteId' => 'FONTE_ID',
        'contagemDispositivo.fonteId' => 'FONTE_ID',
        'ContagemDispositivoTableMap::COL_FONTE_ID' => 'FONTE_ID',
        'COL_FONTE_ID' => 'FONTE_ID',
        'fonte_id' => 'FONTE_ID',
        'contagem_dispositivo.fonte_id' => 'FONTE_ID',
        'TokenHash' => 'TOKEN_HASH',
        'ContagemDispositivo.TokenHash' => 'TOKEN_HASH',
        'tokenHash' => 'TOKEN_HASH',
        'contagemDispositivo.tokenHash' => 'TOKEN_HASH',
        'ContagemDispositivoTableMap::COL_TOKEN_HASH' => 'TOKEN_HASH',
        'COL_TOKEN_HASH' => 'TOKEN_HASH',
        'token_hash' => 'TOKEN_HASH',
        'contagem_dispositivo.token_hash' => 'TOKEN_HASH',
        'Usuario' => 'USUARIO',
        'ContagemDispositivo.Usuario' => 'USUARIO',
        'usuario' => 'USUARIO',
        'contagemDispositivo.usuario' => 'USUARIO',
        'ContagemDispositivoTableMap::COL_USUARIO' => 'USUARIO',
        'COL_USUARIO' => 'USUARIO',
        'contagem_dispositivo.usuario' => 'USUARIO',
        'Rotulo' => 'ROTULO',
        'ContagemDispositivo.Rotulo' => 'ROTULO',
        'rotulo' => 'ROTULO',
        'contagemDispositivo.rotulo' => 'ROTULO',
        'ContagemDispositivoTableMap::COL_ROTULO' => 'ROTULO',
        'COL_ROTULO' => 'ROTULO',
        'contagem_dispositivo.rotulo' => 'ROTULO',
        'CriadoEmMs' => 'CRIADO_EM_MS',
        'ContagemDispositivo.CriadoEmMs' => 'CRIADO_EM_MS',
        'criadoEmMs' => 'CRIADO_EM_MS',
        'contagemDispositivo.criadoEmMs' => 'CRIADO_EM_MS',
        'ContagemDispositivoTableMap::COL_CRIADO_EM_MS' => 'CRIADO_EM_MS',
        'COL_CRIADO_EM_MS' => 'CRIADO_EM_MS',
        'criado_em_ms' => 'CRIADO_EM_MS',
        'contagem_dispositivo.criado_em_ms' => 'CRIADO_EM_MS',
        'RevogadoEmMs' => 'REVOGADO_EM_MS',
        'ContagemDispositivo.RevogadoEmMs' => 'REVOGADO_EM_MS',
        'revogadoEmMs' => 'REVOGADO_EM_MS',
        'contagemDispositivo.revogadoEmMs' => 'REVOGADO_EM_MS',
        'ContagemDispositivoTableMap::COL_REVOGADO_EM_MS' => 'REVOGADO_EM_MS',
        'COL_REVOGADO_EM_MS' => 'REVOGADO_EM_MS',
        'revogado_em_ms' => 'REVOGADO_EM_MS',
        'contagem_dispositivo.revogado_em_ms' => 'REVOGADO_EM_MS',
        'VistoEmMs' => 'VISTO_EM_MS',
        'ContagemDispositivo.VistoEmMs' => 'VISTO_EM_MS',
        'vistoEmMs' => 'VISTO_EM_MS',
        'contagemDispositivo.vistoEmMs' => 'VISTO_EM_MS',
        'ContagemDispositivoTableMap::COL_VISTO_EM_MS' => 'VISTO_EM_MS',
        'COL_VISTO_EM_MS' => 'VISTO_EM_MS',
        'visto_em_ms' => 'VISTO_EM_MS',
        'contagem_dispositivo.visto_em_ms' => 'VISTO_EM_MS',
        'SincronizadoAteMs' => 'SINCRONIZADO_ATE_MS',
        'ContagemDispositivo.SincronizadoAteMs' => 'SINCRONIZADO_ATE_MS',
        'sincronizadoAteMs' => 'SINCRONIZADO_ATE_MS',
        'contagemDispositivo.sincronizadoAteMs' => 'SINCRONIZADO_ATE_MS',
        'ContagemDispositivoTableMap::COL_SINCRONIZADO_ATE_MS' => 'SINCRONIZADO_ATE_MS',
        'COL_SINCRONIZADO_ATE_MS' => 'SINCRONIZADO_ATE_MS',
        'sincronizado_ate_ms' => 'SINCRONIZADO_ATE_MS',
        'contagem_dispositivo.sincronizado_ate_ms' => 'SINCRONIZADO_ATE_MS',
        'PrimeiroDesvioMs' => 'PRIMEIRO_DESVIO_MS',
        'ContagemDispositivo.PrimeiroDesvioMs' => 'PRIMEIRO_DESVIO_MS',
        'primeiroDesvioMs' => 'PRIMEIRO_DESVIO_MS',
        'contagemDispositivo.primeiroDesvioMs' => 'PRIMEIRO_DESVIO_MS',
        'ContagemDispositivoTableMap::COL_PRIMEIRO_DESVIO_MS' => 'PRIMEIRO_DESVIO_MS',
        'COL_PRIMEIRO_DESVIO_MS' => 'PRIMEIRO_DESVIO_MS',
        'primeiro_desvio_ms' => 'PRIMEIRO_DESVIO_MS',
        'contagem_dispositivo.primeiro_desvio_ms' => 'PRIMEIRO_DESVIO_MS',
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
        $this->setName('contagem_dispositivo');
        $this->setPhpName('ContagemDispositivo');
        $this->setIdentifierQuoting(false);
        $this->setClassName('\\Baja\\Model\\ContagemDispositivo');
        $this->setPackage('Baja.Model');
        $this->setUseIdGenerator(true);
        // columns
        $this->addPrimaryKey('dispositivo_id', 'DispositivoId', 'INTEGER', true, null, null);
        $this->addForeignKey('fonte_id', 'FonteId', 'INTEGER', 'contagem_fonte', 'fonte_id', true, null, null);
        $this->addColumn('token_hash', 'TokenHash', 'CHAR', true, 64, null);
        $this->addColumn('usuario', 'Usuario', 'VARCHAR', false, 45, null);
        $this->addColumn('rotulo', 'Rotulo', 'VARCHAR', false, 255, null);
        $this->addColumn('criado_em_ms', 'CriadoEmMs', 'BIGINT', true, null, null);
        $this->addColumn('revogado_em_ms', 'RevogadoEmMs', 'BIGINT', false, null, null);
        $this->addColumn('visto_em_ms', 'VistoEmMs', 'BIGINT', false, null, null);
        $this->addColumn('sincronizado_ate_ms', 'SincronizadoAteMs', 'BIGINT', false, null, null);
        $this->addColumn('primeiro_desvio_ms', 'PrimeiroDesvioMs', 'BIGINT', false, null, null);
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
        $this->addRelation('ContagemPassagem', '\\Baja\\Model\\ContagemPassagem', RelationMap::ONE_TO_MANY, array (
  0 =>
  array (
    0 => ':dispositivo_id',
    1 => ':dispositivo_id',
  ),
), 'CASCADE', 'CASCADE', 'ContagemPassagems', false);
    }

    /**
     * Method to invalidate the instance pool of all tables related to contagem_dispositivo     * by a foreign key with ON DELETE CASCADE
     */
    public static function clearRelatedInstancePool(): void
    {
        // Invalidate objects in related instance pools,
        // since one or more of them may be deleted by ON DELETE CASCADE/SETNULL rule.
        ContagemPassagemTableMap::clearInstancePool();
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
        if ($row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('DispositivoId', TableMap::TYPE_PHPNAME, $indexType)] === null) {
            return null;
        }

        return null === $row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('DispositivoId', TableMap::TYPE_PHPNAME, $indexType)] || is_scalar($row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('DispositivoId', TableMap::TYPE_PHPNAME, $indexType)]) || is_callable([$row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('DispositivoId', TableMap::TYPE_PHPNAME, $indexType)], '__toString']) ? (string) $row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('DispositivoId', TableMap::TYPE_PHPNAME, $indexType)] : $row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('DispositivoId', TableMap::TYPE_PHPNAME, $indexType)];
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
        return (int) $row[
            $indexType == TableMap::TYPE_NUM
                ? 0 + $offset
                : self::translateFieldName('DispositivoId', TableMap::TYPE_PHPNAME, $indexType)
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
        return $withPrefix ? ContagemDispositivoTableMap::CLASS_DEFAULT : ContagemDispositivoTableMap::OM_CLASS;
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
     * @return array (ContagemDispositivo object, last column rank)
     */
    public static function populateObject(array $row, int $offset = 0, string $indexType = TableMap::TYPE_NUM): array
    {
        $key = ContagemDispositivoTableMap::getPrimaryKeyHashFromRow($row, $offset, $indexType);
        if (null !== ($obj = ContagemDispositivoTableMap::getInstanceFromPool($key))) {
            // We no longer rehydrate the object, since this can cause data loss.
            // See http://www.propelorm.org/ticket/509
            // $obj->hydrate($row, $offset, true); // rehydrate
            $col = $offset + ContagemDispositivoTableMap::NUM_HYDRATE_COLUMNS;
        } else {
            $cls = ContagemDispositivoTableMap::OM_CLASS;
            /** @var ContagemDispositivo $obj */
            $obj = new $cls();
            $col = $obj->hydrate($row, $offset, false, $indexType);
            ContagemDispositivoTableMap::addInstanceToPool($obj, $key);
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
            $key = ContagemDispositivoTableMap::getPrimaryKeyHashFromRow($row, 0, $dataFetcher->getIndexType());
            if (null !== ($obj = ContagemDispositivoTableMap::getInstanceFromPool($key))) {
                // We no longer rehydrate the object, since this can cause data loss.
                // See http://www.propelorm.org/ticket/509
                // $obj->hydrate($row, 0, true); // rehydrate
                $results[] = $obj;
            } else {
                /** @var ContagemDispositivo $obj */
                $obj = new $cls();
                $obj->hydrate($row);
                $results[] = $obj;
                ContagemDispositivoTableMap::addInstanceToPool($obj, $key);
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
            $criteria->addSelectColumn(ContagemDispositivoTableMap::COL_DISPOSITIVO_ID);
            $criteria->addSelectColumn(ContagemDispositivoTableMap::COL_FONTE_ID);
            $criteria->addSelectColumn(ContagemDispositivoTableMap::COL_TOKEN_HASH);
            $criteria->addSelectColumn(ContagemDispositivoTableMap::COL_USUARIO);
            $criteria->addSelectColumn(ContagemDispositivoTableMap::COL_ROTULO);
            $criteria->addSelectColumn(ContagemDispositivoTableMap::COL_CRIADO_EM_MS);
            $criteria->addSelectColumn(ContagemDispositivoTableMap::COL_REVOGADO_EM_MS);
            $criteria->addSelectColumn(ContagemDispositivoTableMap::COL_VISTO_EM_MS);
            $criteria->addSelectColumn(ContagemDispositivoTableMap::COL_SINCRONIZADO_ATE_MS);
            $criteria->addSelectColumn(ContagemDispositivoTableMap::COL_PRIMEIRO_DESVIO_MS);
        } else {
            $criteria->addSelectColumn($alias . '.dispositivo_id');
            $criteria->addSelectColumn($alias . '.fonte_id');
            $criteria->addSelectColumn($alias . '.token_hash');
            $criteria->addSelectColumn($alias . '.usuario');
            $criteria->addSelectColumn($alias . '.rotulo');
            $criteria->addSelectColumn($alias . '.criado_em_ms');
            $criteria->addSelectColumn($alias . '.revogado_em_ms');
            $criteria->addSelectColumn($alias . '.visto_em_ms');
            $criteria->addSelectColumn($alias . '.sincronizado_ate_ms');
            $criteria->addSelectColumn($alias . '.primeiro_desvio_ms');
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
            $criteria->removeSelectColumn(ContagemDispositivoTableMap::COL_DISPOSITIVO_ID);
            $criteria->removeSelectColumn(ContagemDispositivoTableMap::COL_FONTE_ID);
            $criteria->removeSelectColumn(ContagemDispositivoTableMap::COL_TOKEN_HASH);
            $criteria->removeSelectColumn(ContagemDispositivoTableMap::COL_USUARIO);
            $criteria->removeSelectColumn(ContagemDispositivoTableMap::COL_ROTULO);
            $criteria->removeSelectColumn(ContagemDispositivoTableMap::COL_CRIADO_EM_MS);
            $criteria->removeSelectColumn(ContagemDispositivoTableMap::COL_REVOGADO_EM_MS);
            $criteria->removeSelectColumn(ContagemDispositivoTableMap::COL_VISTO_EM_MS);
            $criteria->removeSelectColumn(ContagemDispositivoTableMap::COL_SINCRONIZADO_ATE_MS);
            $criteria->removeSelectColumn(ContagemDispositivoTableMap::COL_PRIMEIRO_DESVIO_MS);
        } else {
            $criteria->removeSelectColumn($alias . '.dispositivo_id');
            $criteria->removeSelectColumn($alias . '.fonte_id');
            $criteria->removeSelectColumn($alias . '.token_hash');
            $criteria->removeSelectColumn($alias . '.usuario');
            $criteria->removeSelectColumn($alias . '.rotulo');
            $criteria->removeSelectColumn($alias . '.criado_em_ms');
            $criteria->removeSelectColumn($alias . '.revogado_em_ms');
            $criteria->removeSelectColumn($alias . '.visto_em_ms');
            $criteria->removeSelectColumn($alias . '.sincronizado_ate_ms');
            $criteria->removeSelectColumn($alias . '.primeiro_desvio_ms');
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
        return Propel::getServiceContainer()->getDatabaseMap(ContagemDispositivoTableMap::DATABASE_NAME)->getTable(ContagemDispositivoTableMap::TABLE_NAME);
    }

    /**
     * Performs a DELETE on the database, given a ContagemDispositivo or Criteria object OR a primary key value.
     *
     * @param mixed $values Criteria or ContagemDispositivo object or primary key or array of primary keys
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
            $con = Propel::getServiceContainer()->getWriteConnection(ContagemDispositivoTableMap::DATABASE_NAME);
        }

        if ($values instanceof Criteria) {
            // rename for clarity
            $criteria = $values;
        } elseif ($values instanceof \Baja\Model\ContagemDispositivo) { // it's a model object
            // create criteria based on pk values
            $criteria = $values->buildPkeyCriteria();
        } else { // it's a primary key, or an array of pks
            $criteria = new Criteria(ContagemDispositivoTableMap::DATABASE_NAME);
            $criteria->add(ContagemDispositivoTableMap::COL_DISPOSITIVO_ID, (array) $values, Criteria::IN);
        }

        $query = ContagemDispositivoQuery::create()->mergeWith($criteria);

        if ($values instanceof Criteria) {
            ContagemDispositivoTableMap::clearInstancePool();
        } elseif (!is_object($values)) { // it's a primary key, or an array of pks
            foreach ((array) $values as $singleval) {
                ContagemDispositivoTableMap::removeInstanceFromPool($singleval);
            }
        }

        return $query->delete($con);
    }

    /**
     * Deletes all rows from the contagem_dispositivo table.
     *
     * @param ConnectionInterface $con the connection to use
     * @return int The number of affected rows (if supported by underlying database driver).
     */
    public static function doDeleteAll(?ConnectionInterface $con = null): int
    {
        return ContagemDispositivoQuery::create()->doDeleteAll($con);
    }

    /**
     * Performs an INSERT on the database, given a ContagemDispositivo or Criteria object.
     *
     * @param mixed $criteria Criteria or ContagemDispositivo object containing data that is used to create the INSERT statement.
     * @param ConnectionInterface $con the ConnectionInterface connection to use
     * @return mixed The new primary key.
     * @throws \Propel\Runtime\Exception\PropelException Any exceptions caught during processing will be
     *                         rethrown wrapped into a PropelException.
     */
    public static function doInsert($criteria, ?ConnectionInterface $con = null)
    {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(ContagemDispositivoTableMap::DATABASE_NAME);
        }

        if ($criteria instanceof Criteria) {
            $criteria = clone $criteria; // rename for clarity
        } else {
            $criteria = $criteria->buildCriteria(); // build Criteria from ContagemDispositivo object
        }

        if ($criteria->containsKey(ContagemDispositivoTableMap::COL_DISPOSITIVO_ID) && $criteria->keyContainsValue(ContagemDispositivoTableMap::COL_DISPOSITIVO_ID) ) {
            throw new PropelException('Cannot insert a value for auto-increment primary key ('.ContagemDispositivoTableMap::COL_DISPOSITIVO_ID.')');
        }


        // Set the correct dbName
        $query = ContagemDispositivoQuery::create()->mergeWith($criteria);

        // use transaction because $criteria could contain info
        // for more than one table (I guess, conceivably)
        return $con->transaction(function () use ($con, $query) {
            return $query->doInsert($con);
        });
    }

}
