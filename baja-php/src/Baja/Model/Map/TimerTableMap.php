<?php

namespace Baja\Model\Map;

use Baja\Model\Timer;
use Baja\Model\TimerQuery;
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
 * This class defines the structure of the 'timer' table.
 *
 *
 *
 * This map class is used by Propel to do runtime db structure discovery.
 * For example, the createSelectSql() method checks the type of a given column used in an
 * ORDER BY clause to know whether it needs to apply SQL to make the ORDER BY case-insensitive
 * (i.e. if it's a text column type).
 */
class TimerTableMap extends TableMap
{
    use InstancePoolTrait;
    use TableMapTrait;

    /**
     * The (dot-path) name of this class
     */
    public const CLASS_NAME = 'Baja.Model.Map.TimerTableMap';

    /**
     * The default database name for this class
     */
    public const DATABASE_NAME = 'resultados';

    /**
     * The table name for this class
     */
    public const TABLE_NAME = 'timer';

    /**
     * The PHP name of this class (PascalCase)
     */
    public const TABLE_PHP_NAME = 'Timer';

    /**
     * The related Propel class for this table
     */
    public const OM_CLASS = '\\Baja\\Model\\Timer';

    /**
     * A class that can be returned by this tableMap
     */
    public const CLASS_DEFAULT = 'Baja.Model.Timer';

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
     * the column name for the evento_id field
     */
    public const COL_EVENTO_ID = 'timer.evento_id';

    /**
     * the column name for the timer_id field
     */
    public const COL_TIMER_ID = 'timer.timer_id';

    /**
     * the column name for the config field
     */
    public const COL_CONFIG = 'timer.config';

    /**
     * the column name for the config_backup field
     */
    public const COL_CONFIG_BACKUP = 'timer.config_backup';

    /**
     * the column name for the state field
     */
    public const COL_STATE = 'timer.state';

    /**
     * the column name for the log field
     */
    public const COL_LOG = 'timer.log';

    /**
     * the column name for the access_key field
     */
    public const COL_ACCESS_KEY = 'timer.access_key';

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
        self::TYPE_PHPNAME       => ['EventoId', 'TimerId', 'Config', 'ConfigBackup', 'State', 'Log', 'AccessKey', ],
        self::TYPE_CAMELNAME     => ['eventoId', 'timerId', 'config', 'configBackup', 'state', 'log', 'accessKey', ],
        self::TYPE_COLNAME       => [TimerTableMap::COL_EVENTO_ID, TimerTableMap::COL_TIMER_ID, TimerTableMap::COL_CONFIG, TimerTableMap::COL_CONFIG_BACKUP, TimerTableMap::COL_STATE, TimerTableMap::COL_LOG, TimerTableMap::COL_ACCESS_KEY, ],
        self::TYPE_FIELDNAME     => ['evento_id', 'timer_id', 'config', 'config_backup', 'state', 'log', 'access_key', ],
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
        self::TYPE_PHPNAME       => ['EventoId' => 0, 'TimerId' => 1, 'Config' => 2, 'ConfigBackup' => 3, 'State' => 4, 'Log' => 5, 'AccessKey' => 6, ],
        self::TYPE_CAMELNAME     => ['eventoId' => 0, 'timerId' => 1, 'config' => 2, 'configBackup' => 3, 'state' => 4, 'log' => 5, 'accessKey' => 6, ],
        self::TYPE_COLNAME       => [TimerTableMap::COL_EVENTO_ID => 0, TimerTableMap::COL_TIMER_ID => 1, TimerTableMap::COL_CONFIG => 2, TimerTableMap::COL_CONFIG_BACKUP => 3, TimerTableMap::COL_STATE => 4, TimerTableMap::COL_LOG => 5, TimerTableMap::COL_ACCESS_KEY => 6, ],
        self::TYPE_FIELDNAME     => ['evento_id' => 0, 'timer_id' => 1, 'config' => 2, 'config_backup' => 3, 'state' => 4, 'log' => 5, 'access_key' => 6, ],
        self::TYPE_NUM           => [0, 1, 2, 3, 4, 5, 6, ]
    ];

    /**
     * Holds a list of column names and their normalized version.
     *
     * @var array<string>
     */
    protected $normalizedColumnNameMap = [
        'EventoId' => 'EVENTO_ID',
        'Timer.EventoId' => 'EVENTO_ID',
        'eventoId' => 'EVENTO_ID',
        'timer.eventoId' => 'EVENTO_ID',
        'TimerTableMap::COL_EVENTO_ID' => 'EVENTO_ID',
        'COL_EVENTO_ID' => 'EVENTO_ID',
        'evento_id' => 'EVENTO_ID',
        'timer.evento_id' => 'EVENTO_ID',
        'TimerId' => 'TIMER_ID',
        'Timer.TimerId' => 'TIMER_ID',
        'timerId' => 'TIMER_ID',
        'timer.timerId' => 'TIMER_ID',
        'TimerTableMap::COL_TIMER_ID' => 'TIMER_ID',
        'COL_TIMER_ID' => 'TIMER_ID',
        'timer_id' => 'TIMER_ID',
        'timer.timer_id' => 'TIMER_ID',
        'Config' => 'CONFIG',
        'Timer.Config' => 'CONFIG',
        'config' => 'CONFIG',
        'timer.config' => 'CONFIG',
        'TimerTableMap::COL_CONFIG' => 'CONFIG',
        'COL_CONFIG' => 'CONFIG',
        'ConfigBackup' => 'CONFIG_BACKUP',
        'Timer.ConfigBackup' => 'CONFIG_BACKUP',
        'configBackup' => 'CONFIG_BACKUP',
        'timer.configBackup' => 'CONFIG_BACKUP',
        'TimerTableMap::COL_CONFIG_BACKUP' => 'CONFIG_BACKUP',
        'COL_CONFIG_BACKUP' => 'CONFIG_BACKUP',
        'config_backup' => 'CONFIG_BACKUP',
        'timer.config_backup' => 'CONFIG_BACKUP',
        'State' => 'STATE',
        'Timer.State' => 'STATE',
        'state' => 'STATE',
        'timer.state' => 'STATE',
        'TimerTableMap::COL_STATE' => 'STATE',
        'COL_STATE' => 'STATE',
        'Log' => 'LOG',
        'Timer.Log' => 'LOG',
        'log' => 'LOG',
        'timer.log' => 'LOG',
        'TimerTableMap::COL_LOG' => 'LOG',
        'COL_LOG' => 'LOG',
        'AccessKey' => 'ACCESS_KEY',
        'Timer.AccessKey' => 'ACCESS_KEY',
        'accessKey' => 'ACCESS_KEY',
        'timer.accessKey' => 'ACCESS_KEY',
        'TimerTableMap::COL_ACCESS_KEY' => 'ACCESS_KEY',
        'COL_ACCESS_KEY' => 'ACCESS_KEY',
        'access_key' => 'ACCESS_KEY',
        'timer.access_key' => 'ACCESS_KEY',
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
        $this->setName('timer');
        $this->setPhpName('Timer');
        $this->setIdentifierQuoting(false);
        $this->setClassName('\\Baja\\Model\\Timer');
        $this->setPackage('Baja.Model');
        $this->setUseIdGenerator(false);
        // columns
        $this->addForeignPrimaryKey('evento_id', 'EventoId', 'CHAR' , 'evento', 'evento_id', true, 4, null);
        $this->addPrimaryKey('timer_id', 'TimerId', 'INTEGER', true, null, null);
        $this->addColumn('config', 'Config', 'LONGVARCHAR', false, null, null);
        $this->addColumn('config_backup', 'ConfigBackup', 'LONGVARCHAR', false, null, null);
        $this->addColumn('state', 'State', 'LONGVARCHAR', false, null, null);
        $this->addColumn('log', 'Log', 'LONGVARCHAR', false, null, null);
        $this->addColumn('access_key', 'AccessKey', 'CHAR', true, 6, null);
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
     * Adds an object to the instance pool.
     *
     * Propel keeps cached copies of objects in an instance pool when they are retrieved
     * from the database. In some cases you may need to explicitly add objects
     * to the cache in order to ensure that the same objects are always returned by find*()
     * and findPk*() calls.
     *
     * @param \Baja\Model\Timer $obj A \Baja\Model\Timer object.
     * @param string|null $key Key (optional) to use for instance map (for performance boost if key was already calculated externally).
     *
     * @return void
     */
    public static function addInstanceToPool(Timer $obj, ?string $key = null): void
    {
        if (Propel::isInstancePoolingEnabled()) {
            if (null === $key) {
                $key = serialize([(null === $obj->getEventoId() || is_scalar($obj->getEventoId()) || is_callable([$obj->getEventoId(), '__toString']) ? (string) $obj->getEventoId() : $obj->getEventoId()), (null === $obj->getTimerId() || is_scalar($obj->getTimerId()) || is_callable([$obj->getTimerId(), '__toString']) ? (string) $obj->getTimerId() : $obj->getTimerId())]);
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
     * @param mixed $value A \Baja\Model\Timer object or a primary key value.
     *
     * @return void
     */
    public static function removeInstanceFromPool($value): void
    {
        if (Propel::isInstancePoolingEnabled() && null !== $value) {
            if (is_object($value) && $value instanceof \Baja\Model\Timer) {
                $key = serialize([(null === $value->getEventoId() || is_scalar($value->getEventoId()) || is_callable([$value->getEventoId(), '__toString']) ? (string) $value->getEventoId() : $value->getEventoId()), (null === $value->getTimerId() || is_scalar($value->getTimerId()) || is_callable([$value->getTimerId(), '__toString']) ? (string) $value->getTimerId() : $value->getTimerId())]);

            } elseif (is_array($value) && count($value) === 2) {
                // assume we've been passed a primary key";
                $key = serialize([(null === $value[0] || is_scalar($value[0]) || is_callable([$value[0], '__toString']) ? (string) $value[0] : $value[0]), (null === $value[1] || is_scalar($value[1]) || is_callable([$value[1], '__toString']) ? (string) $value[1] : $value[1])]);
            } elseif ($value instanceof Criteria) {
                self::$instances = [];

                return;
            } else {
                $e = new PropelException("Invalid value passed to removeInstanceFromPool().  Expected primary key or \Baja\Model\Timer object; got " . (is_object($value) ? get_class($value) . ' object.' : var_export($value, true)));
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
        if ($row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('EventoId', TableMap::TYPE_PHPNAME, $indexType)] === null && $row[TableMap::TYPE_NUM == $indexType ? 1 + $offset : static::translateFieldName('TimerId', TableMap::TYPE_PHPNAME, $indexType)] === null) {
            return null;
        }

        return serialize([(null === $row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('EventoId', TableMap::TYPE_PHPNAME, $indexType)] || is_scalar($row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('EventoId', TableMap::TYPE_PHPNAME, $indexType)]) || is_callable([$row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('EventoId', TableMap::TYPE_PHPNAME, $indexType)], '__toString']) ? (string) $row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('EventoId', TableMap::TYPE_PHPNAME, $indexType)] : $row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('EventoId', TableMap::TYPE_PHPNAME, $indexType)]), (null === $row[TableMap::TYPE_NUM == $indexType ? 1 + $offset : static::translateFieldName('TimerId', TableMap::TYPE_PHPNAME, $indexType)] || is_scalar($row[TableMap::TYPE_NUM == $indexType ? 1 + $offset : static::translateFieldName('TimerId', TableMap::TYPE_PHPNAME, $indexType)]) || is_callable([$row[TableMap::TYPE_NUM == $indexType ? 1 + $offset : static::translateFieldName('TimerId', TableMap::TYPE_PHPNAME, $indexType)], '__toString']) ? (string) $row[TableMap::TYPE_NUM == $indexType ? 1 + $offset : static::translateFieldName('TimerId', TableMap::TYPE_PHPNAME, $indexType)] : $row[TableMap::TYPE_NUM == $indexType ? 1 + $offset : static::translateFieldName('TimerId', TableMap::TYPE_PHPNAME, $indexType)])]);
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
        $pks[] = (int) $row[
            $indexType == TableMap::TYPE_NUM
                ? 1 + $offset
                : self::translateFieldName('TimerId', TableMap::TYPE_PHPNAME, $indexType)
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
        return $withPrefix ? TimerTableMap::CLASS_DEFAULT : TimerTableMap::OM_CLASS;
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
     * @return array (Timer object, last column rank)
     */
    public static function populateObject(array $row, int $offset = 0, string $indexType = TableMap::TYPE_NUM): array
    {
        $key = TimerTableMap::getPrimaryKeyHashFromRow($row, $offset, $indexType);
        if (null !== ($obj = TimerTableMap::getInstanceFromPool($key))) {
            // We no longer rehydrate the object, since this can cause data loss.
            // See http://www.propelorm.org/ticket/509
            // $obj->hydrate($row, $offset, true); // rehydrate
            $col = $offset + TimerTableMap::NUM_HYDRATE_COLUMNS;
        } else {
            $cls = TimerTableMap::OM_CLASS;
            /** @var Timer $obj */
            $obj = new $cls();
            $col = $obj->hydrate($row, $offset, false, $indexType);
            TimerTableMap::addInstanceToPool($obj, $key);
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
            $key = TimerTableMap::getPrimaryKeyHashFromRow($row, 0, $dataFetcher->getIndexType());
            if (null !== ($obj = TimerTableMap::getInstanceFromPool($key))) {
                // We no longer rehydrate the object, since this can cause data loss.
                // See http://www.propelorm.org/ticket/509
                // $obj->hydrate($row, 0, true); // rehydrate
                $results[] = $obj;
            } else {
                /** @var Timer $obj */
                $obj = new $cls();
                $obj->hydrate($row);
                $results[] = $obj;
                TimerTableMap::addInstanceToPool($obj, $key);
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
            $criteria->addSelectColumn(TimerTableMap::COL_EVENTO_ID);
            $criteria->addSelectColumn(TimerTableMap::COL_TIMER_ID);
            $criteria->addSelectColumn(TimerTableMap::COL_CONFIG);
            $criteria->addSelectColumn(TimerTableMap::COL_CONFIG_BACKUP);
            $criteria->addSelectColumn(TimerTableMap::COL_STATE);
            $criteria->addSelectColumn(TimerTableMap::COL_LOG);
            $criteria->addSelectColumn(TimerTableMap::COL_ACCESS_KEY);
        } else {
            $criteria->addSelectColumn($alias . '.evento_id');
            $criteria->addSelectColumn($alias . '.timer_id');
            $criteria->addSelectColumn($alias . '.config');
            $criteria->addSelectColumn($alias . '.config_backup');
            $criteria->addSelectColumn($alias . '.state');
            $criteria->addSelectColumn($alias . '.log');
            $criteria->addSelectColumn($alias . '.access_key');
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
            $criteria->removeSelectColumn(TimerTableMap::COL_EVENTO_ID);
            $criteria->removeSelectColumn(TimerTableMap::COL_TIMER_ID);
            $criteria->removeSelectColumn(TimerTableMap::COL_CONFIG);
            $criteria->removeSelectColumn(TimerTableMap::COL_CONFIG_BACKUP);
            $criteria->removeSelectColumn(TimerTableMap::COL_STATE);
            $criteria->removeSelectColumn(TimerTableMap::COL_LOG);
            $criteria->removeSelectColumn(TimerTableMap::COL_ACCESS_KEY);
        } else {
            $criteria->removeSelectColumn($alias . '.evento_id');
            $criteria->removeSelectColumn($alias . '.timer_id');
            $criteria->removeSelectColumn($alias . '.config');
            $criteria->removeSelectColumn($alias . '.config_backup');
            $criteria->removeSelectColumn($alias . '.state');
            $criteria->removeSelectColumn($alias . '.log');
            $criteria->removeSelectColumn($alias . '.access_key');
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
        return Propel::getServiceContainer()->getDatabaseMap(TimerTableMap::DATABASE_NAME)->getTable(TimerTableMap::TABLE_NAME);
    }

    /**
     * Performs a DELETE on the database, given a Timer or Criteria object OR a primary key value.
     *
     * @param mixed $values Criteria or Timer object or primary key or array of primary keys
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
            $con = Propel::getServiceContainer()->getWriteConnection(TimerTableMap::DATABASE_NAME);
        }

        if ($values instanceof Criteria) {
            // rename for clarity
            $criteria = $values;
        } elseif ($values instanceof \Baja\Model\Timer) { // it's a model object
            // create criteria based on pk values
            $criteria = $values->buildPkeyCriteria();
        } else { // it's a primary key, or an array of pks
            $criteria = new Criteria(TimerTableMap::DATABASE_NAME);
            // primary key is composite; we therefore, expect
            // the primary key passed to be an array of pkey values
            if (count($values) == count($values, COUNT_RECURSIVE)) {
                // array is not multi-dimensional
                $values = [$values];
            }
            foreach ($values as $value) {
                $criterion = $criteria->getNewCriterion(TimerTableMap::COL_EVENTO_ID, $value[0]);
                $criterion->addAnd($criteria->getNewCriterion(TimerTableMap::COL_TIMER_ID, $value[1]));
                $criteria->addOr($criterion);
            }
        }

        $query = TimerQuery::create()->mergeWith($criteria);

        if ($values instanceof Criteria) {
            TimerTableMap::clearInstancePool();
        } elseif (!is_object($values)) { // it's a primary key, or an array of pks
            foreach ((array) $values as $singleval) {
                TimerTableMap::removeInstanceFromPool($singleval);
            }
        }

        return $query->delete($con);
    }

    /**
     * Deletes all rows from the timer table.
     *
     * @param ConnectionInterface $con the connection to use
     * @return int The number of affected rows (if supported by underlying database driver).
     */
    public static function doDeleteAll(?ConnectionInterface $con = null): int
    {
        return TimerQuery::create()->doDeleteAll($con);
    }

    /**
     * Performs an INSERT on the database, given a Timer or Criteria object.
     *
     * @param mixed $criteria Criteria or Timer object containing data that is used to create the INSERT statement.
     * @param ConnectionInterface $con the ConnectionInterface connection to use
     * @return mixed The new primary key.
     * @throws \Propel\Runtime\Exception\PropelException Any exceptions caught during processing will be
     *                         rethrown wrapped into a PropelException.
     */
    public static function doInsert($criteria, ?ConnectionInterface $con = null)
    {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(TimerTableMap::DATABASE_NAME);
        }

        if ($criteria instanceof Criteria) {
            $criteria = clone $criteria; // rename for clarity
        } else {
            $criteria = $criteria->buildCriteria(); // build Criteria from Timer object
        }


        // Set the correct dbName
        $query = TimerQuery::create()->mergeWith($criteria);

        // use transaction because $criteria could contain info
        // for more than one table (I guess, conceivably)
        return $con->transaction(function () use ($con, $query) {
            return $query->doInsert($con);
        });
    }

}
