<?php

namespace Baja\Model\Map;

use Baja\Model\Participante;
use Baja\Model\ParticipanteQuery;
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
 * This class defines the structure of the 'participantes' table.
 *
 *
 *
 * This map class is used by Propel to do runtime db structure discovery.
 * For example, the createSelectSql() method checks the type of a given column used in an
 * ORDER BY clause to know whether it needs to apply SQL to make the ORDER BY case-insensitive
 * (i.e. if it's a text column type).
 */
class ParticipanteTableMap extends TableMap
{
    use InstancePoolTrait;
    use TableMapTrait;

    /**
     * The (dot-path) name of this class
     */
    const CLASS_NAME = 'Baja.Model.Map.ParticipanteTableMap';

    /**
     * The default database name for this class
     */
    const DATABASE_NAME = 'resultados';

    /**
     * The table name for this class
     */
    const TABLE_NAME = 'participantes';

    /**
     * The related Propel class for this table
     */
    const OM_CLASS = '\\Baja\\Model\\Participante';

    /**
     * A class that can be returned by this tableMap
     */
    const CLASS_DEFAULT = 'Baja.Model.Participante';

    /**
     * The total number of columns
     */
    const NUM_COLUMNS = 5;

    /**
     * The number of lazy-loaded columns
     */
    const NUM_LAZY_LOAD_COLUMNS = 0;

    /**
     * The number of columns to hydrate (NUM_COLUMNS - NUM_LAZY_LOAD_COLUMNS)
     */
    const NUM_HYDRATE_COLUMNS = 5;

    /**
     * the column name for the idparticipantes field
     */
    const COL_IDPARTICIPANTES = 'participantes.idparticipantes';

    /**
     * the column name for the nome field
     */
    const COL_NOME = 'participantes.nome';

    /**
     * the column name for the funcao field
     */
    const COL_FUNCAO = 'participantes.funcao';

    /**
     * the column name for the cpf field
     */
    const COL_CPF = 'participantes.cpf';

    /**
     * the column name for the evento field
     */
    const COL_EVENTO = 'participantes.evento';

    /**
     * The default string format for model objects of the related table
     */
    const DEFAULT_STRING_FORMAT = 'YAML';

    /**
     * holds an array of fieldnames
     *
     * first dimension keys are the type constants
     * e.g. self::$fieldNames[self::TYPE_PHPNAME][0] = 'Id'
     */
    protected static $fieldNames = array (
        self::TYPE_PHPNAME       => array('ParticipanteId', 'Nome', 'Funcao', 'Cpf', 'EventoId', ),
        self::TYPE_CAMELNAME     => array('participanteId', 'nome', 'funcao', 'cpf', 'eventoId', ),
        self::TYPE_COLNAME       => array(ParticipanteTableMap::COL_IDPARTICIPANTES, ParticipanteTableMap::COL_NOME, ParticipanteTableMap::COL_FUNCAO, ParticipanteTableMap::COL_CPF, ParticipanteTableMap::COL_EVENTO, ),
        self::TYPE_FIELDNAME     => array('idparticipantes', 'nome', 'funcao', 'cpf', 'evento', ),
        self::TYPE_NUM           => array(0, 1, 2, 3, 4, )
    );

    /**
     * holds an array of keys for quick access to the fieldnames array
     *
     * first dimension keys are the type constants
     * e.g. self::$fieldKeys[self::TYPE_PHPNAME]['Id'] = 0
     */
    protected static $fieldKeys = array (
        self::TYPE_PHPNAME       => array('ParticipanteId' => 0, 'Nome' => 1, 'Funcao' => 2, 'Cpf' => 3, 'EventoId' => 4, ),
        self::TYPE_CAMELNAME     => array('participanteId' => 0, 'nome' => 1, 'funcao' => 2, 'cpf' => 3, 'eventoId' => 4, ),
        self::TYPE_COLNAME       => array(ParticipanteTableMap::COL_IDPARTICIPANTES => 0, ParticipanteTableMap::COL_NOME => 1, ParticipanteTableMap::COL_FUNCAO => 2, ParticipanteTableMap::COL_CPF => 3, ParticipanteTableMap::COL_EVENTO => 4, ),
        self::TYPE_FIELDNAME     => array('idparticipantes' => 0, 'nome' => 1, 'funcao' => 2, 'cpf' => 3, 'evento' => 4, ),
        self::TYPE_NUM           => array(0, 1, 2, 3, 4, )
    );

    /**
     * Initialize the table attributes and columns
     * Relations are not initialized by this method since they are lazy loaded
     *
     * @return void
     * @throws PropelException
     */
    public function initialize()
    {
        // attributes
        $this->setName('participantes');
        $this->setPhpName('Participante');
        $this->setIdentifierQuoting(false);
        $this->setClassName('\\Baja\\Model\\Participante');
        $this->setPackage('Baja.Model');
        $this->setUseIdGenerator(true);
        // columns
        $this->addPrimaryKey('idparticipantes', 'ParticipanteId', 'INTEGER', true, null, null);
        $this->addColumn('nome', 'Nome', 'VARCHAR', false, 300, null);
        $this->addColumn('funcao', 'Funcao', 'VARCHAR', false, 45, null);
        $this->addColumn('cpf', 'Cpf', 'BIGINT', false, 11, null);
        $this->addForeignPrimaryKey('evento', 'EventoId', 'CHAR' , 'evento', 'evento_id', true, 4, null);
    } // initialize()

    /**
     * Build the RelationMap objects for this table relationships
     */
    public function buildRelations()
    {
        $this->addRelation('Evento', '\\Baja\\Model\\Evento', RelationMap::MANY_TO_ONE, array (
  0 =>
  array (
    0 => ':evento',
    1 => ':evento_id',
  ),
), 'CASCADE', 'CASCADE', null, false);
    } // buildRelations()

    /**
     * Adds an object to the instance pool.
     *
     * Propel keeps cached copies of objects in an instance pool when they are retrieved
     * from the database. In some cases you may need to explicitly add objects
     * to the cache in order to ensure that the same objects are always returned by find*()
     * and findPk*() calls.
     *
     * @param \Baja\Model\Participante $obj A \Baja\Model\Participante object.
     * @param string $key             (optional) key to use for instance map (for performance boost if key was already calculated externally).
     */
    public static function addInstanceToPool($obj, $key = null)
    {
        if (Propel::isInstancePoolingEnabled()) {
            if (null === $key) {
                $key = serialize([(null === $obj->getParticipanteId() || is_scalar($obj->getParticipanteId()) || is_callable([$obj->getParticipanteId(), '__toString']) ? (string) $obj->getParticipanteId() : $obj->getParticipanteId()), (null === $obj->getEventoId() || is_scalar($obj->getEventoId()) || is_callable([$obj->getEventoId(), '__toString']) ? (string) $obj->getEventoId() : $obj->getEventoId())]);
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
     * @param mixed $value A \Baja\Model\Participante object or a primary key value.
     */
    public static function removeInstanceFromPool($value)
    {
        if (Propel::isInstancePoolingEnabled() && null !== $value) {
            if (is_object($value) && $value instanceof \Baja\Model\Participante) {
                $key = serialize([(null === $value->getParticipanteId() || is_scalar($value->getParticipanteId()) || is_callable([$value->getParticipanteId(), '__toString']) ? (string) $value->getParticipanteId() : $value->getParticipanteId()), (null === $value->getEventoId() || is_scalar($value->getEventoId()) || is_callable([$value->getEventoId(), '__toString']) ? (string) $value->getEventoId() : $value->getEventoId())]);

            } elseif (is_array($value) && count($value) === 2) {
                // assume we've been passed a primary key";
                $key = serialize([(null === $value[0] || is_scalar($value[0]) || is_callable([$value[0], '__toString']) ? (string) $value[0] : $value[0]), (null === $value[1] || is_scalar($value[1]) || is_callable([$value[1], '__toString']) ? (string) $value[1] : $value[1])]);
            } elseif ($value instanceof Criteria) {
                self::$instances = [];

                return;
            } else {
                $e = new PropelException("Invalid value passed to removeInstanceFromPool().  Expected primary key or \Baja\Model\Participante object; got " . (is_object($value) ? get_class($value) . ' object.' : var_export($value, true)));
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
     * @param array  $row       resultset row.
     * @param int    $offset    The 0-based offset for reading from the resultset row.
     * @param string $indexType One of the class type constants TableMap::TYPE_PHPNAME, TableMap::TYPE_CAMELNAME
     *                           TableMap::TYPE_COLNAME, TableMap::TYPE_FIELDNAME, TableMap::TYPE_NUM
     *
     * @return string The primary key hash of the row
     */
    public static function getPrimaryKeyHashFromRow($row, $offset = 0, $indexType = TableMap::TYPE_NUM)
    {
        // If the PK cannot be derived from the row, return NULL.
        if ($row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('ParticipanteId', TableMap::TYPE_PHPNAME, $indexType)] === null && $row[TableMap::TYPE_NUM == $indexType ? 4 + $offset : static::translateFieldName('EventoId', TableMap::TYPE_PHPNAME, $indexType)] === null) {
            return null;
        }

        return serialize([(null === $row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('ParticipanteId', TableMap::TYPE_PHPNAME, $indexType)] || is_scalar($row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('ParticipanteId', TableMap::TYPE_PHPNAME, $indexType)]) || is_callable([$row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('ParticipanteId', TableMap::TYPE_PHPNAME, $indexType)], '__toString']) ? (string) $row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('ParticipanteId', TableMap::TYPE_PHPNAME, $indexType)] : $row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('ParticipanteId', TableMap::TYPE_PHPNAME, $indexType)]), (null === $row[TableMap::TYPE_NUM == $indexType ? 4 + $offset : static::translateFieldName('EventoId', TableMap::TYPE_PHPNAME, $indexType)] || is_scalar($row[TableMap::TYPE_NUM == $indexType ? 4 + $offset : static::translateFieldName('EventoId', TableMap::TYPE_PHPNAME, $indexType)]) || is_callable([$row[TableMap::TYPE_NUM == $indexType ? 4 + $offset : static::translateFieldName('EventoId', TableMap::TYPE_PHPNAME, $indexType)], '__toString']) ? (string) $row[TableMap::TYPE_NUM == $indexType ? 4 + $offset : static::translateFieldName('EventoId', TableMap::TYPE_PHPNAME, $indexType)] : $row[TableMap::TYPE_NUM == $indexType ? 4 + $offset : static::translateFieldName('EventoId', TableMap::TYPE_PHPNAME, $indexType)])]);
    }

    /**
     * Retrieves the primary key from the DB resultset row
     * For tables with a single-column primary key, that simple pkey value will be returned.  For tables with
     * a multi-column primary key, an array of the primary key columns will be returned.
     *
     * @param array  $row       resultset row.
     * @param int    $offset    The 0-based offset for reading from the resultset row.
     * @param string $indexType One of the class type constants TableMap::TYPE_PHPNAME, TableMap::TYPE_CAMELNAME
     *                           TableMap::TYPE_COLNAME, TableMap::TYPE_FIELDNAME, TableMap::TYPE_NUM
     *
     * @return mixed The primary key of the row
     */
    public static function getPrimaryKeyFromRow($row, $offset = 0, $indexType = TableMap::TYPE_NUM)
    {
            $pks = [];

        $pks[] = (int) $row[
            $indexType == TableMap::TYPE_NUM
                ? 0 + $offset
                : self::translateFieldName('ParticipanteId', TableMap::TYPE_PHPNAME, $indexType)
        ];
        $pks[] = (string) $row[
            $indexType == TableMap::TYPE_NUM
                ? 4 + $offset
                : self::translateFieldName('EventoId', TableMap::TYPE_PHPNAME, $indexType)
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
     * @param boolean $withPrefix Whether or not to return the path with the class name
     * @return string path.to.ClassName
     */
    public static function getOMClass($withPrefix = true)
    {
        return $withPrefix ? ParticipanteTableMap::CLASS_DEFAULT : ParticipanteTableMap::OM_CLASS;
    }

    /**
     * Populates an object of the default type or an object that inherit from the default.
     *
     * @param array  $row       row returned by DataFetcher->fetch().
     * @param int    $offset    The 0-based offset for reading from the resultset row.
     * @param string $indexType The index type of $row. Mostly DataFetcher->getIndexType().
                                 One of the class type constants TableMap::TYPE_PHPNAME, TableMap::TYPE_CAMELNAME
     *                           TableMap::TYPE_COLNAME, TableMap::TYPE_FIELDNAME, TableMap::TYPE_NUM.
     *
     * @throws PropelException Any exceptions caught during processing will be
     *                         rethrown wrapped into a PropelException.
     * @return array           (Participante object, last column rank)
     */
    public static function populateObject($row, $offset = 0, $indexType = TableMap::TYPE_NUM)
    {
        $key = ParticipanteTableMap::getPrimaryKeyHashFromRow($row, $offset, $indexType);
        if (null !== ($obj = ParticipanteTableMap::getInstanceFromPool($key))) {
            // We no longer rehydrate the object, since this can cause data loss.
            // See http://www.propelorm.org/ticket/509
            // $obj->hydrate($row, $offset, true); // rehydrate
            $col = $offset + ParticipanteTableMap::NUM_HYDRATE_COLUMNS;
        } else {
            $cls = ParticipanteTableMap::OM_CLASS;
            /** @var Participante $obj */
            $obj = new $cls();
            $col = $obj->hydrate($row, $offset, false, $indexType);
            ParticipanteTableMap::addInstanceToPool($obj, $key);
        }

        return array($obj, $col);
    }

    /**
     * The returned array will contain objects of the default type or
     * objects that inherit from the default.
     *
     * @param DataFetcherInterface $dataFetcher
     * @return array
     * @throws PropelException Any exceptions caught during processing will be
     *                         rethrown wrapped into a PropelException.
     */
    public static function populateObjects(DataFetcherInterface $dataFetcher)
    {
        $results = array();

        // set the class once to avoid overhead in the loop
        $cls = static::getOMClass(false);
        // populate the object(s)
        while ($row = $dataFetcher->fetch()) {
            $key = ParticipanteTableMap::getPrimaryKeyHashFromRow($row, 0, $dataFetcher->getIndexType());
            if (null !== ($obj = ParticipanteTableMap::getInstanceFromPool($key))) {
                // We no longer rehydrate the object, since this can cause data loss.
                // See http://www.propelorm.org/ticket/509
                // $obj->hydrate($row, 0, true); // rehydrate
                $results[] = $obj;
            } else {
                /** @var Participante $obj */
                $obj = new $cls();
                $obj->hydrate($row);
                $results[] = $obj;
                ParticipanteTableMap::addInstanceToPool($obj, $key);
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
     * @param Criteria $criteria object containing the columns to add.
     * @param string   $alias    optional table alias
     * @throws PropelException Any exceptions caught during processing will be
     *                         rethrown wrapped into a PropelException.
     */
    public static function addSelectColumns(Criteria $criteria, $alias = null)
    {
        if (null === $alias) {
            $criteria->addSelectColumn(ParticipanteTableMap::COL_IDPARTICIPANTES);
            $criteria->addSelectColumn(ParticipanteTableMap::COL_NOME);
            $criteria->addSelectColumn(ParticipanteTableMap::COL_FUNCAO);
            $criteria->addSelectColumn(ParticipanteTableMap::COL_CPF);
            $criteria->addSelectColumn(ParticipanteTableMap::COL_EVENTO);
        } else {
            $criteria->addSelectColumn($alias . '.idparticipantes');
            $criteria->addSelectColumn($alias . '.nome');
            $criteria->addSelectColumn($alias . '.funcao');
            $criteria->addSelectColumn($alias . '.cpf');
            $criteria->addSelectColumn($alias . '.evento');
        }
    }

    /**
     * Returns the TableMap related to this object.
     * This method is not needed for general use but a specific application could have a need.
     * @return TableMap
     * @throws PropelException Any exceptions caught during processing will be
     *                         rethrown wrapped into a PropelException.
     */
    public static function getTableMap()
    {
        return Propel::getServiceContainer()->getDatabaseMap(ParticipanteTableMap::DATABASE_NAME)->getTable(ParticipanteTableMap::TABLE_NAME);
    }

    /**
     * Add a TableMap instance to the database for this tableMap class.
     */
    public static function buildTableMap()
    {
        $dbMap = Propel::getServiceContainer()->getDatabaseMap(ParticipanteTableMap::DATABASE_NAME);
        if (!$dbMap->hasTable(ParticipanteTableMap::TABLE_NAME)) {
            $dbMap->addTableObject(new ParticipanteTableMap());
        }
    }

    /**
     * Performs a DELETE on the database, given a Participante or Criteria object OR a primary key value.
     *
     * @param mixed               $values Criteria or Participante object or primary key or array of primary keys
     *              which is used to create the DELETE statement
     * @param  ConnectionInterface $con the connection to use
     * @return int             The number of affected rows (if supported by underlying database driver).  This includes CASCADE-related rows
     *                         if supported by native driver or if emulated using Propel.
     * @throws PropelException Any exceptions caught during processing will be
     *                         rethrown wrapped into a PropelException.
     */
     public static function doDelete($values, ConnectionInterface $con = null)
     {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(ParticipanteTableMap::DATABASE_NAME);
        }

        if ($values instanceof Criteria) {
            // rename for clarity
            $criteria = $values;
        } elseif ($values instanceof \Baja\Model\Participante) { // it's a model object
            // create criteria based on pk values
            $criteria = $values->buildPkeyCriteria();
        } else { // it's a primary key, or an array of pks
            $criteria = new Criteria(ParticipanteTableMap::DATABASE_NAME);
            // primary key is composite; we therefore, expect
            // the primary key passed to be an array of pkey values
            if (count($values) == count($values, COUNT_RECURSIVE)) {
                // array is not multi-dimensional
                $values = array($values);
            }
            foreach ($values as $value) {
                $criterion = $criteria->getNewCriterion(ParticipanteTableMap::COL_IDPARTICIPANTES, $value[0]);
                $criterion->addAnd($criteria->getNewCriterion(ParticipanteTableMap::COL_EVENTO, $value[1]));
                $criteria->addOr($criterion);
            }
        }

        $query = ParticipanteQuery::create()->mergeWith($criteria);

        if ($values instanceof Criteria) {
            ParticipanteTableMap::clearInstancePool();
        } elseif (!is_object($values)) { // it's a primary key, or an array of pks
            foreach ((array) $values as $singleval) {
                ParticipanteTableMap::removeInstanceFromPool($singleval);
            }
        }

        return $query->delete($con);
    }

    /**
     * Deletes all rows from the participantes table.
     *
     * @param ConnectionInterface $con the connection to use
     * @return int The number of affected rows (if supported by underlying database driver).
     */
    public static function doDeleteAll(ConnectionInterface $con = null)
    {
        return ParticipanteQuery::create()->doDeleteAll($con);
    }

    /**
     * Performs an INSERT on the database, given a Participante or Criteria object.
     *
     * @param mixed               $criteria Criteria or Participante object containing data that is used to create the INSERT statement.
     * @param ConnectionInterface $con the ConnectionInterface connection to use
     * @return mixed           The new primary key.
     * @throws PropelException Any exceptions caught during processing will be
     *                         rethrown wrapped into a PropelException.
     */
    public static function doInsert($criteria, ConnectionInterface $con = null)
    {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(ParticipanteTableMap::DATABASE_NAME);
        }

        if ($criteria instanceof Criteria) {
            $criteria = clone $criteria; // rename for clarity
        } else {
            $criteria = $criteria->buildCriteria(); // build Criteria from Participante object
        }

        if ($criteria->containsKey(ParticipanteTableMap::COL_IDPARTICIPANTES) && $criteria->keyContainsValue(ParticipanteTableMap::COL_IDPARTICIPANTES) ) {
            throw new PropelException('Cannot insert a value for auto-increment primary key ('.ParticipanteTableMap::COL_IDPARTICIPANTES.')');
        }


        // Set the correct dbName
        $query = ParticipanteQuery::create()->mergeWith($criteria);

        // use transaction because $criteria could contain info
        // for more than one table (I guess, conceivably)
        return $con->transaction(function () use ($con, $query) {
            return $query->doInsert($con);
        });
    }

} // ParticipanteTableMap
// This is the static code needed to register the TableMap for this table with the main Propel class.
//
ParticipanteTableMap::buildTableMap();
