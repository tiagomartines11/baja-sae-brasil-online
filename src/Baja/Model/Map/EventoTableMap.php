<?php

namespace Baja\Model\Map;

use Baja\Model\Evento;
use Baja\Model\EventoQuery;
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
 * This class defines the structure of the 'evento' table.
 *
 *
 *
 * This map class is used by Propel to do runtime db structure discovery.
 * For example, the createSelectSql() method checks the type of a given column used in an
 * ORDER BY clause to know whether it needs to apply SQL to make the ORDER BY case-insensitive
 * (i.e. if it's a text column type).
 */
class EventoTableMap extends TableMap
{
    use InstancePoolTrait;
    use TableMapTrait;

    /**
     * The (dot-path) name of this class
     */
    const CLASS_NAME = 'Baja.Model.Map.EventoTableMap';

    /**
     * The default database name for this class
     */
    const DATABASE_NAME = 'resultados';

    /**
     * The table name for this class
     */
    const TABLE_NAME = 'evento';

    /**
     * The related Propel class for this table
     */
    const OM_CLASS = '\\Baja\\Model\\Evento';

    /**
     * A class that can be returned by this tableMap
     */
    const CLASS_DEFAULT = 'Baja.Model.Evento';

    /**
     * The total number of columns
     */
    const NUM_COLUMNS = 14;

    /**
     * The number of lazy-loaded columns
     */
    const NUM_LAZY_LOAD_COLUMNS = 0;

    /**
     * The number of columns to hydrate (NUM_COLUMNS - NUM_LAZY_LOAD_COLUMNS)
     */
    const NUM_HYDRATE_COLUMNS = 14;

    /**
     * the column name for the evento_id field
     */
    const COL_EVENTO_ID = 'evento.evento_id';

    /**
     * the column name for the titulo field
     */
    const COL_TITULO = 'evento.titulo';

    /**
     * the column name for the nome field
     */
    const COL_NOME = 'evento.nome';

    /**
     * the column name for the tipo field
     */
    const COL_TIPO = 'evento.tipo';

    /**
     * the column name for the ano field
     */
    const COL_ANO = 'evento.ano';

    /**
     * the column name for the menu field
     */
    const COL_MENU = 'evento.menu';

    /**
     * the column name for the ativo field
     */
    const COL_ATIVO = 'evento.ativo';

    /**
     * the column name for the finalizado field
     */
    const COL_FINALIZADO = 'evento.finalizado';

    /**
     * the column name for the spoilers field
     */
    const COL_SPOILERS = 'evento.spoilers';

    /**
     * the column name for the tem_certificado field
     */
    const COL_TEM_CERTIFICADO = 'evento.tem_certificado';

    /**
     * the column name for the presidente field
     */
    const COL_PRESIDENTE = 'evento.presidente';

    /**
     * the column name for the data field
     */
    const COL_DATA = 'evento.data';

    /**
     * the column name for the mandato_presidente field
     */
    const COL_MANDATO_PRESIDENTE = 'evento.mandato_presidente';

    /**
     * the column name for the local field
     */
    const COL_LOCAL = 'evento.local';

    /**
     * The default string format for model objects of the related table
     */
    const DEFAULT_STRING_FORMAT = 'YAML';

    /** The enumerated values for the tipo field */
    const COL_TIPO_NACIONAL = 'Nacional';
    const COL_TIPO_SUDESTE = 'Sudeste';
    const COL_TIPO_NORDESTE = 'Nordeste';
    const COL_TIPO_SUL = 'Sul';

    /**
     * holds an array of fieldnames
     *
     * first dimension keys are the type constants
     * e.g. self::$fieldNames[self::TYPE_PHPNAME][0] = 'Id'
     */
    protected static $fieldNames = array (
        self::TYPE_PHPNAME       => array('EventoId', 'Titulo', 'Nome', 'Tipo', 'Ano', 'Menu', 'Ativo', 'Finalizado', 'Spoilers', 'TemCertificado', 'Presidente', 'Data', 'MandatoPresidente', 'Local', ),
        self::TYPE_CAMELNAME     => array('eventoId', 'titulo', 'nome', 'tipo', 'ano', 'menu', 'ativo', 'finalizado', 'spoilers', 'temCertificado', 'presidente', 'data', 'mandatoPresidente', 'local', ),
        self::TYPE_COLNAME       => array(EventoTableMap::COL_EVENTO_ID, EventoTableMap::COL_TITULO, EventoTableMap::COL_NOME, EventoTableMap::COL_TIPO, EventoTableMap::COL_ANO, EventoTableMap::COL_MENU, EventoTableMap::COL_ATIVO, EventoTableMap::COL_FINALIZADO, EventoTableMap::COL_SPOILERS, EventoTableMap::COL_TEM_CERTIFICADO, EventoTableMap::COL_PRESIDENTE, EventoTableMap::COL_DATA, EventoTableMap::COL_MANDATO_PRESIDENTE, EventoTableMap::COL_LOCAL, ),
        self::TYPE_FIELDNAME     => array('evento_id', 'titulo', 'nome', 'tipo', 'ano', 'menu', 'ativo', 'finalizado', 'spoilers', 'tem_certificado', 'presidente', 'data', 'mandato_presidente', 'local', ),
        self::TYPE_NUM           => array(0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, )
    );

    /**
     * holds an array of keys for quick access to the fieldnames array
     *
     * first dimension keys are the type constants
     * e.g. self::$fieldKeys[self::TYPE_PHPNAME]['Id'] = 0
     */
    protected static $fieldKeys = array (
        self::TYPE_PHPNAME       => array('EventoId' => 0, 'Titulo' => 1, 'Nome' => 2, 'Tipo' => 3, 'Ano' => 4, 'Menu' => 5, 'Ativo' => 6, 'Finalizado' => 7, 'Spoilers' => 8, 'TemCertificado' => 9, 'Presidente' => 10, 'Data' => 11, 'MandatoPresidente' => 12, 'Local' => 13, ),
        self::TYPE_CAMELNAME     => array('eventoId' => 0, 'titulo' => 1, 'nome' => 2, 'tipo' => 3, 'ano' => 4, 'menu' => 5, 'ativo' => 6, 'finalizado' => 7, 'spoilers' => 8, 'temCertificado' => 9, 'presidente' => 10, 'data' => 11, 'mandatoPresidente' => 12, 'local' => 13, ),
        self::TYPE_COLNAME       => array(EventoTableMap::COL_EVENTO_ID => 0, EventoTableMap::COL_TITULO => 1, EventoTableMap::COL_NOME => 2, EventoTableMap::COL_TIPO => 3, EventoTableMap::COL_ANO => 4, EventoTableMap::COL_MENU => 5, EventoTableMap::COL_ATIVO => 6, EventoTableMap::COL_FINALIZADO => 7, EventoTableMap::COL_SPOILERS => 8, EventoTableMap::COL_TEM_CERTIFICADO => 9, EventoTableMap::COL_PRESIDENTE => 10, EventoTableMap::COL_DATA => 11, EventoTableMap::COL_MANDATO_PRESIDENTE => 12, EventoTableMap::COL_LOCAL => 13, ),
        self::TYPE_FIELDNAME     => array('evento_id' => 0, 'titulo' => 1, 'nome' => 2, 'tipo' => 3, 'ano' => 4, 'menu' => 5, 'ativo' => 6, 'finalizado' => 7, 'spoilers' => 8, 'tem_certificado' => 9, 'presidente' => 10, 'data' => 11, 'mandato_presidente' => 12, 'local' => 13, ),
        self::TYPE_NUM           => array(0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, )
    );

    /** The enumerated values for this table */
    protected static $enumValueSets = array(
                EventoTableMap::COL_TIPO => array(
                            self::COL_TIPO_NACIONAL,
            self::COL_TIPO_SUDESTE,
            self::COL_TIPO_NORDESTE,
            self::COL_TIPO_SUL,
        ),
    );

    /**
     * Gets the list of values for all ENUM and SET columns
     * @return array
     */
    public static function getValueSets()
    {
      return static::$enumValueSets;
    }

    /**
     * Gets the list of values for an ENUM or SET column
     * @param string $colname
     * @return array list of possible values for the column
     */
    public static function getValueSet($colname)
    {
        $valueSets = self::getValueSets();

        return $valueSets[$colname];
    }

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
        $this->setName('evento');
        $this->setPhpName('Evento');
        $this->setIdentifierQuoting(false);
        $this->setClassName('\\Baja\\Model\\Evento');
        $this->setPackage('Baja.Model');
        $this->setUseIdGenerator(false);
        // columns
        $this->addPrimaryKey('evento_id', 'EventoId', 'CHAR', true, 4, null);
        $this->addColumn('titulo', 'Titulo', 'VARCHAR', false, 100, null);
        $this->addColumn('nome', 'Nome', 'VARCHAR', false, 120, null);
        $this->addColumn('tipo', 'Tipo', 'ENUM', false, null, null);
        $this->getColumn('tipo')->setValueSet(array (
  0 => 'Nacional',
  1 => 'Sudeste',
  2 => 'Nordeste',
  3 => 'Sul',
));
        $this->addColumn('ano', 'Ano', 'INTEGER', false, 4, null);
        $this->addColumn('menu', 'Menu', 'LONGVARCHAR', false, null, null);
        $this->addColumn('ativo', 'Ativo', 'BOOLEAN', true, 1, true);
        $this->addColumn('finalizado', 'Finalizado', 'BOOLEAN', true, 1, false);
        $this->addColumn('spoilers', 'Spoilers', 'BOOLEAN', true, 1, false);
        $this->addColumn('tem_certificado', 'TemCertificado', 'BOOLEAN', true, 1, false);
        $this->addColumn('presidente', 'Presidente', 'VARCHAR', false, 45, null);
        $this->addColumn('data', 'Data', 'VARCHAR', false, 100, null);
        $this->addColumn('mandato_presidente', 'MandatoPresidente', 'VARCHAR', false, 9, null);
        $this->addColumn('local', 'Local', 'VARCHAR', false, 120, null);
    } // initialize()

    /**
     * Build the RelationMap objects for this table relationships
     */
    public function buildRelations()
    {
        $this->addRelation('Equipe', '\\Baja\\Model\\Equipe', RelationMap::ONE_TO_MANY, array (
  0 =>
  array (
    0 => ':evento_id',
    1 => ':evento_id',
  ),
), 'CASCADE', 'CASCADE', 'Equipes', false);
        $this->addRelation('Participante', '\\Baja\\Model\\Participante', RelationMap::ONE_TO_MANY, array (
  0 =>
  array (
    0 => ':evento',
    1 => ':evento_id',
  ),
), 'CASCADE', 'CASCADE', 'Participantes', false);
        $this->addRelation('Prova', '\\Baja\\Model\\Prova', RelationMap::ONE_TO_MANY, array (
  0 =>
  array (
    0 => ':evento_id',
    1 => ':evento_id',
  ),
), 'CASCADE', 'CASCADE', 'Provas', false);
        $this->addRelation('Resultado', '\\Baja\\Model\\Resultado', RelationMap::ONE_TO_MANY, array (
  0 =>
  array (
    0 => ':evento_id',
    1 => ':evento_id',
  ),
), 'CASCADE', 'CASCADE', 'Resultados', false);
    } // buildRelations()
    /**
     * Method to invalidate the instance pool of all tables related to evento     * by a foreign key with ON DELETE CASCADE
     */
    public static function clearRelatedInstancePool()
    {
        // Invalidate objects in related instance pools,
        // since one or more of them may be deleted by ON DELETE CASCADE/SETNULL rule.
        EquipeTableMap::clearInstancePool();
        ParticipanteTableMap::clearInstancePool();
        ProvaTableMap::clearInstancePool();
        ResultadoTableMap::clearInstancePool();
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
        if ($row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('EventoId', TableMap::TYPE_PHPNAME, $indexType)] === null) {
            return null;
        }

        return null === $row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('EventoId', TableMap::TYPE_PHPNAME, $indexType)] || is_scalar($row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('EventoId', TableMap::TYPE_PHPNAME, $indexType)]) || is_callable([$row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('EventoId', TableMap::TYPE_PHPNAME, $indexType)], '__toString']) ? (string) $row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('EventoId', TableMap::TYPE_PHPNAME, $indexType)] : $row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('EventoId', TableMap::TYPE_PHPNAME, $indexType)];
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
        return (string) $row[
            $indexType == TableMap::TYPE_NUM
                ? 0 + $offset
                : self::translateFieldName('EventoId', TableMap::TYPE_PHPNAME, $indexType)
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
     * @param boolean $withPrefix Whether or not to return the path with the class name
     * @return string path.to.ClassName
     */
    public static function getOMClass($withPrefix = true)
    {
        return $withPrefix ? EventoTableMap::CLASS_DEFAULT : EventoTableMap::OM_CLASS;
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
     * @return array           (Evento object, last column rank)
     */
    public static function populateObject($row, $offset = 0, $indexType = TableMap::TYPE_NUM)
    {
        $key = EventoTableMap::getPrimaryKeyHashFromRow($row, $offset, $indexType);
        if (null !== ($obj = EventoTableMap::getInstanceFromPool($key))) {
            // We no longer rehydrate the object, since this can cause data loss.
            // See http://www.propelorm.org/ticket/509
            // $obj->hydrate($row, $offset, true); // rehydrate
            $col = $offset + EventoTableMap::NUM_HYDRATE_COLUMNS;
        } else {
            $cls = EventoTableMap::OM_CLASS;
            /** @var Evento $obj */
            $obj = new $cls();
            $col = $obj->hydrate($row, $offset, false, $indexType);
            EventoTableMap::addInstanceToPool($obj, $key);
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
            $key = EventoTableMap::getPrimaryKeyHashFromRow($row, 0, $dataFetcher->getIndexType());
            if (null !== ($obj = EventoTableMap::getInstanceFromPool($key))) {
                // We no longer rehydrate the object, since this can cause data loss.
                // See http://www.propelorm.org/ticket/509
                // $obj->hydrate($row, 0, true); // rehydrate
                $results[] = $obj;
            } else {
                /** @var Evento $obj */
                $obj = new $cls();
                $obj->hydrate($row);
                $results[] = $obj;
                EventoTableMap::addInstanceToPool($obj, $key);
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
            $criteria->addSelectColumn(EventoTableMap::COL_EVENTO_ID);
            $criteria->addSelectColumn(EventoTableMap::COL_TITULO);
            $criteria->addSelectColumn(EventoTableMap::COL_NOME);
            $criteria->addSelectColumn(EventoTableMap::COL_TIPO);
            $criteria->addSelectColumn(EventoTableMap::COL_ANO);
            $criteria->addSelectColumn(EventoTableMap::COL_MENU);
            $criteria->addSelectColumn(EventoTableMap::COL_ATIVO);
            $criteria->addSelectColumn(EventoTableMap::COL_FINALIZADO);
            $criteria->addSelectColumn(EventoTableMap::COL_SPOILERS);
            $criteria->addSelectColumn(EventoTableMap::COL_TEM_CERTIFICADO);
            $criteria->addSelectColumn(EventoTableMap::COL_PRESIDENTE);
            $criteria->addSelectColumn(EventoTableMap::COL_DATA);
            $criteria->addSelectColumn(EventoTableMap::COL_MANDATO_PRESIDENTE);
            $criteria->addSelectColumn(EventoTableMap::COL_LOCAL);
        } else {
            $criteria->addSelectColumn($alias . '.evento_id');
            $criteria->addSelectColumn($alias . '.titulo');
            $criteria->addSelectColumn($alias . '.nome');
            $criteria->addSelectColumn($alias . '.tipo');
            $criteria->addSelectColumn($alias . '.ano');
            $criteria->addSelectColumn($alias . '.menu');
            $criteria->addSelectColumn($alias . '.ativo');
            $criteria->addSelectColumn($alias . '.finalizado');
            $criteria->addSelectColumn($alias . '.spoilers');
            $criteria->addSelectColumn($alias . '.tem_certificado');
            $criteria->addSelectColumn($alias . '.presidente');
            $criteria->addSelectColumn($alias . '.data');
            $criteria->addSelectColumn($alias . '.mandato_presidente');
            $criteria->addSelectColumn($alias . '.local');
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
        return Propel::getServiceContainer()->getDatabaseMap(EventoTableMap::DATABASE_NAME)->getTable(EventoTableMap::TABLE_NAME);
    }

    /**
     * Add a TableMap instance to the database for this tableMap class.
     */
    public static function buildTableMap()
    {
        $dbMap = Propel::getServiceContainer()->getDatabaseMap(EventoTableMap::DATABASE_NAME);
        if (!$dbMap->hasTable(EventoTableMap::TABLE_NAME)) {
            $dbMap->addTableObject(new EventoTableMap());
        }
    }

    /**
     * Performs a DELETE on the database, given a Evento or Criteria object OR a primary key value.
     *
     * @param mixed               $values Criteria or Evento object or primary key or array of primary keys
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
            $con = Propel::getServiceContainer()->getWriteConnection(EventoTableMap::DATABASE_NAME);
        }

        if ($values instanceof Criteria) {
            // rename for clarity
            $criteria = $values;
        } elseif ($values instanceof \Baja\Model\Evento) { // it's a model object
            // create criteria based on pk values
            $criteria = $values->buildPkeyCriteria();
        } else { // it's a primary key, or an array of pks
            $criteria = new Criteria(EventoTableMap::DATABASE_NAME);
            $criteria->add(EventoTableMap::COL_EVENTO_ID, (array) $values, Criteria::IN);
        }

        $query = EventoQuery::create()->mergeWith($criteria);

        if ($values instanceof Criteria) {
            EventoTableMap::clearInstancePool();
        } elseif (!is_object($values)) { // it's a primary key, or an array of pks
            foreach ((array) $values as $singleval) {
                EventoTableMap::removeInstanceFromPool($singleval);
            }
        }

        return $query->delete($con);
    }

    /**
     * Deletes all rows from the evento table.
     *
     * @param ConnectionInterface $con the connection to use
     * @return int The number of affected rows (if supported by underlying database driver).
     */
    public static function doDeleteAll(ConnectionInterface $con = null)
    {
        return EventoQuery::create()->doDeleteAll($con);
    }

    /**
     * Performs an INSERT on the database, given a Evento or Criteria object.
     *
     * @param mixed               $criteria Criteria or Evento object containing data that is used to create the INSERT statement.
     * @param ConnectionInterface $con the ConnectionInterface connection to use
     * @return mixed           The new primary key.
     * @throws PropelException Any exceptions caught during processing will be
     *                         rethrown wrapped into a PropelException.
     */
    public static function doInsert($criteria, ConnectionInterface $con = null)
    {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(EventoTableMap::DATABASE_NAME);
        }

        if ($criteria instanceof Criteria) {
            $criteria = clone $criteria; // rename for clarity
        } else {
            $criteria = $criteria->buildCriteria(); // build Criteria from Evento object
        }


        // Set the correct dbName
        $query = EventoQuery::create()->mergeWith($criteria);

        // use transaction because $criteria could contain info
        // for more than one table (I guess, conceivably)
        return $con->transaction(function () use ($con, $query) {
            return $query->doInsert($con);
        });
    }

} // EventoTableMap
// This is the static code needed to register the TableMap for this table with the main Propel class.
//
EventoTableMap::buildTableMap();
