<?php

namespace Baja\Model\Map;

use Baja\Model\Programa;
use Baja\Model\ProgramaQuery;
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
 * This class defines the structure of the 'programa' table.
 *
 *
 *
 * This map class is used by Propel to do runtime db structure discovery.
 * For example, the createSelectSql() method checks the type of a given column used in an
 * ORDER BY clause to know whether it needs to apply SQL to make the ORDER BY case-insensitive
 * (i.e. if it's a text column type).
 */
class ProgramaTableMap extends TableMap
{
    use InstancePoolTrait;
    use TableMapTrait;

    /**
     * The (dot-path) name of this class
     */
    public const CLASS_NAME = 'Baja.Model.Map.ProgramaTableMap';

    /**
     * The default database name for this class
     */
    public const DATABASE_NAME = 'resultados';

    /**
     * The table name for this class
     */
    public const TABLE_NAME = 'programa';

    /**
     * The PHP name of this class (PascalCase)
     */
    public const TABLE_PHP_NAME = 'Programa';

    /**
     * The related Propel class for this table
     */
    public const OM_CLASS = '\\Baja\\Model\\Programa';

    /**
     * A class that can be returned by this tableMap
     */
    public const CLASS_DEFAULT = 'Baja.Model.Programa';

    /**
     * The total number of columns
     */
    public const NUM_COLUMNS = 5;

    /**
     * The number of lazy-loaded columns
     */
    public const NUM_LAZY_LOAD_COLUMNS = 0;

    /**
     * The number of columns to hydrate (NUM_COLUMNS - NUM_LAZY_LOAD_COLUMNS)
     */
    public const NUM_HYDRATE_COLUMNS = 5;

    /**
     * the column name for the programa_id field
     */
    public const COL_PROGRAMA_ID = 'programa.programa_id';

    /**
     * the column name for the parent_id field
     */
    public const COL_PARENT_ID = 'programa.parent_id';

    /**
     * the column name for the nome field
     */
    public const COL_NOME = 'programa.nome';

    /**
     * the column name for the ordem field
     */
    public const COL_ORDEM = 'programa.ordem';

    /**
     * the column name for the ativo field
     */
    public const COL_ATIVO = 'programa.ativo';

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
        self::TYPE_PHPNAME       => ['ProgramaId', 'ParentId', 'Nome', 'Ordem', 'Ativo', ],
        self::TYPE_CAMELNAME     => ['programaId', 'parentId', 'nome', 'ordem', 'ativo', ],
        self::TYPE_COLNAME       => [ProgramaTableMap::COL_PROGRAMA_ID, ProgramaTableMap::COL_PARENT_ID, ProgramaTableMap::COL_NOME, ProgramaTableMap::COL_ORDEM, ProgramaTableMap::COL_ATIVO, ],
        self::TYPE_FIELDNAME     => ['programa_id', 'parent_id', 'nome', 'ordem', 'ativo', ],
        self::TYPE_NUM           => [0, 1, 2, 3, 4, ]
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
        self::TYPE_PHPNAME       => ['ProgramaId' => 0, 'ParentId' => 1, 'Nome' => 2, 'Ordem' => 3, 'Ativo' => 4, ],
        self::TYPE_CAMELNAME     => ['programaId' => 0, 'parentId' => 1, 'nome' => 2, 'ordem' => 3, 'ativo' => 4, ],
        self::TYPE_COLNAME       => [ProgramaTableMap::COL_PROGRAMA_ID => 0, ProgramaTableMap::COL_PARENT_ID => 1, ProgramaTableMap::COL_NOME => 2, ProgramaTableMap::COL_ORDEM => 3, ProgramaTableMap::COL_ATIVO => 4, ],
        self::TYPE_FIELDNAME     => ['programa_id' => 0, 'parent_id' => 1, 'nome' => 2, 'ordem' => 3, 'ativo' => 4, ],
        self::TYPE_NUM           => [0, 1, 2, 3, 4, ]
    ];

    /**
     * Holds a list of column names and their normalized version.
     *
     * @var array<string>
     */
    protected $normalizedColumnNameMap = [
        'ProgramaId' => 'PROGRAMA_ID',
        'Programa.ProgramaId' => 'PROGRAMA_ID',
        'programaId' => 'PROGRAMA_ID',
        'programa.programaId' => 'PROGRAMA_ID',
        'ProgramaTableMap::COL_PROGRAMA_ID' => 'PROGRAMA_ID',
        'COL_PROGRAMA_ID' => 'PROGRAMA_ID',
        'programa_id' => 'PROGRAMA_ID',
        'programa.programa_id' => 'PROGRAMA_ID',
        'ParentId' => 'PARENT_ID',
        'Programa.ParentId' => 'PARENT_ID',
        'parentId' => 'PARENT_ID',
        'programa.parentId' => 'PARENT_ID',
        'ProgramaTableMap::COL_PARENT_ID' => 'PARENT_ID',
        'COL_PARENT_ID' => 'PARENT_ID',
        'parent_id' => 'PARENT_ID',
        'programa.parent_id' => 'PARENT_ID',
        'Nome' => 'NOME',
        'Programa.Nome' => 'NOME',
        'nome' => 'NOME',
        'programa.nome' => 'NOME',
        'ProgramaTableMap::COL_NOME' => 'NOME',
        'COL_NOME' => 'NOME',
        'Ordem' => 'ORDEM',
        'Programa.Ordem' => 'ORDEM',
        'ordem' => 'ORDEM',
        'programa.ordem' => 'ORDEM',
        'ProgramaTableMap::COL_ORDEM' => 'ORDEM',
        'COL_ORDEM' => 'ORDEM',
        'Ativo' => 'ATIVO',
        'Programa.Ativo' => 'ATIVO',
        'ativo' => 'ATIVO',
        'programa.ativo' => 'ATIVO',
        'ProgramaTableMap::COL_ATIVO' => 'ATIVO',
        'COL_ATIVO' => 'ATIVO',
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
        $this->setName('programa');
        $this->setPhpName('Programa');
        $this->setIdentifierQuoting(false);
        $this->setClassName('\\Baja\\Model\\Programa');
        $this->setPackage('Baja.Model');
        $this->setUseIdGenerator(false);
        // columns
        $this->addPrimaryKey('programa_id', 'ProgramaId', 'VARCHAR', true, 16, null);
        $this->addForeignKey('parent_id', 'ParentId', 'VARCHAR', 'programa', 'programa_id', false, 16, null);
        $this->addColumn('nome', 'Nome', 'VARCHAR', true, 100, null);
        $this->addColumn('ordem', 'Ordem', 'INTEGER', true, null, 0);
        $this->addColumn('ativo', 'Ativo', 'BOOLEAN', true, 1, true);
    }

    /**
     * Build the RelationMap objects for this table relationships
     *
     * @return void
     */
    public function buildRelations(): void
    {
        $this->addRelation('Parent', '\\Baja\\Model\\Programa', RelationMap::MANY_TO_ONE, array (
  0 =>
  array (
    0 => ':parent_id',
    1 => ':programa_id',
  ),
), 'RESTRICT', 'CASCADE', null, false);
        $this->addRelation('Evento', '\\Baja\\Model\\Evento', RelationMap::ONE_TO_MANY, array (
  0 =>
  array (
    0 => ':programa_id',
    1 => ':programa_id',
  ),
), 'RESTRICT', 'CASCADE', 'Eventos', false);
        $this->addRelation('Child', '\\Baja\\Model\\Programa', RelationMap::ONE_TO_MANY, array (
  0 =>
  array (
    0 => ':parent_id',
    1 => ':programa_id',
  ),
), 'RESTRICT', 'CASCADE', 'Children', false);
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
        if ($row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('ProgramaId', TableMap::TYPE_PHPNAME, $indexType)] === null) {
            return null;
        }

        return null === $row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('ProgramaId', TableMap::TYPE_PHPNAME, $indexType)] || is_scalar($row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('ProgramaId', TableMap::TYPE_PHPNAME, $indexType)]) || is_callable([$row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('ProgramaId', TableMap::TYPE_PHPNAME, $indexType)], '__toString']) ? (string) $row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('ProgramaId', TableMap::TYPE_PHPNAME, $indexType)] : $row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('ProgramaId', TableMap::TYPE_PHPNAME, $indexType)];
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
                : self::translateFieldName('ProgramaId', TableMap::TYPE_PHPNAME, $indexType)
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
        return $withPrefix ? ProgramaTableMap::CLASS_DEFAULT : ProgramaTableMap::OM_CLASS;
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
     * @return array (Programa object, last column rank)
     */
    public static function populateObject(array $row, int $offset = 0, string $indexType = TableMap::TYPE_NUM): array
    {
        $key = ProgramaTableMap::getPrimaryKeyHashFromRow($row, $offset, $indexType);
        if (null !== ($obj = ProgramaTableMap::getInstanceFromPool($key))) {
            // We no longer rehydrate the object, since this can cause data loss.
            // See http://www.propelorm.org/ticket/509
            // $obj->hydrate($row, $offset, true); // rehydrate
            $col = $offset + ProgramaTableMap::NUM_HYDRATE_COLUMNS;
        } else {
            $cls = ProgramaTableMap::OM_CLASS;
            /** @var Programa $obj */
            $obj = new $cls();
            $col = $obj->hydrate($row, $offset, false, $indexType);
            ProgramaTableMap::addInstanceToPool($obj, $key);
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
            $key = ProgramaTableMap::getPrimaryKeyHashFromRow($row, 0, $dataFetcher->getIndexType());
            if (null !== ($obj = ProgramaTableMap::getInstanceFromPool($key))) {
                // We no longer rehydrate the object, since this can cause data loss.
                // See http://www.propelorm.org/ticket/509
                // $obj->hydrate($row, 0, true); // rehydrate
                $results[] = $obj;
            } else {
                /** @var Programa $obj */
                $obj = new $cls();
                $obj->hydrate($row);
                $results[] = $obj;
                ProgramaTableMap::addInstanceToPool($obj, $key);
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
            $criteria->addSelectColumn(ProgramaTableMap::COL_PROGRAMA_ID);
            $criteria->addSelectColumn(ProgramaTableMap::COL_PARENT_ID);
            $criteria->addSelectColumn(ProgramaTableMap::COL_NOME);
            $criteria->addSelectColumn(ProgramaTableMap::COL_ORDEM);
            $criteria->addSelectColumn(ProgramaTableMap::COL_ATIVO);
        } else {
            $criteria->addSelectColumn($alias . '.programa_id');
            $criteria->addSelectColumn($alias . '.parent_id');
            $criteria->addSelectColumn($alias . '.nome');
            $criteria->addSelectColumn($alias . '.ordem');
            $criteria->addSelectColumn($alias . '.ativo');
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
            $criteria->removeSelectColumn(ProgramaTableMap::COL_PROGRAMA_ID);
            $criteria->removeSelectColumn(ProgramaTableMap::COL_PARENT_ID);
            $criteria->removeSelectColumn(ProgramaTableMap::COL_NOME);
            $criteria->removeSelectColumn(ProgramaTableMap::COL_ORDEM);
            $criteria->removeSelectColumn(ProgramaTableMap::COL_ATIVO);
        } else {
            $criteria->removeSelectColumn($alias . '.programa_id');
            $criteria->removeSelectColumn($alias . '.parent_id');
            $criteria->removeSelectColumn($alias . '.nome');
            $criteria->removeSelectColumn($alias . '.ordem');
            $criteria->removeSelectColumn($alias . '.ativo');
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
        return Propel::getServiceContainer()->getDatabaseMap(ProgramaTableMap::DATABASE_NAME)->getTable(ProgramaTableMap::TABLE_NAME);
    }

    /**
     * Performs a DELETE on the database, given a Programa or Criteria object OR a primary key value.
     *
     * @param mixed $values Criteria or Programa object or primary key or array of primary keys
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
            $con = Propel::getServiceContainer()->getWriteConnection(ProgramaTableMap::DATABASE_NAME);
        }

        if ($values instanceof Criteria) {
            // rename for clarity
            $criteria = $values;
        } elseif ($values instanceof \Baja\Model\Programa) { // it's a model object
            // create criteria based on pk values
            $criteria = $values->buildPkeyCriteria();
        } else { // it's a primary key, or an array of pks
            $criteria = new Criteria(ProgramaTableMap::DATABASE_NAME);
            $criteria->add(ProgramaTableMap::COL_PROGRAMA_ID, (array) $values, Criteria::IN);
        }

        $query = ProgramaQuery::create()->mergeWith($criteria);

        if ($values instanceof Criteria) {
            ProgramaTableMap::clearInstancePool();
        } elseif (!is_object($values)) { // it's a primary key, or an array of pks
            foreach ((array) $values as $singleval) {
                ProgramaTableMap::removeInstanceFromPool($singleval);
            }
        }

        return $query->delete($con);
    }

    /**
     * Deletes all rows from the programa table.
     *
     * @param ConnectionInterface $con the connection to use
     * @return int The number of affected rows (if supported by underlying database driver).
     */
    public static function doDeleteAll(?ConnectionInterface $con = null): int
    {
        return ProgramaQuery::create()->doDeleteAll($con);
    }

    /**
     * Performs an INSERT on the database, given a Programa or Criteria object.
     *
     * @param mixed $criteria Criteria or Programa object containing data that is used to create the INSERT statement.
     * @param ConnectionInterface $con the ConnectionInterface connection to use
     * @return mixed The new primary key.
     * @throws \Propel\Runtime\Exception\PropelException Any exceptions caught during processing will be
     *                         rethrown wrapped into a PropelException.
     */
    public static function doInsert($criteria, ?ConnectionInterface $con = null)
    {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(ProgramaTableMap::DATABASE_NAME);
        }

        if ($criteria instanceof Criteria) {
            $criteria = clone $criteria; // rename for clarity
        } else {
            $criteria = $criteria->buildCriteria(); // build Criteria from Programa object
        }


        // Set the correct dbName
        $query = ProgramaQuery::create()->mergeWith($criteria);

        // use transaction because $criteria could contain info
        // for more than one table (I guess, conceivably)
        return $con->transaction(function () use ($con, $query) {
            return $query->doInsert($con);
        });
    }

}
