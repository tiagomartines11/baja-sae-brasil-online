<?php

namespace Baja\Model\Map;

use Baja\Model\Premiacao;
use Baja\Model\PremiacaoQuery;
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
 * This class defines the structure of the 'premiacao' table.
 *
 *
 *
 * This map class is used by Propel to do runtime db structure discovery.
 * For example, the createSelectSql() method checks the type of a given column used in an
 * ORDER BY clause to know whether it needs to apply SQL to make the ORDER BY case-insensitive
 * (i.e. if it's a text column type).
 */
class PremiacaoTableMap extends TableMap
{
    use InstancePoolTrait;
    use TableMapTrait;

    /**
     * The (dot-path) name of this class
     */
    public const CLASS_NAME = 'Baja.Model.Map.PremiacaoTableMap';

    /**
     * The default database name for this class
     */
    public const DATABASE_NAME = 'resultados';

    /**
     * The table name for this class
     */
    public const TABLE_NAME = 'premiacao';

    /**
     * The PHP name of this class (PascalCase)
     */
    public const TABLE_PHP_NAME = 'Premiacao';

    /**
     * The related Propel class for this table
     */
    public const OM_CLASS = '\\Baja\\Model\\Premiacao';

    /**
     * A class that can be returned by this tableMap
     */
    public const CLASS_DEFAULT = 'Baja.Model.Premiacao';

    /**
     * The total number of columns
     */
    public const NUM_COLUMNS = 7;

    /**
     * The number of lazy-loaded columns
     */
    public const NUM_LAZY_LOAD_COLUMNS = 0;

    /**
     * The number of columns to hydrate (NUM_COLUMNS - NUM_LAZY_LOAD_COLUMNS)
     */
    public const NUM_HYDRATE_COLUMNS = 7;

    /**
     * the column name for the premiacao_id field
     */
    public const COL_PREMIACAO_ID = 'premiacao.premiacao_id';

    /**
     * the column name for the evento_id field
     */
    public const COL_EVENTO_ID = 'premiacao.evento_id';

    /**
     * the column name for the nome field
     */
    public const COL_NOME = 'premiacao.nome';

    /**
     * the column name for the status field
     */
    public const COL_STATUS = 'premiacao.status';

    /**
     * the column name for the modificado field
     */
    public const COL_MODIFICADO = 'premiacao.modificado';

    /**
     * the column name for the categorias field
     */
    public const COL_CATEGORIAS = 'premiacao.categorias';

    /**
     * the column name for the categorias_backup field
     */
    public const COL_CATEGORIAS_BACKUP = 'premiacao.categorias_backup';

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
        self::TYPE_PHPNAME       => ['PremiacaoId', 'EventoId', 'Nome', 'Status', 'Modificado', 'Categorias', 'CategoriasBackup', ],
        self::TYPE_CAMELNAME     => ['premiacaoId', 'eventoId', 'nome', 'status', 'modificado', 'categorias', 'categoriasBackup', ],
        self::TYPE_COLNAME       => [PremiacaoTableMap::COL_PREMIACAO_ID, PremiacaoTableMap::COL_EVENTO_ID, PremiacaoTableMap::COL_NOME, PremiacaoTableMap::COL_STATUS, PremiacaoTableMap::COL_MODIFICADO, PremiacaoTableMap::COL_CATEGORIAS, PremiacaoTableMap::COL_CATEGORIAS_BACKUP, ],
        self::TYPE_FIELDNAME     => ['premiacao_id', 'evento_id', 'nome', 'status', 'modificado', 'categorias', 'categorias_backup', ],
        self::TYPE_NUM           => [0, 1, 2, 3, 4, 5, 6, ]
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
        self::TYPE_PHPNAME       => ['PremiacaoId' => 0, 'EventoId' => 1, 'Nome' => 2, 'Status' => 3, 'Modificado' => 4, 'Categorias' => 5, 'CategoriasBackup' => 6, ],
        self::TYPE_CAMELNAME     => ['premiacaoId' => 0, 'eventoId' => 1, 'nome' => 2, 'status' => 3, 'modificado' => 4, 'categorias' => 5, 'categoriasBackup' => 6, ],
        self::TYPE_COLNAME       => [PremiacaoTableMap::COL_PREMIACAO_ID => 0, PremiacaoTableMap::COL_EVENTO_ID => 1, PremiacaoTableMap::COL_NOME => 2, PremiacaoTableMap::COL_STATUS => 3, PremiacaoTableMap::COL_MODIFICADO => 4, PremiacaoTableMap::COL_CATEGORIAS => 5, PremiacaoTableMap::COL_CATEGORIAS_BACKUP => 6, ],
        self::TYPE_FIELDNAME     => ['premiacao_id' => 0, 'evento_id' => 1, 'nome' => 2, 'status' => 3, 'modificado' => 4, 'categorias' => 5, 'categorias_backup' => 6, ],
        self::TYPE_NUM           => [0, 1, 2, 3, 4, 5, 6, ]
    ];

    /**
     * Holds a list of column names and their normalized version.
     *
     * @var array<string>
     */
    protected $normalizedColumnNameMap = [
        'PremiacaoId' => 'PREMIACAO_ID',
        'Premiacao.PremiacaoId' => 'PREMIACAO_ID',
        'premiacaoId' => 'PREMIACAO_ID',
        'premiacao.premiacaoId' => 'PREMIACAO_ID',
        'PremiacaoTableMap::COL_PREMIACAO_ID' => 'PREMIACAO_ID',
        'COL_PREMIACAO_ID' => 'PREMIACAO_ID',
        'premiacao_id' => 'PREMIACAO_ID',
        'premiacao.premiacao_id' => 'PREMIACAO_ID',
        'EventoId' => 'EVENTO_ID',
        'Premiacao.EventoId' => 'EVENTO_ID',
        'eventoId' => 'EVENTO_ID',
        'premiacao.eventoId' => 'EVENTO_ID',
        'PremiacaoTableMap::COL_EVENTO_ID' => 'EVENTO_ID',
        'COL_EVENTO_ID' => 'EVENTO_ID',
        'evento_id' => 'EVENTO_ID',
        'premiacao.evento_id' => 'EVENTO_ID',
        'Nome' => 'NOME',
        'Premiacao.Nome' => 'NOME',
        'nome' => 'NOME',
        'premiacao.nome' => 'NOME',
        'PremiacaoTableMap::COL_NOME' => 'NOME',
        'COL_NOME' => 'NOME',
        'Status' => 'STATUS',
        'Premiacao.Status' => 'STATUS',
        'status' => 'STATUS',
        'premiacao.status' => 'STATUS',
        'PremiacaoTableMap::COL_STATUS' => 'STATUS',
        'COL_STATUS' => 'STATUS',
        'Modificado' => 'MODIFICADO',
        'Premiacao.Modificado' => 'MODIFICADO',
        'modificado' => 'MODIFICADO',
        'premiacao.modificado' => 'MODIFICADO',
        'PremiacaoTableMap::COL_MODIFICADO' => 'MODIFICADO',
        'COL_MODIFICADO' => 'MODIFICADO',
        'Categorias' => 'CATEGORIAS',
        'Premiacao.Categorias' => 'CATEGORIAS',
        'categorias' => 'CATEGORIAS',
        'premiacao.categorias' => 'CATEGORIAS',
        'PremiacaoTableMap::COL_CATEGORIAS' => 'CATEGORIAS',
        'COL_CATEGORIAS' => 'CATEGORIAS',
        'CategoriasBackup' => 'CATEGORIAS_BACKUP',
        'Premiacao.CategoriasBackup' => 'CATEGORIAS_BACKUP',
        'categoriasBackup' => 'CATEGORIAS_BACKUP',
        'premiacao.categoriasBackup' => 'CATEGORIAS_BACKUP',
        'PremiacaoTableMap::COL_CATEGORIAS_BACKUP' => 'CATEGORIAS_BACKUP',
        'COL_CATEGORIAS_BACKUP' => 'CATEGORIAS_BACKUP',
        'categorias_backup' => 'CATEGORIAS_BACKUP',
        'premiacao.categorias_backup' => 'CATEGORIAS_BACKUP',
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
        $this->setName('premiacao');
        $this->setPhpName('Premiacao');
        $this->setIdentifierQuoting(false);
        $this->setClassName('\\Baja\\Model\\Premiacao');
        $this->setPackage('Baja.Model');
        $this->setUseIdGenerator(false);
        // columns
        $this->addPrimaryKey('premiacao_id', 'PremiacaoId', 'CHAR', true, 8, null);
        $this->addForeignKey('evento_id', 'EventoId', 'CHAR', 'evento', 'evento_id', true, 4, null);
        $this->addColumn('nome', 'Nome', 'VARCHAR', true, 45, null);
        $this->addColumn('status', 'Status', 'BOOLEAN', true, 1, true);
        $this->addColumn('modificado', 'Modificado', 'TIMESTAMP', false, null, 'CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
        $this->addColumn('categorias', 'Categorias', 'LONGVARCHAR', false, null, null);
        $this->addColumn('categorias_backup', 'CategoriasBackup', 'LONGVARCHAR', false, null, null);
    }

    /**
     * Build the RelationMap objects for this table relationships
     *
     * @return void
     */
    public function buildRelations(): void
    {
        $this->addRelation('Evento', '\\Baja\\Model\\Evento', RelationMap::MANY_TO_ONE, array (
  0 =>
  array (
    0 => ':evento_id',
    1 => ':evento_id',
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
        if ($row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('PremiacaoId', TableMap::TYPE_PHPNAME, $indexType)] === null) {
            return null;
        }

        return null === $row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('PremiacaoId', TableMap::TYPE_PHPNAME, $indexType)] || is_scalar($row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('PremiacaoId', TableMap::TYPE_PHPNAME, $indexType)]) || is_callable([$row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('PremiacaoId', TableMap::TYPE_PHPNAME, $indexType)], '__toString']) ? (string) $row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('PremiacaoId', TableMap::TYPE_PHPNAME, $indexType)] : $row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('PremiacaoId', TableMap::TYPE_PHPNAME, $indexType)];
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
                : self::translateFieldName('PremiacaoId', TableMap::TYPE_PHPNAME, $indexType)
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
        return $withPrefix ? PremiacaoTableMap::CLASS_DEFAULT : PremiacaoTableMap::OM_CLASS;
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
     * @return array (Premiacao object, last column rank)
     */
    public static function populateObject(array $row, int $offset = 0, string $indexType = TableMap::TYPE_NUM): array
    {
        $key = PremiacaoTableMap::getPrimaryKeyHashFromRow($row, $offset, $indexType);
        if (null !== ($obj = PremiacaoTableMap::getInstanceFromPool($key))) {
            // We no longer rehydrate the object, since this can cause data loss.
            // See http://www.propelorm.org/ticket/509
            // $obj->hydrate($row, $offset, true); // rehydrate
            $col = $offset + PremiacaoTableMap::NUM_HYDRATE_COLUMNS;
        } else {
            $cls = PremiacaoTableMap::OM_CLASS;
            /** @var Premiacao $obj */
            $obj = new $cls();
            $col = $obj->hydrate($row, $offset, false, $indexType);
            PremiacaoTableMap::addInstanceToPool($obj, $key);
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
            $key = PremiacaoTableMap::getPrimaryKeyHashFromRow($row, 0, $dataFetcher->getIndexType());
            if (null !== ($obj = PremiacaoTableMap::getInstanceFromPool($key))) {
                // We no longer rehydrate the object, since this can cause data loss.
                // See http://www.propelorm.org/ticket/509
                // $obj->hydrate($row, 0, true); // rehydrate
                $results[] = $obj;
            } else {
                /** @var Premiacao $obj */
                $obj = new $cls();
                $obj->hydrate($row);
                $results[] = $obj;
                PremiacaoTableMap::addInstanceToPool($obj, $key);
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
            $criteria->addSelectColumn(PremiacaoTableMap::COL_PREMIACAO_ID);
            $criteria->addSelectColumn(PremiacaoTableMap::COL_EVENTO_ID);
            $criteria->addSelectColumn(PremiacaoTableMap::COL_NOME);
            $criteria->addSelectColumn(PremiacaoTableMap::COL_STATUS);
            $criteria->addSelectColumn(PremiacaoTableMap::COL_MODIFICADO);
            $criteria->addSelectColumn(PremiacaoTableMap::COL_CATEGORIAS);
            $criteria->addSelectColumn(PremiacaoTableMap::COL_CATEGORIAS_BACKUP);
        } else {
            $criteria->addSelectColumn($alias . '.premiacao_id');
            $criteria->addSelectColumn($alias . '.evento_id');
            $criteria->addSelectColumn($alias . '.nome');
            $criteria->addSelectColumn($alias . '.status');
            $criteria->addSelectColumn($alias . '.modificado');
            $criteria->addSelectColumn($alias . '.categorias');
            $criteria->addSelectColumn($alias . '.categorias_backup');
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
            $criteria->removeSelectColumn(PremiacaoTableMap::COL_PREMIACAO_ID);
            $criteria->removeSelectColumn(PremiacaoTableMap::COL_EVENTO_ID);
            $criteria->removeSelectColumn(PremiacaoTableMap::COL_NOME);
            $criteria->removeSelectColumn(PremiacaoTableMap::COL_STATUS);
            $criteria->removeSelectColumn(PremiacaoTableMap::COL_MODIFICADO);
            $criteria->removeSelectColumn(PremiacaoTableMap::COL_CATEGORIAS);
            $criteria->removeSelectColumn(PremiacaoTableMap::COL_CATEGORIAS_BACKUP);
        } else {
            $criteria->removeSelectColumn($alias . '.premiacao_id');
            $criteria->removeSelectColumn($alias . '.evento_id');
            $criteria->removeSelectColumn($alias . '.nome');
            $criteria->removeSelectColumn($alias . '.status');
            $criteria->removeSelectColumn($alias . '.modificado');
            $criteria->removeSelectColumn($alias . '.categorias');
            $criteria->removeSelectColumn($alias . '.categorias_backup');
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
        return Propel::getServiceContainer()->getDatabaseMap(PremiacaoTableMap::DATABASE_NAME)->getTable(PremiacaoTableMap::TABLE_NAME);
    }

    /**
     * Performs a DELETE on the database, given a Premiacao or Criteria object OR a primary key value.
     *
     * @param mixed $values Criteria or Premiacao object or primary key or array of primary keys
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
            $con = Propel::getServiceContainer()->getWriteConnection(PremiacaoTableMap::DATABASE_NAME);
        }

        if ($values instanceof Criteria) {
            // rename for clarity
            $criteria = $values;
        } elseif ($values instanceof \Baja\Model\Premiacao) { // it's a model object
            // create criteria based on pk values
            $criteria = $values->buildPkeyCriteria();
        } else { // it's a primary key, or an array of pks
            $criteria = new Criteria(PremiacaoTableMap::DATABASE_NAME);
            $criteria->add(PremiacaoTableMap::COL_PREMIACAO_ID, (array) $values, Criteria::IN);
        }

        $query = PremiacaoQuery::create()->mergeWith($criteria);

        if ($values instanceof Criteria) {
            PremiacaoTableMap::clearInstancePool();
        } elseif (!is_object($values)) { // it's a primary key, or an array of pks
            foreach ((array) $values as $singleval) {
                PremiacaoTableMap::removeInstanceFromPool($singleval);
            }
        }

        return $query->delete($con);
    }

    /**
     * Deletes all rows from the premiacao table.
     *
     * @param ConnectionInterface $con the connection to use
     * @return int The number of affected rows (if supported by underlying database driver).
     */
    public static function doDeleteAll(?ConnectionInterface $con = null): int
    {
        return PremiacaoQuery::create()->doDeleteAll($con);
    }

    /**
     * Performs an INSERT on the database, given a Premiacao or Criteria object.
     *
     * @param mixed $criteria Criteria or Premiacao object containing data that is used to create the INSERT statement.
     * @param ConnectionInterface $con the ConnectionInterface connection to use
     * @return mixed The new primary key.
     * @throws \Propel\Runtime\Exception\PropelException Any exceptions caught during processing will be
     *                         rethrown wrapped into a PropelException.
     */
    public static function doInsert($criteria, ?ConnectionInterface $con = null)
    {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(PremiacaoTableMap::DATABASE_NAME);
        }

        if ($criteria instanceof Criteria) {
            $criteria = clone $criteria; // rename for clarity
        } else {
            $criteria = $criteria->buildCriteria(); // build Criteria from Premiacao object
        }


        // Set the correct dbName
        $query = PremiacaoQuery::create()->mergeWith($criteria);

        // use transaction because $criteria could contain info
        // for more than one table (I guess, conceivably)
        return $con->transaction(function () use ($con, $query) {
            return $query->doInsert($con);
        });
    }

}
