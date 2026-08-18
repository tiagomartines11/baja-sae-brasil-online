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
    public const CLASS_NAME = 'Baja.Model.Map.ParticipanteTableMap';

    /**
     * The default database name for this class
     */
    public const DATABASE_NAME = 'resultados';

    /**
     * The table name for this class
     */
    public const TABLE_NAME = 'participantes';

    /**
     * The PHP name of this class (PascalCase)
     */
    public const TABLE_PHP_NAME = 'Participante';

    /**
     * The related Propel class for this table
     */
    public const OM_CLASS = '\\Baja\\Model\\Participante';

    /**
     * A class that can be returned by this tableMap
     */
    public const CLASS_DEFAULT = 'Baja.Model.Participante';

    /**
     * The total number of columns
     */
    public const NUM_COLUMNS = 9;

    /**
     * The number of lazy-loaded columns
     */
    public const NUM_LAZY_LOAD_COLUMNS = 0;

    /**
     * The number of columns to hydrate (NUM_COLUMNS - NUM_LAZY_LOAD_COLUMNS)
     */
    public const NUM_HYDRATE_COLUMNS = 9;

    /**
     * the column name for the nome field
     */
    public const COL_NOME = 'participantes.nome';

    /**
     * the column name for the funcao field
     */
    public const COL_FUNCAO = 'participantes.funcao';

    /**
     * the column name for the cpf field
     */
    public const COL_CPF = 'participantes.cpf';

    /**
     * the column name for the documento_estrangeiro field
     */
    public const COL_DOCUMENTO_ESTRANGEIRO = 'participantes.documento_estrangeiro';

    /**
     * the column name for the evento field
     */
    public const COL_EVENTO = 'participantes.evento';

    /**
     * the column name for the token field
     */
    public const COL_TOKEN = 'participantes.token';

    /**
     * the column name for the criado_por field
     */
    public const COL_CRIADO_POR = 'participantes.criado_por';

    /**
     * the column name for the criado_em field
     */
    public const COL_CRIADO_EM = 'participantes.criado_em';

    /**
     * the column name for the lote_id field
     */
    public const COL_LOTE_ID = 'participantes.lote_id';

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
        self::TYPE_PHPNAME       => ['Nome', 'Funcao', 'Cpf', 'DocumentoEstrangeiro', 'EventoId', 'Token', 'CriadoPor', 'CriadoEm', 'LoteId', ],
        self::TYPE_CAMELNAME     => ['nome', 'funcao', 'cpf', 'documentoEstrangeiro', 'eventoId', 'token', 'criadoPor', 'criadoEm', 'loteId', ],
        self::TYPE_COLNAME       => [ParticipanteTableMap::COL_NOME, ParticipanteTableMap::COL_FUNCAO, ParticipanteTableMap::COL_CPF, ParticipanteTableMap::COL_DOCUMENTO_ESTRANGEIRO, ParticipanteTableMap::COL_EVENTO, ParticipanteTableMap::COL_TOKEN, ParticipanteTableMap::COL_CRIADO_POR, ParticipanteTableMap::COL_CRIADO_EM, ParticipanteTableMap::COL_LOTE_ID, ],
        self::TYPE_FIELDNAME     => ['nome', 'funcao', 'cpf', 'documento_estrangeiro', 'evento', 'token', 'criado_por', 'criado_em', 'lote_id', ],
        self::TYPE_NUM           => [0, 1, 2, 3, 4, 5, 6, 7, 8, ]
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
        self::TYPE_PHPNAME       => ['Nome' => 0, 'Funcao' => 1, 'Cpf' => 2, 'DocumentoEstrangeiro' => 3, 'EventoId' => 4, 'Token' => 5, 'CriadoPor' => 6, 'CriadoEm' => 7, 'LoteId' => 8, ],
        self::TYPE_CAMELNAME     => ['nome' => 0, 'funcao' => 1, 'cpf' => 2, 'documentoEstrangeiro' => 3, 'eventoId' => 4, 'token' => 5, 'criadoPor' => 6, 'criadoEm' => 7, 'loteId' => 8, ],
        self::TYPE_COLNAME       => [ParticipanteTableMap::COL_NOME => 0, ParticipanteTableMap::COL_FUNCAO => 1, ParticipanteTableMap::COL_CPF => 2, ParticipanteTableMap::COL_DOCUMENTO_ESTRANGEIRO => 3, ParticipanteTableMap::COL_EVENTO => 4, ParticipanteTableMap::COL_TOKEN => 5, ParticipanteTableMap::COL_CRIADO_POR => 6, ParticipanteTableMap::COL_CRIADO_EM => 7, ParticipanteTableMap::COL_LOTE_ID => 8, ],
        self::TYPE_FIELDNAME     => ['nome' => 0, 'funcao' => 1, 'cpf' => 2, 'documento_estrangeiro' => 3, 'evento' => 4, 'token' => 5, 'criado_por' => 6, 'criado_em' => 7, 'lote_id' => 8, ],
        self::TYPE_NUM           => [0, 1, 2, 3, 4, 5, 6, 7, 8, ]
    ];

    /**
     * Holds a list of column names and their normalized version.
     *
     * @var array<string>
     */
    protected $normalizedColumnNameMap = [
        'Nome' => 'NOME',
        'Participante.Nome' => 'NOME',
        'nome' => 'NOME',
        'participante.nome' => 'NOME',
        'ParticipanteTableMap::COL_NOME' => 'NOME',
        'COL_NOME' => 'NOME',
        'participantes.nome' => 'NOME',
        'Funcao' => 'FUNCAO',
        'Participante.Funcao' => 'FUNCAO',
        'funcao' => 'FUNCAO',
        'participante.funcao' => 'FUNCAO',
        'ParticipanteTableMap::COL_FUNCAO' => 'FUNCAO',
        'COL_FUNCAO' => 'FUNCAO',
        'participantes.funcao' => 'FUNCAO',
        'Cpf' => 'CPF',
        'Participante.Cpf' => 'CPF',
        'cpf' => 'CPF',
        'participante.cpf' => 'CPF',
        'ParticipanteTableMap::COL_CPF' => 'CPF',
        'COL_CPF' => 'CPF',
        'participantes.cpf' => 'CPF',
        'DocumentoEstrangeiro' => 'DOCUMENTO_ESTRANGEIRO',
        'Participante.DocumentoEstrangeiro' => 'DOCUMENTO_ESTRANGEIRO',
        'documentoEstrangeiro' => 'DOCUMENTO_ESTRANGEIRO',
        'participante.documentoEstrangeiro' => 'DOCUMENTO_ESTRANGEIRO',
        'ParticipanteTableMap::COL_DOCUMENTO_ESTRANGEIRO' => 'DOCUMENTO_ESTRANGEIRO',
        'COL_DOCUMENTO_ESTRANGEIRO' => 'DOCUMENTO_ESTRANGEIRO',
        'documento_estrangeiro' => 'DOCUMENTO_ESTRANGEIRO',
        'participantes.documento_estrangeiro' => 'DOCUMENTO_ESTRANGEIRO',
        'EventoId' => 'EVENTO',
        'Participante.EventoId' => 'EVENTO',
        'eventoId' => 'EVENTO',
        'participante.eventoId' => 'EVENTO',
        'ParticipanteTableMap::COL_EVENTO' => 'EVENTO',
        'COL_EVENTO' => 'EVENTO',
        'evento' => 'EVENTO',
        'participantes.evento' => 'EVENTO',
        'Token' => 'TOKEN',
        'Participante.Token' => 'TOKEN',
        'token' => 'TOKEN',
        'participante.token' => 'TOKEN',
        'ParticipanteTableMap::COL_TOKEN' => 'TOKEN',
        'COL_TOKEN' => 'TOKEN',
        'participantes.token' => 'TOKEN',
        'CriadoPor' => 'CRIADO_POR',
        'Participante.CriadoPor' => 'CRIADO_POR',
        'criadoPor' => 'CRIADO_POR',
        'participante.criadoPor' => 'CRIADO_POR',
        'ParticipanteTableMap::COL_CRIADO_POR' => 'CRIADO_POR',
        'COL_CRIADO_POR' => 'CRIADO_POR',
        'criado_por' => 'CRIADO_POR',
        'participantes.criado_por' => 'CRIADO_POR',
        'CriadoEm' => 'CRIADO_EM',
        'Participante.CriadoEm' => 'CRIADO_EM',
        'criadoEm' => 'CRIADO_EM',
        'participante.criadoEm' => 'CRIADO_EM',
        'ParticipanteTableMap::COL_CRIADO_EM' => 'CRIADO_EM',
        'COL_CRIADO_EM' => 'CRIADO_EM',
        'criado_em' => 'CRIADO_EM',
        'participantes.criado_em' => 'CRIADO_EM',
        'LoteId' => 'LOTE_ID',
        'Participante.LoteId' => 'LOTE_ID',
        'loteId' => 'LOTE_ID',
        'participante.loteId' => 'LOTE_ID',
        'ParticipanteTableMap::COL_LOTE_ID' => 'LOTE_ID',
        'COL_LOTE_ID' => 'LOTE_ID',
        'lote_id' => 'LOTE_ID',
        'participantes.lote_id' => 'LOTE_ID',
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
        $this->setName('participantes');
        $this->setPhpName('Participante');
        $this->setIdentifierQuoting(false);
        $this->setClassName('\\Baja\\Model\\Participante');
        $this->setPackage('Baja.Model');
        $this->setUseIdGenerator(false);
        // columns
        $this->addColumn('nome', 'Nome', 'VARCHAR', false, 300, null);
        $this->addColumn('funcao', 'Funcao', 'VARCHAR', false, 45, null);
        $this->addColumn('cpf', 'Cpf', 'CHAR', false, 11, null);
        $this->addColumn('documento_estrangeiro', 'DocumentoEstrangeiro', 'VARCHAR', false, 32, null);
        $this->addForeignKey('evento', 'EventoId', 'CHAR', 'evento', 'evento_id', true, 4, null);
        $this->addPrimaryKey('token', 'Token', 'CHAR', true, 22, null);
        $this->addForeignKey('criado_por', 'CriadoPor', 'INTEGER', 'user', 'user_id', false, null, null);
        $this->addColumn('criado_em', 'CriadoEm', 'TIMESTAMP', false, null, null);
        $this->addColumn('lote_id', 'LoteId', 'CHAR', false, 22, null);
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
    0 => ':evento',
    1 => ':evento_id',
  ),
), 'CASCADE', 'CASCADE', null, false);
        $this->addRelation('User', '\\Baja\\Model\\User', RelationMap::MANY_TO_ONE, array (
  0 =>
  array (
    0 => ':criado_por',
    1 => ':user_id',
  ),
), 'SET NULL', 'CASCADE', null, false);
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
        if ($row[TableMap::TYPE_NUM == $indexType ? 5 + $offset : static::translateFieldName('Token', TableMap::TYPE_PHPNAME, $indexType)] === null) {
            return null;
        }

        return null === $row[TableMap::TYPE_NUM == $indexType ? 5 + $offset : static::translateFieldName('Token', TableMap::TYPE_PHPNAME, $indexType)] || is_scalar($row[TableMap::TYPE_NUM == $indexType ? 5 + $offset : static::translateFieldName('Token', TableMap::TYPE_PHPNAME, $indexType)]) || is_callable([$row[TableMap::TYPE_NUM == $indexType ? 5 + $offset : static::translateFieldName('Token', TableMap::TYPE_PHPNAME, $indexType)], '__toString']) ? (string) $row[TableMap::TYPE_NUM == $indexType ? 5 + $offset : static::translateFieldName('Token', TableMap::TYPE_PHPNAME, $indexType)] : $row[TableMap::TYPE_NUM == $indexType ? 5 + $offset : static::translateFieldName('Token', TableMap::TYPE_PHPNAME, $indexType)];
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
                ? 5 + $offset
                : self::translateFieldName('Token', TableMap::TYPE_PHPNAME, $indexType)
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
        return $withPrefix ? ParticipanteTableMap::CLASS_DEFAULT : ParticipanteTableMap::OM_CLASS;
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
     * @return array (Participante object, last column rank)
     */
    public static function populateObject(array $row, int $offset = 0, string $indexType = TableMap::TYPE_NUM): array
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
     * @param Criteria $criteria Object containing the columns to add.
     * @param string|null $alias Optional table alias
     * @throws \Propel\Runtime\Exception\PropelException Any exceptions caught during processing will be
     *                         rethrown wrapped into a PropelException.
     * @return void
     */
    public static function addSelectColumns(Criteria $criteria, ?string $alias = null): void
    {
        if (null === $alias) {
            $criteria->addSelectColumn(ParticipanteTableMap::COL_NOME);
            $criteria->addSelectColumn(ParticipanteTableMap::COL_FUNCAO);
            $criteria->addSelectColumn(ParticipanteTableMap::COL_CPF);
            $criteria->addSelectColumn(ParticipanteTableMap::COL_DOCUMENTO_ESTRANGEIRO);
            $criteria->addSelectColumn(ParticipanteTableMap::COL_EVENTO);
            $criteria->addSelectColumn(ParticipanteTableMap::COL_TOKEN);
            $criteria->addSelectColumn(ParticipanteTableMap::COL_CRIADO_POR);
            $criteria->addSelectColumn(ParticipanteTableMap::COL_CRIADO_EM);
            $criteria->addSelectColumn(ParticipanteTableMap::COL_LOTE_ID);
        } else {
            $criteria->addSelectColumn($alias . '.nome');
            $criteria->addSelectColumn($alias . '.funcao');
            $criteria->addSelectColumn($alias . '.cpf');
            $criteria->addSelectColumn($alias . '.documento_estrangeiro');
            $criteria->addSelectColumn($alias . '.evento');
            $criteria->addSelectColumn($alias . '.token');
            $criteria->addSelectColumn($alias . '.criado_por');
            $criteria->addSelectColumn($alias . '.criado_em');
            $criteria->addSelectColumn($alias . '.lote_id');
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
            $criteria->removeSelectColumn(ParticipanteTableMap::COL_NOME);
            $criteria->removeSelectColumn(ParticipanteTableMap::COL_FUNCAO);
            $criteria->removeSelectColumn(ParticipanteTableMap::COL_CPF);
            $criteria->removeSelectColumn(ParticipanteTableMap::COL_DOCUMENTO_ESTRANGEIRO);
            $criteria->removeSelectColumn(ParticipanteTableMap::COL_EVENTO);
            $criteria->removeSelectColumn(ParticipanteTableMap::COL_TOKEN);
            $criteria->removeSelectColumn(ParticipanteTableMap::COL_CRIADO_POR);
            $criteria->removeSelectColumn(ParticipanteTableMap::COL_CRIADO_EM);
            $criteria->removeSelectColumn(ParticipanteTableMap::COL_LOTE_ID);
        } else {
            $criteria->removeSelectColumn($alias . '.nome');
            $criteria->removeSelectColumn($alias . '.funcao');
            $criteria->removeSelectColumn($alias . '.cpf');
            $criteria->removeSelectColumn($alias . '.documento_estrangeiro');
            $criteria->removeSelectColumn($alias . '.evento');
            $criteria->removeSelectColumn($alias . '.token');
            $criteria->removeSelectColumn($alias . '.criado_por');
            $criteria->removeSelectColumn($alias . '.criado_em');
            $criteria->removeSelectColumn($alias . '.lote_id');
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
        return Propel::getServiceContainer()->getDatabaseMap(ParticipanteTableMap::DATABASE_NAME)->getTable(ParticipanteTableMap::TABLE_NAME);
    }

    /**
     * Performs a DELETE on the database, given a Participante or Criteria object OR a primary key value.
     *
     * @param mixed $values Criteria or Participante object or primary key or array of primary keys
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
            $criteria->add(ParticipanteTableMap::COL_TOKEN, (array) $values, Criteria::IN);
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
    public static function doDeleteAll(?ConnectionInterface $con = null): int
    {
        return ParticipanteQuery::create()->doDeleteAll($con);
    }

    /**
     * Performs an INSERT on the database, given a Participante or Criteria object.
     *
     * @param mixed $criteria Criteria or Participante object containing data that is used to create the INSERT statement.
     * @param ConnectionInterface $con the ConnectionInterface connection to use
     * @return mixed The new primary key.
     * @throws \Propel\Runtime\Exception\PropelException Any exceptions caught during processing will be
     *                         rethrown wrapped into a PropelException.
     */
    public static function doInsert($criteria, ?ConnectionInterface $con = null)
    {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(ParticipanteTableMap::DATABASE_NAME);
        }

        if ($criteria instanceof Criteria) {
            $criteria = clone $criteria; // rename for clarity
        } else {
            $criteria = $criteria->buildCriteria(); // build Criteria from Participante object
        }


        // Set the correct dbName
        $query = ParticipanteQuery::create()->mergeWith($criteria);

        // use transaction because $criteria could contain info
        // for more than one table (I guess, conceivably)
        return $con->transaction(function () use ($con, $query) {
            return $query->doInsert($con);
        });
    }

}
