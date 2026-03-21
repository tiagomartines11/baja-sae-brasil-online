<?php

namespace Baja\Model\Map;

use Baja\Model\Fila;
use Baja\Model\FilaQuery;
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
 * This class defines the structure of the 'fila' table.
 *
 *
 *
 * This map class is used by Propel to do runtime db structure discovery.
 * For example, the createSelectSql() method checks the type of a given column used in an
 * ORDER BY clause to know whether it needs to apply SQL to make the ORDER BY case-insensitive
 * (i.e. if it's a text column type).
 */
class FilaTableMap extends TableMap
{
    use InstancePoolTrait;
    use TableMapTrait;

    /**
     * The (dot-path) name of this class
     */
    const CLASS_NAME = 'Baja.Model.Map.FilaTableMap';

    /**
     * The default database name for this class
     */
    const DATABASE_NAME = 'resultados';

    /**
     * The table name for this class
     */
    const TABLE_NAME = 'fila';

    /**
     * The related Propel class for this table
     */
    const OM_CLASS = '\\Baja\\Model\\Fila';

    /**
     * A class that can be returned by this tableMap
     */
    const CLASS_DEFAULT = 'Baja.Model.Fila';

    /**
     * The total number of columns
     */
    const NUM_COLUMNS = 10;

    /**
     * The number of lazy-loaded columns
     */
    const NUM_LAZY_LOAD_COLUMNS = 0;

    /**
     * The number of columns to hydrate (NUM_COLUMNS - NUM_LAZY_LOAD_COLUMNS)
     */
    const NUM_HYDRATE_COLUMNS = 10;

    /**
     * the column name for the evento_id field
     */
    const COL_EVENTO_ID = 'fila.evento_id';

    /**
     * the column name for the fila_id field
     */
    const COL_FILA_ID = 'fila.fila_id';

    /**
     * the column name for the nome field
     */
    const COL_NOME = 'fila.nome';

    /**
     * the column name for the status field
     */
    const COL_STATUS = 'fila.status';

    /**
     * the column name for the permite_troca field
     */
    const COL_PERMITE_TROCA = 'fila.permite_troca';

    /**
     * the column name for the permite_multiplas field
     */
    const COL_PERMITE_MULTIPLAS = 'fila.permite_multiplas';

    /**
     * the column name for the permite_chamada_espera field
     */
    const COL_PERMITE_CHAMADA_ESPERA = 'fila.permite_chamada_espera';

    /**
     * the column name for the tempo_espera field
     */
    const COL_TEMPO_ESPERA = 'fila.tempo_espera';

    /**
     * the column name for the abertura_programada field
     */
    const COL_ABERTURA_PROGRAMADA = 'fila.abertura_programada';

    /**
     * the column name for the fechamento_programado field
     */
    const COL_FECHAMENTO_PROGRAMADO = 'fila.fechamento_programado';

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
        self::TYPE_PHPNAME       => array('EventoId', 'FilaId', 'Nome', 'Status', 'PermiteTroca', 'PermiteMultiplas', 'PermiteChamadaEspera', 'TempoEspera', 'AberturaProgramada', 'FechamentoProgramado', ),
        self::TYPE_CAMELNAME     => array('eventoId', 'filaId', 'nome', 'status', 'permiteTroca', 'permiteMultiplas', 'permiteChamadaEspera', 'tempoEspera', 'aberturaProgramada', 'fechamentoProgramado', ),
        self::TYPE_COLNAME       => array(FilaTableMap::COL_EVENTO_ID, FilaTableMap::COL_FILA_ID, FilaTableMap::COL_NOME, FilaTableMap::COL_STATUS, FilaTableMap::COL_PERMITE_TROCA, FilaTableMap::COL_PERMITE_MULTIPLAS, FilaTableMap::COL_PERMITE_CHAMADA_ESPERA, FilaTableMap::COL_TEMPO_ESPERA, FilaTableMap::COL_ABERTURA_PROGRAMADA, FilaTableMap::COL_FECHAMENTO_PROGRAMADO, ),
        self::TYPE_FIELDNAME     => array('evento_id', 'fila_id', 'nome', 'status', 'permite_troca', 'permite_multiplas', 'permite_chamada_espera', 'tempo_espera', 'abertura_programada', 'fechamento_programado', ),
        self::TYPE_NUM           => array(0, 1, 2, 3, 4, 5, 6, 7, 8, 9, )
    );

    /**
     * holds an array of keys for quick access to the fieldnames array
     *
     * first dimension keys are the type constants
     * e.g. self::$fieldKeys[self::TYPE_PHPNAME]['Id'] = 0
     */
    protected static $fieldKeys = array (
        self::TYPE_PHPNAME       => array('EventoId' => 0, 'FilaId' => 1, 'Nome' => 2, 'Status' => 3, 'PermiteTroca' => 4, 'PermiteMultiplas' => 5, 'PermiteChamadaEspera' => 6, 'TempoEspera' => 7, 'AberturaProgramada' => 8, 'FechamentoProgramado' => 9, ),
        self::TYPE_CAMELNAME     => array('eventoId' => 0, 'filaId' => 1, 'nome' => 2, 'status' => 3, 'permiteTroca' => 4, 'permiteMultiplas' => 5, 'permiteChamadaEspera' => 6, 'tempoEspera' => 7, 'aberturaProgramada' => 8, 'fechamentoProgramado' => 9, ),
        self::TYPE_COLNAME       => array(FilaTableMap::COL_EVENTO_ID => 0, FilaTableMap::COL_FILA_ID => 1, FilaTableMap::COL_NOME => 2, FilaTableMap::COL_STATUS => 3, FilaTableMap::COL_PERMITE_TROCA => 4, FilaTableMap::COL_PERMITE_MULTIPLAS => 5, FilaTableMap::COL_PERMITE_CHAMADA_ESPERA => 6, FilaTableMap::COL_TEMPO_ESPERA => 7, FilaTableMap::COL_ABERTURA_PROGRAMADA => 8, FilaTableMap::COL_FECHAMENTO_PROGRAMADO => 9, ),
        self::TYPE_FIELDNAME     => array('evento_id' => 0, 'fila_id' => 1, 'nome' => 2, 'status' => 3, 'permite_troca' => 4, 'permite_multiplas' => 5, 'permite_chamada_espera' => 6, 'tempo_espera' => 7, 'abertura_programada' => 8, 'fechamento_programado' => 9, ),
        self::TYPE_NUM           => array(0, 1, 2, 3, 4, 5, 6, 7, 8, 9, )
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
        $this->setName('fila');
        $this->setPhpName('Fila');
        $this->setIdentifierQuoting(false);
        $this->setClassName('\\Baja\\Model\\Fila');
        $this->setPackage('Baja.Model');
        $this->setUseIdGenerator(false);
        // columns
        $this->addForeignPrimaryKey('evento_id', 'EventoId', 'CHAR' , 'evento', 'evento_id', true, 16, null);
        $this->addPrimaryKey('fila_id', 'FilaId', 'INTEGER', true, 4, null);
        $this->addColumn('nome', 'Nome', 'VARCHAR', true, 45, null);
        $this->addColumn('status', 'Status', 'TINYINT', true, 1, 0);
        $this->addColumn('permite_troca', 'PermiteTroca', 'BOOLEAN', true, 1, false);
        $this->addColumn('permite_multiplas', 'PermiteMultiplas', 'BOOLEAN', true, 1, false);
        $this->addColumn('permite_chamada_espera', 'PermiteChamadaEspera', 'BOOLEAN', true, 1, true);
        $this->addColumn('tempo_espera', 'TempoEspera', 'INTEGER', false, 11, null);
        $this->addColumn('abertura_programada', 'AberturaProgramada', 'TIMESTAMP', false, null, null);
        $this->addColumn('fechamento_programado', 'FechamentoProgramado', 'TIMESTAMP', false, null, null);
    } // initialize()

    /**
     * Build the RelationMap objects for this table relationships
     */
    public function buildRelations()
    {
        $this->addRelation('Evento', '\\Baja\\Model\\Evento', RelationMap::MANY_TO_ONE, array (
  0 =>
  array (
    0 => ':evento_id',
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
     * @param \Baja\Model\Fila $obj A \Baja\Model\Fila object.
     * @param string $key             (optional) key to use for instance map (for performance boost if key was already calculated externally).
     */
    public static function addInstanceToPool($obj, $key = null)
    {
        if (Propel::isInstancePoolingEnabled()) {
            if (null === $key) {
                $key = serialize([(null === $obj->getEventoId() || is_scalar($obj->getEventoId()) || is_callable([$obj->getEventoId(), '__toString']) ? (string) $obj->getEventoId() : $obj->getEventoId()), (null === $obj->getFilaId() || is_scalar($obj->getFilaId()) || is_callable([$obj->getFilaId(), '__toString']) ? (string) $obj->getFilaId() : $obj->getFilaId())]);
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
     * @param mixed $value A \Baja\Model\Fila object or a primary key value.
     */
    public static function removeInstanceFromPool($value)
    {
        if (Propel::isInstancePoolingEnabled() && null !== $value) {
            if (is_object($value) && $value instanceof \Baja\Model\Fila) {
                $key = serialize([(null === $value->getEventoId() || is_scalar($value->getEventoId()) || is_callable([$value->getEventoId(), '__toString']) ? (string) $value->getEventoId() : $value->getEventoId()), (null === $value->getFilaId() || is_scalar($value->getFilaId()) || is_callable([$value->getFilaId(), '__toString']) ? (string) $value->getFilaId() : $value->getFilaId())]);

            } elseif (is_array($value) && count($value) === 2) {
                // assume we've been passed a primary key";
                $key = serialize([(null === $value[0] || is_scalar($value[0]) || is_callable([$value[0], '__toString']) ? (string) $value[0] : $value[0]), (null === $value[1] || is_scalar($value[1]) || is_callable([$value[1], '__toString']) ? (string) $value[1] : $value[1])]);
            } elseif ($value instanceof Criteria) {
                self::$instances = [];

                return;
            } else {
                $e = new PropelException("Invalid value passed to removeInstanceFromPool().  Expected primary key or \Baja\Model\Fila object; got " . (is_object($value) ? get_class($value) . ' object.' : var_export($value, true)));
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
        if ($row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('EventoId', TableMap::TYPE_PHPNAME, $indexType)] === null && $row[TableMap::TYPE_NUM == $indexType ? 1 + $offset : static::translateFieldName('FilaId', TableMap::TYPE_PHPNAME, $indexType)] === null) {
            return null;
        }

        return serialize([(null === $row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('EventoId', TableMap::TYPE_PHPNAME, $indexType)] || is_scalar($row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('EventoId', TableMap::TYPE_PHPNAME, $indexType)]) || is_callable([$row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('EventoId', TableMap::TYPE_PHPNAME, $indexType)], '__toString']) ? (string) $row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('EventoId', TableMap::TYPE_PHPNAME, $indexType)] : $row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('EventoId', TableMap::TYPE_PHPNAME, $indexType)]), (null === $row[TableMap::TYPE_NUM == $indexType ? 1 + $offset : static::translateFieldName('FilaId', TableMap::TYPE_PHPNAME, $indexType)] || is_scalar($row[TableMap::TYPE_NUM == $indexType ? 1 + $offset : static::translateFieldName('FilaId', TableMap::TYPE_PHPNAME, $indexType)]) || is_callable([$row[TableMap::TYPE_NUM == $indexType ? 1 + $offset : static::translateFieldName('FilaId', TableMap::TYPE_PHPNAME, $indexType)], '__toString']) ? (string) $row[TableMap::TYPE_NUM == $indexType ? 1 + $offset : static::translateFieldName('FilaId', TableMap::TYPE_PHPNAME, $indexType)] : $row[TableMap::TYPE_NUM == $indexType ? 1 + $offset : static::translateFieldName('FilaId', TableMap::TYPE_PHPNAME, $indexType)])]);
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

        $pks[] = (string) $row[
            $indexType == TableMap::TYPE_NUM
                ? 0 + $offset
                : self::translateFieldName('EventoId', TableMap::TYPE_PHPNAME, $indexType)
        ];
        $pks[] = (int) $row[
            $indexType == TableMap::TYPE_NUM
                ? 1 + $offset
                : self::translateFieldName('FilaId', TableMap::TYPE_PHPNAME, $indexType)
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
        return $withPrefix ? FilaTableMap::CLASS_DEFAULT : FilaTableMap::OM_CLASS;
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
     * @return array           (Fila object, last column rank)
     */
    public static function populateObject($row, $offset = 0, $indexType = TableMap::TYPE_NUM)
    {
        $key = FilaTableMap::getPrimaryKeyHashFromRow($row, $offset, $indexType);
        if (null !== ($obj = FilaTableMap::getInstanceFromPool($key))) {
            // We no longer rehydrate the object, since this can cause data loss.
            // See http://www.propelorm.org/ticket/509
            // $obj->hydrate($row, $offset, true); // rehydrate
            $col = $offset + FilaTableMap::NUM_HYDRATE_COLUMNS;
        } else {
            $cls = FilaTableMap::OM_CLASS;
            /** @var Fila $obj */
            $obj = new $cls();
            $col = $obj->hydrate($row, $offset, false, $indexType);
            FilaTableMap::addInstanceToPool($obj, $key);
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
            $key = FilaTableMap::getPrimaryKeyHashFromRow($row, 0, $dataFetcher->getIndexType());
            if (null !== ($obj = FilaTableMap::getInstanceFromPool($key))) {
                // We no longer rehydrate the object, since this can cause data loss.
                // See http://www.propelorm.org/ticket/509
                // $obj->hydrate($row, 0, true); // rehydrate
                $results[] = $obj;
            } else {
                /** @var Fila $obj */
                $obj = new $cls();
                $obj->hydrate($row);
                $results[] = $obj;
                FilaTableMap::addInstanceToPool($obj, $key);
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
            $criteria->addSelectColumn(FilaTableMap::COL_EVENTO_ID);
            $criteria->addSelectColumn(FilaTableMap::COL_FILA_ID);
            $criteria->addSelectColumn(FilaTableMap::COL_NOME);
            $criteria->addSelectColumn(FilaTableMap::COL_STATUS);
            $criteria->addSelectColumn(FilaTableMap::COL_PERMITE_TROCA);
            $criteria->addSelectColumn(FilaTableMap::COL_PERMITE_MULTIPLAS);
            $criteria->addSelectColumn(FilaTableMap::COL_PERMITE_CHAMADA_ESPERA);
            $criteria->addSelectColumn(FilaTableMap::COL_TEMPO_ESPERA);
            $criteria->addSelectColumn(FilaTableMap::COL_ABERTURA_PROGRAMADA);
            $criteria->addSelectColumn(FilaTableMap::COL_FECHAMENTO_PROGRAMADO);
        } else {
            $criteria->addSelectColumn($alias . '.evento_id');
            $criteria->addSelectColumn($alias . '.fila_id');
            $criteria->addSelectColumn($alias . '.nome');
            $criteria->addSelectColumn($alias . '.status');
            $criteria->addSelectColumn($alias . '.permite_troca');
            $criteria->addSelectColumn($alias . '.permite_multiplas');
            $criteria->addSelectColumn($alias . '.permite_chamada_espera');
            $criteria->addSelectColumn($alias . '.tempo_espera');
            $criteria->addSelectColumn($alias . '.abertura_programada');
            $criteria->addSelectColumn($alias . '.fechamento_programado');
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
        return Propel::getServiceContainer()->getDatabaseMap(FilaTableMap::DATABASE_NAME)->getTable(FilaTableMap::TABLE_NAME);
    }

    /**
     * Add a TableMap instance to the database for this tableMap class.
     */
    public static function buildTableMap()
    {
        $dbMap = Propel::getServiceContainer()->getDatabaseMap(FilaTableMap::DATABASE_NAME);
        if (!$dbMap->hasTable(FilaTableMap::TABLE_NAME)) {
            $dbMap->addTableObject(new FilaTableMap());
        }
    }

    /**
     * Performs a DELETE on the database, given a Fila or Criteria object OR a primary key value.
     *
     * @param mixed               $values Criteria or Fila object or primary key or array of primary keys
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
            $con = Propel::getServiceContainer()->getWriteConnection(FilaTableMap::DATABASE_NAME);
        }

        if ($values instanceof Criteria) {
            // rename for clarity
            $criteria = $values;
        } elseif ($values instanceof \Baja\Model\Fila) { // it's a model object
            // create criteria based on pk values
            $criteria = $values->buildPkeyCriteria();
        } else { // it's a primary key, or an array of pks
            $criteria = new Criteria(FilaTableMap::DATABASE_NAME);
            // primary key is composite; we therefore, expect
            // the primary key passed to be an array of pkey values
            if (count($values) == count($values, COUNT_RECURSIVE)) {
                // array is not multi-dimensional
                $values = array($values);
            }
            foreach ($values as $value) {
                $criterion = $criteria->getNewCriterion(FilaTableMap::COL_EVENTO_ID, $value[0]);
                $criterion->addAnd($criteria->getNewCriterion(FilaTableMap::COL_FILA_ID, $value[1]));
                $criteria->addOr($criterion);
            }
        }

        $query = FilaQuery::create()->mergeWith($criteria);

        if ($values instanceof Criteria) {
            FilaTableMap::clearInstancePool();
        } elseif (!is_object($values)) { // it's a primary key, or an array of pks
            foreach ((array) $values as $singleval) {
                FilaTableMap::removeInstanceFromPool($singleval);
            }
        }

        return $query->delete($con);
    }

    /**
     * Deletes all rows from the fila table.
     *
     * @param ConnectionInterface $con the connection to use
     * @return int The number of affected rows (if supported by underlying database driver).
     */
    public static function doDeleteAll(ConnectionInterface $con = null)
    {
        return FilaQuery::create()->doDeleteAll($con);
    }

    /**
     * Performs an INSERT on the database, given a Fila or Criteria object.
     *
     * @param mixed               $criteria Criteria or Fila object containing data that is used to create the INSERT statement.
     * @param ConnectionInterface $con the ConnectionInterface connection to use
     * @return mixed           The new primary key.
     * @throws PropelException Any exceptions caught during processing will be
     *                         rethrown wrapped into a PropelException.
     */
    public static function doInsert($criteria, ConnectionInterface $con = null)
    {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(FilaTableMap::DATABASE_NAME);
        }

        if ($criteria instanceof Criteria) {
            $criteria = clone $criteria; // rename for clarity
        } else {
            $criteria = $criteria->buildCriteria(); // build Criteria from Fila object
        }


        // Set the correct dbName
        $query = FilaQuery::create()->mergeWith($criteria);

        // use transaction because $criteria could contain info
        // for more than one table (I guess, conceivably)
        return $con->transaction(function () use ($con, $query) {
            return $query->doInsert($con);
        });
    }

} // FilaTableMap
// This is the static code needed to register the TableMap for this table with the main Propel class.
//
FilaTableMap::buildTableMap();
