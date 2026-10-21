<?php

namespace Baja\Model\Map;

use Baja\Model\ContagemArquivo;
use Baja\Model\ContagemArquivoQuery;
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
 * This class defines the structure of the 'contagem_arquivo' table.
 *
 *
 *
 * This map class is used by Propel to do runtime db structure discovery.
 * For example, the createSelectSql() method checks the type of a given column used in an
 * ORDER BY clause to know whether it needs to apply SQL to make the ORDER BY case-insensitive
 * (i.e. if it's a text column type).
 */
class ContagemArquivoTableMap extends TableMap
{
    use InstancePoolTrait;
    use TableMapTrait;

    /**
     * The (dot-path) name of this class
     */
    public const CLASS_NAME = 'Baja.Model.Map.ContagemArquivoTableMap';

    /**
     * The default database name for this class
     */
    public const DATABASE_NAME = 'resultados';

    /**
     * The table name for this class
     */
    public const TABLE_NAME = 'contagem_arquivo';

    /**
     * The PHP name of this class (PascalCase)
     */
    public const TABLE_PHP_NAME = 'ContagemArquivo';

    /**
     * The related Propel class for this table
     */
    public const OM_CLASS = '\\Baja\\Model\\ContagemArquivo';

    /**
     * A class that can be returned by this tableMap
     */
    public const CLASS_DEFAULT = 'Baja.Model.ContagemArquivo';

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
     * the column name for the arquivo_id field
     */
    public const COL_ARQUIVO_ID = 'contagem_arquivo.arquivo_id';

    /**
     * the column name for the evento_id field
     */
    public const COL_EVENTO_ID = 'contagem_arquivo.evento_id';

    /**
     * the column name for the prova_id field
     */
    public const COL_PROVA_ID = 'contagem_arquivo.prova_id';

    /**
     * the column name for the arquivado_em_ms field
     */
    public const COL_ARQUIVADO_EM_MS = 'contagem_arquivo.arquivado_em_ms';

    /**
     * the column name for the arquivado_por field
     */
    public const COL_ARQUIVADO_POR = 'contagem_arquivo.arquivado_por';

    /**
     * the column name for the tabela field
     */
    public const COL_TABELA = 'contagem_arquivo.tabela';

    /**
     * the column name for the dados field
     */
    public const COL_DADOS = 'contagem_arquivo.dados';

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
        self::TYPE_PHPNAME       => ['ArquivoId', 'EventoId', 'ProvaId', 'ArquivadoEmMs', 'ArquivadoPor', 'Tabela', 'Dados', ],
        self::TYPE_CAMELNAME     => ['arquivoId', 'eventoId', 'provaId', 'arquivadoEmMs', 'arquivadoPor', 'tabela', 'dados', ],
        self::TYPE_COLNAME       => [ContagemArquivoTableMap::COL_ARQUIVO_ID, ContagemArquivoTableMap::COL_EVENTO_ID, ContagemArquivoTableMap::COL_PROVA_ID, ContagemArquivoTableMap::COL_ARQUIVADO_EM_MS, ContagemArquivoTableMap::COL_ARQUIVADO_POR, ContagemArquivoTableMap::COL_TABELA, ContagemArquivoTableMap::COL_DADOS, ],
        self::TYPE_FIELDNAME     => ['arquivo_id', 'evento_id', 'prova_id', 'arquivado_em_ms', 'arquivado_por', 'tabela', 'dados', ],
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
        self::TYPE_PHPNAME       => ['ArquivoId' => 0, 'EventoId' => 1, 'ProvaId' => 2, 'ArquivadoEmMs' => 3, 'ArquivadoPor' => 4, 'Tabela' => 5, 'Dados' => 6, ],
        self::TYPE_CAMELNAME     => ['arquivoId' => 0, 'eventoId' => 1, 'provaId' => 2, 'arquivadoEmMs' => 3, 'arquivadoPor' => 4, 'tabela' => 5, 'dados' => 6, ],
        self::TYPE_COLNAME       => [ContagemArquivoTableMap::COL_ARQUIVO_ID => 0, ContagemArquivoTableMap::COL_EVENTO_ID => 1, ContagemArquivoTableMap::COL_PROVA_ID => 2, ContagemArquivoTableMap::COL_ARQUIVADO_EM_MS => 3, ContagemArquivoTableMap::COL_ARQUIVADO_POR => 4, ContagemArquivoTableMap::COL_TABELA => 5, ContagemArquivoTableMap::COL_DADOS => 6, ],
        self::TYPE_FIELDNAME     => ['arquivo_id' => 0, 'evento_id' => 1, 'prova_id' => 2, 'arquivado_em_ms' => 3, 'arquivado_por' => 4, 'tabela' => 5, 'dados' => 6, ],
        self::TYPE_NUM           => [0, 1, 2, 3, 4, 5, 6, ]
    ];

    /**
     * Holds a list of column names and their normalized version.
     *
     * @var array<string>
     */
    protected $normalizedColumnNameMap = [
        'ArquivoId' => 'ARQUIVO_ID',
        'ContagemArquivo.ArquivoId' => 'ARQUIVO_ID',
        'arquivoId' => 'ARQUIVO_ID',
        'contagemArquivo.arquivoId' => 'ARQUIVO_ID',
        'ContagemArquivoTableMap::COL_ARQUIVO_ID' => 'ARQUIVO_ID',
        'COL_ARQUIVO_ID' => 'ARQUIVO_ID',
        'arquivo_id' => 'ARQUIVO_ID',
        'contagem_arquivo.arquivo_id' => 'ARQUIVO_ID',
        'EventoId' => 'EVENTO_ID',
        'ContagemArquivo.EventoId' => 'EVENTO_ID',
        'eventoId' => 'EVENTO_ID',
        'contagemArquivo.eventoId' => 'EVENTO_ID',
        'ContagemArquivoTableMap::COL_EVENTO_ID' => 'EVENTO_ID',
        'COL_EVENTO_ID' => 'EVENTO_ID',
        'evento_id' => 'EVENTO_ID',
        'contagem_arquivo.evento_id' => 'EVENTO_ID',
        'ProvaId' => 'PROVA_ID',
        'ContagemArquivo.ProvaId' => 'PROVA_ID',
        'provaId' => 'PROVA_ID',
        'contagemArquivo.provaId' => 'PROVA_ID',
        'ContagemArquivoTableMap::COL_PROVA_ID' => 'PROVA_ID',
        'COL_PROVA_ID' => 'PROVA_ID',
        'prova_id' => 'PROVA_ID',
        'contagem_arquivo.prova_id' => 'PROVA_ID',
        'ArquivadoEmMs' => 'ARQUIVADO_EM_MS',
        'ContagemArquivo.ArquivadoEmMs' => 'ARQUIVADO_EM_MS',
        'arquivadoEmMs' => 'ARQUIVADO_EM_MS',
        'contagemArquivo.arquivadoEmMs' => 'ARQUIVADO_EM_MS',
        'ContagemArquivoTableMap::COL_ARQUIVADO_EM_MS' => 'ARQUIVADO_EM_MS',
        'COL_ARQUIVADO_EM_MS' => 'ARQUIVADO_EM_MS',
        'arquivado_em_ms' => 'ARQUIVADO_EM_MS',
        'contagem_arquivo.arquivado_em_ms' => 'ARQUIVADO_EM_MS',
        'ArquivadoPor' => 'ARQUIVADO_POR',
        'ContagemArquivo.ArquivadoPor' => 'ARQUIVADO_POR',
        'arquivadoPor' => 'ARQUIVADO_POR',
        'contagemArquivo.arquivadoPor' => 'ARQUIVADO_POR',
        'ContagemArquivoTableMap::COL_ARQUIVADO_POR' => 'ARQUIVADO_POR',
        'COL_ARQUIVADO_POR' => 'ARQUIVADO_POR',
        'arquivado_por' => 'ARQUIVADO_POR',
        'contagem_arquivo.arquivado_por' => 'ARQUIVADO_POR',
        'Tabela' => 'TABELA',
        'ContagemArquivo.Tabela' => 'TABELA',
        'tabela' => 'TABELA',
        'contagemArquivo.tabela' => 'TABELA',
        'ContagemArquivoTableMap::COL_TABELA' => 'TABELA',
        'COL_TABELA' => 'TABELA',
        'contagem_arquivo.tabela' => 'TABELA',
        'Dados' => 'DADOS',
        'ContagemArquivo.Dados' => 'DADOS',
        'dados' => 'DADOS',
        'contagemArquivo.dados' => 'DADOS',
        'ContagemArquivoTableMap::COL_DADOS' => 'DADOS',
        'COL_DADOS' => 'DADOS',
        'contagem_arquivo.dados' => 'DADOS',
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
        $this->setName('contagem_arquivo');
        $this->setPhpName('ContagemArquivo');
        $this->setIdentifierQuoting(false);
        $this->setClassName('\\Baja\\Model\\ContagemArquivo');
        $this->setPackage('Baja.Model');
        $this->setUseIdGenerator(true);
        // columns
        $this->addPrimaryKey('arquivo_id', 'ArquivoId', 'BIGINT', true, null, null);
        $this->addColumn('evento_id', 'EventoId', 'CHAR', true, 4, null);
        $this->addColumn('prova_id', 'ProvaId', 'CHAR', true, 3, null);
        $this->addColumn('arquivado_em_ms', 'ArquivadoEmMs', 'BIGINT', true, null, null);
        $this->addColumn('arquivado_por', 'ArquivadoPor', 'VARCHAR', false, 45, null);
        $this->addColumn('tabela', 'Tabela', 'VARCHAR', true, 32, null);
        $this->addColumn('dados', 'Dados', 'LONGVARCHAR', true, null, null);
    }

    /**
     * Build the RelationMap objects for this table relationships
     *
     * @return void
     */
    public function buildRelations(): void
    {
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
        if ($row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('ArquivoId', TableMap::TYPE_PHPNAME, $indexType)] === null) {
            return null;
        }

        return null === $row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('ArquivoId', TableMap::TYPE_PHPNAME, $indexType)] || is_scalar($row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('ArquivoId', TableMap::TYPE_PHPNAME, $indexType)]) || is_callable([$row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('ArquivoId', TableMap::TYPE_PHPNAME, $indexType)], '__toString']) ? (string) $row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('ArquivoId', TableMap::TYPE_PHPNAME, $indexType)] : $row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('ArquivoId', TableMap::TYPE_PHPNAME, $indexType)];
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
                : self::translateFieldName('ArquivoId', TableMap::TYPE_PHPNAME, $indexType)
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
        return $withPrefix ? ContagemArquivoTableMap::CLASS_DEFAULT : ContagemArquivoTableMap::OM_CLASS;
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
     * @return array (ContagemArquivo object, last column rank)
     */
    public static function populateObject(array $row, int $offset = 0, string $indexType = TableMap::TYPE_NUM): array
    {
        $key = ContagemArquivoTableMap::getPrimaryKeyHashFromRow($row, $offset, $indexType);
        if (null !== ($obj = ContagemArquivoTableMap::getInstanceFromPool($key))) {
            // We no longer rehydrate the object, since this can cause data loss.
            // See http://www.propelorm.org/ticket/509
            // $obj->hydrate($row, $offset, true); // rehydrate
            $col = $offset + ContagemArquivoTableMap::NUM_HYDRATE_COLUMNS;
        } else {
            $cls = ContagemArquivoTableMap::OM_CLASS;
            /** @var ContagemArquivo $obj */
            $obj = new $cls();
            $col = $obj->hydrate($row, $offset, false, $indexType);
            ContagemArquivoTableMap::addInstanceToPool($obj, $key);
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
            $key = ContagemArquivoTableMap::getPrimaryKeyHashFromRow($row, 0, $dataFetcher->getIndexType());
            if (null !== ($obj = ContagemArquivoTableMap::getInstanceFromPool($key))) {
                // We no longer rehydrate the object, since this can cause data loss.
                // See http://www.propelorm.org/ticket/509
                // $obj->hydrate($row, 0, true); // rehydrate
                $results[] = $obj;
            } else {
                /** @var ContagemArquivo $obj */
                $obj = new $cls();
                $obj->hydrate($row);
                $results[] = $obj;
                ContagemArquivoTableMap::addInstanceToPool($obj, $key);
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
            $criteria->addSelectColumn(ContagemArquivoTableMap::COL_ARQUIVO_ID);
            $criteria->addSelectColumn(ContagemArquivoTableMap::COL_EVENTO_ID);
            $criteria->addSelectColumn(ContagemArquivoTableMap::COL_PROVA_ID);
            $criteria->addSelectColumn(ContagemArquivoTableMap::COL_ARQUIVADO_EM_MS);
            $criteria->addSelectColumn(ContagemArquivoTableMap::COL_ARQUIVADO_POR);
            $criteria->addSelectColumn(ContagemArquivoTableMap::COL_TABELA);
            $criteria->addSelectColumn(ContagemArquivoTableMap::COL_DADOS);
        } else {
            $criteria->addSelectColumn($alias . '.arquivo_id');
            $criteria->addSelectColumn($alias . '.evento_id');
            $criteria->addSelectColumn($alias . '.prova_id');
            $criteria->addSelectColumn($alias . '.arquivado_em_ms');
            $criteria->addSelectColumn($alias . '.arquivado_por');
            $criteria->addSelectColumn($alias . '.tabela');
            $criteria->addSelectColumn($alias . '.dados');
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
            $criteria->removeSelectColumn(ContagemArquivoTableMap::COL_ARQUIVO_ID);
            $criteria->removeSelectColumn(ContagemArquivoTableMap::COL_EVENTO_ID);
            $criteria->removeSelectColumn(ContagemArquivoTableMap::COL_PROVA_ID);
            $criteria->removeSelectColumn(ContagemArquivoTableMap::COL_ARQUIVADO_EM_MS);
            $criteria->removeSelectColumn(ContagemArquivoTableMap::COL_ARQUIVADO_POR);
            $criteria->removeSelectColumn(ContagemArquivoTableMap::COL_TABELA);
            $criteria->removeSelectColumn(ContagemArquivoTableMap::COL_DADOS);
        } else {
            $criteria->removeSelectColumn($alias . '.arquivo_id');
            $criteria->removeSelectColumn($alias . '.evento_id');
            $criteria->removeSelectColumn($alias . '.prova_id');
            $criteria->removeSelectColumn($alias . '.arquivado_em_ms');
            $criteria->removeSelectColumn($alias . '.arquivado_por');
            $criteria->removeSelectColumn($alias . '.tabela');
            $criteria->removeSelectColumn($alias . '.dados');
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
        return Propel::getServiceContainer()->getDatabaseMap(ContagemArquivoTableMap::DATABASE_NAME)->getTable(ContagemArquivoTableMap::TABLE_NAME);
    }

    /**
     * Performs a DELETE on the database, given a ContagemArquivo or Criteria object OR a primary key value.
     *
     * @param mixed $values Criteria or ContagemArquivo object or primary key or array of primary keys
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
            $con = Propel::getServiceContainer()->getWriteConnection(ContagemArquivoTableMap::DATABASE_NAME);
        }

        if ($values instanceof Criteria) {
            // rename for clarity
            $criteria = $values;
        } elseif ($values instanceof \Baja\Model\ContagemArquivo) { // it's a model object
            // create criteria based on pk values
            $criteria = $values->buildPkeyCriteria();
        } else { // it's a primary key, or an array of pks
            $criteria = new Criteria(ContagemArquivoTableMap::DATABASE_NAME);
            $criteria->add(ContagemArquivoTableMap::COL_ARQUIVO_ID, (array) $values, Criteria::IN);
        }

        $query = ContagemArquivoQuery::create()->mergeWith($criteria);

        if ($values instanceof Criteria) {
            ContagemArquivoTableMap::clearInstancePool();
        } elseif (!is_object($values)) { // it's a primary key, or an array of pks
            foreach ((array) $values as $singleval) {
                ContagemArquivoTableMap::removeInstanceFromPool($singleval);
            }
        }

        return $query->delete($con);
    }

    /**
     * Deletes all rows from the contagem_arquivo table.
     *
     * @param ConnectionInterface $con the connection to use
     * @return int The number of affected rows (if supported by underlying database driver).
     */
    public static function doDeleteAll(?ConnectionInterface $con = null): int
    {
        return ContagemArquivoQuery::create()->doDeleteAll($con);
    }

    /**
     * Performs an INSERT on the database, given a ContagemArquivo or Criteria object.
     *
     * @param mixed $criteria Criteria or ContagemArquivo object containing data that is used to create the INSERT statement.
     * @param ConnectionInterface $con the ConnectionInterface connection to use
     * @return mixed The new primary key.
     * @throws \Propel\Runtime\Exception\PropelException Any exceptions caught during processing will be
     *                         rethrown wrapped into a PropelException.
     */
    public static function doInsert($criteria, ?ConnectionInterface $con = null)
    {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(ContagemArquivoTableMap::DATABASE_NAME);
        }

        if ($criteria instanceof Criteria) {
            $criteria = clone $criteria; // rename for clarity
        } else {
            $criteria = $criteria->buildCriteria(); // build Criteria from ContagemArquivo object
        }

        if ($criteria->containsKey(ContagemArquivoTableMap::COL_ARQUIVO_ID) && $criteria->keyContainsValue(ContagemArquivoTableMap::COL_ARQUIVO_ID) ) {
            throw new PropelException('Cannot insert a value for auto-increment primary key ('.ContagemArquivoTableMap::COL_ARQUIVO_ID.')');
        }


        // Set the correct dbName
        $query = ContagemArquivoQuery::create()->mergeWith($criteria);

        // use transaction because $criteria could contain info
        // for more than one table (I guess, conceivably)
        return $con->transaction(function () use ($con, $query) {
            return $query->doInsert($con);
        });
    }

}
