<?php

namespace Baja\Model\Map;

use Baja\Model\ContagemFonte;
use Baja\Model\ContagemFonteQuery;
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
 * This class defines the structure of the 'contagem_fonte' table.
 *
 *
 *
 * This map class is used by Propel to do runtime db structure discovery.
 * For example, the createSelectSql() method checks the type of a given column used in an
 * ORDER BY clause to know whether it needs to apply SQL to make the ORDER BY case-insensitive
 * (i.e. if it's a text column type).
 */
class ContagemFonteTableMap extends TableMap
{
    use InstancePoolTrait;
    use TableMapTrait;

    /**
     * The (dot-path) name of this class
     */
    public const CLASS_NAME = 'Baja.Model.Map.ContagemFonteTableMap';

    /**
     * The default database name for this class
     */
    public const DATABASE_NAME = 'resultados';

    /**
     * The table name for this class
     */
    public const TABLE_NAME = 'contagem_fonte';

    /**
     * The PHP name of this class (PascalCase)
     */
    public const TABLE_PHP_NAME = 'ContagemFonte';

    /**
     * The related Propel class for this table
     */
    public const OM_CLASS = '\\Baja\\Model\\ContagemFonte';

    /**
     * A class that can be returned by this tableMap
     */
    public const CLASS_DEFAULT = 'Baja.Model.ContagemFonte';

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
     * the column name for the fonte_id field
     */
    public const COL_FONTE_ID = 'contagem_fonte.fonte_id';

    /**
     * the column name for the evento_id field
     */
    public const COL_EVENTO_ID = 'contagem_fonte.evento_id';

    /**
     * the column name for the prova_id field
     */
    public const COL_PROVA_ID = 'contagem_fonte.prova_id';

    /**
     * the column name for the nome field
     */
    public const COL_NOME = 'contagem_fonte.nome';

    /**
     * the column name for the tipo field
     */
    public const COL_TIPO = 'contagem_fonte.tipo';

    /**
     * the column name for the qualidade_tempo field
     */
    public const COL_QUALIDADE_TEMPO = 'contagem_fonte.qualidade_tempo';

    /**
     * the column name for the ativa field
     */
    public const COL_ATIVA = 'contagem_fonte.ativa';

    /**
     * the column name for the ordem field
     */
    public const COL_ORDEM = 'contagem_fonte.ordem';

    /**
     * the column name for the desativada_em_ms field
     */
    public const COL_DESATIVADA_EM_MS = 'contagem_fonte.desativada_em_ms';

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
        self::TYPE_PHPNAME       => ['FonteId', 'EventoId', 'ProvaId', 'Nome', 'Tipo', 'QualidadeTempo', 'Ativa', 'Ordem', 'DesativadaEmMs', ],
        self::TYPE_CAMELNAME     => ['fonteId', 'eventoId', 'provaId', 'nome', 'tipo', 'qualidadeTempo', 'ativa', 'ordem', 'desativadaEmMs', ],
        self::TYPE_COLNAME       => [ContagemFonteTableMap::COL_FONTE_ID, ContagemFonteTableMap::COL_EVENTO_ID, ContagemFonteTableMap::COL_PROVA_ID, ContagemFonteTableMap::COL_NOME, ContagemFonteTableMap::COL_TIPO, ContagemFonteTableMap::COL_QUALIDADE_TEMPO, ContagemFonteTableMap::COL_ATIVA, ContagemFonteTableMap::COL_ORDEM, ContagemFonteTableMap::COL_DESATIVADA_EM_MS, ],
        self::TYPE_FIELDNAME     => ['fonte_id', 'evento_id', 'prova_id', 'nome', 'tipo', 'qualidade_tempo', 'ativa', 'ordem', 'desativada_em_ms', ],
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
        self::TYPE_PHPNAME       => ['FonteId' => 0, 'EventoId' => 1, 'ProvaId' => 2, 'Nome' => 3, 'Tipo' => 4, 'QualidadeTempo' => 5, 'Ativa' => 6, 'Ordem' => 7, 'DesativadaEmMs' => 8, ],
        self::TYPE_CAMELNAME     => ['fonteId' => 0, 'eventoId' => 1, 'provaId' => 2, 'nome' => 3, 'tipo' => 4, 'qualidadeTempo' => 5, 'ativa' => 6, 'ordem' => 7, 'desativadaEmMs' => 8, ],
        self::TYPE_COLNAME       => [ContagemFonteTableMap::COL_FONTE_ID => 0, ContagemFonteTableMap::COL_EVENTO_ID => 1, ContagemFonteTableMap::COL_PROVA_ID => 2, ContagemFonteTableMap::COL_NOME => 3, ContagemFonteTableMap::COL_TIPO => 4, ContagemFonteTableMap::COL_QUALIDADE_TEMPO => 5, ContagemFonteTableMap::COL_ATIVA => 6, ContagemFonteTableMap::COL_ORDEM => 7, ContagemFonteTableMap::COL_DESATIVADA_EM_MS => 8, ],
        self::TYPE_FIELDNAME     => ['fonte_id' => 0, 'evento_id' => 1, 'prova_id' => 2, 'nome' => 3, 'tipo' => 4, 'qualidade_tempo' => 5, 'ativa' => 6, 'ordem' => 7, 'desativada_em_ms' => 8, ],
        self::TYPE_NUM           => [0, 1, 2, 3, 4, 5, 6, 7, 8, ]
    ];

    /**
     * Holds a list of column names and their normalized version.
     *
     * @var array<string>
     */
    protected $normalizedColumnNameMap = [
        'FonteId' => 'FONTE_ID',
        'ContagemFonte.FonteId' => 'FONTE_ID',
        'fonteId' => 'FONTE_ID',
        'contagemFonte.fonteId' => 'FONTE_ID',
        'ContagemFonteTableMap::COL_FONTE_ID' => 'FONTE_ID',
        'COL_FONTE_ID' => 'FONTE_ID',
        'fonte_id' => 'FONTE_ID',
        'contagem_fonte.fonte_id' => 'FONTE_ID',
        'EventoId' => 'EVENTO_ID',
        'ContagemFonte.EventoId' => 'EVENTO_ID',
        'eventoId' => 'EVENTO_ID',
        'contagemFonte.eventoId' => 'EVENTO_ID',
        'ContagemFonteTableMap::COL_EVENTO_ID' => 'EVENTO_ID',
        'COL_EVENTO_ID' => 'EVENTO_ID',
        'evento_id' => 'EVENTO_ID',
        'contagem_fonte.evento_id' => 'EVENTO_ID',
        'ProvaId' => 'PROVA_ID',
        'ContagemFonte.ProvaId' => 'PROVA_ID',
        'provaId' => 'PROVA_ID',
        'contagemFonte.provaId' => 'PROVA_ID',
        'ContagemFonteTableMap::COL_PROVA_ID' => 'PROVA_ID',
        'COL_PROVA_ID' => 'PROVA_ID',
        'prova_id' => 'PROVA_ID',
        'contagem_fonte.prova_id' => 'PROVA_ID',
        'Nome' => 'NOME',
        'ContagemFonte.Nome' => 'NOME',
        'nome' => 'NOME',
        'contagemFonte.nome' => 'NOME',
        'ContagemFonteTableMap::COL_NOME' => 'NOME',
        'COL_NOME' => 'NOME',
        'contagem_fonte.nome' => 'NOME',
        'Tipo' => 'TIPO',
        'ContagemFonte.Tipo' => 'TIPO',
        'tipo' => 'TIPO',
        'contagemFonte.tipo' => 'TIPO',
        'ContagemFonteTableMap::COL_TIPO' => 'TIPO',
        'COL_TIPO' => 'TIPO',
        'contagem_fonte.tipo' => 'TIPO',
        'QualidadeTempo' => 'QUALIDADE_TEMPO',
        'ContagemFonte.QualidadeTempo' => 'QUALIDADE_TEMPO',
        'qualidadeTempo' => 'QUALIDADE_TEMPO',
        'contagemFonte.qualidadeTempo' => 'QUALIDADE_TEMPO',
        'ContagemFonteTableMap::COL_QUALIDADE_TEMPO' => 'QUALIDADE_TEMPO',
        'COL_QUALIDADE_TEMPO' => 'QUALIDADE_TEMPO',
        'qualidade_tempo' => 'QUALIDADE_TEMPO',
        'contagem_fonte.qualidade_tempo' => 'QUALIDADE_TEMPO',
        'Ativa' => 'ATIVA',
        'ContagemFonte.Ativa' => 'ATIVA',
        'ativa' => 'ATIVA',
        'contagemFonte.ativa' => 'ATIVA',
        'ContagemFonteTableMap::COL_ATIVA' => 'ATIVA',
        'COL_ATIVA' => 'ATIVA',
        'contagem_fonte.ativa' => 'ATIVA',
        'Ordem' => 'ORDEM',
        'ContagemFonte.Ordem' => 'ORDEM',
        'ordem' => 'ORDEM',
        'contagemFonte.ordem' => 'ORDEM',
        'ContagemFonteTableMap::COL_ORDEM' => 'ORDEM',
        'COL_ORDEM' => 'ORDEM',
        'contagem_fonte.ordem' => 'ORDEM',
        'DesativadaEmMs' => 'DESATIVADA_EM_MS',
        'ContagemFonte.DesativadaEmMs' => 'DESATIVADA_EM_MS',
        'desativadaEmMs' => 'DESATIVADA_EM_MS',
        'contagemFonte.desativadaEmMs' => 'DESATIVADA_EM_MS',
        'ContagemFonteTableMap::COL_DESATIVADA_EM_MS' => 'DESATIVADA_EM_MS',
        'COL_DESATIVADA_EM_MS' => 'DESATIVADA_EM_MS',
        'desativada_em_ms' => 'DESATIVADA_EM_MS',
        'contagem_fonte.desativada_em_ms' => 'DESATIVADA_EM_MS',
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
        $this->setName('contagem_fonte');
        $this->setPhpName('ContagemFonte');
        $this->setIdentifierQuoting(false);
        $this->setClassName('\\Baja\\Model\\ContagemFonte');
        $this->setPackage('Baja.Model');
        $this->setUseIdGenerator(true);
        // columns
        $this->addPrimaryKey('fonte_id', 'FonteId', 'INTEGER', true, null, null);
        $this->addForeignKey('evento_id', 'EventoId', 'CHAR', 'prova', 'evento_id', true, 4, null);
        $this->addForeignKey('prova_id', 'ProvaId', 'CHAR', 'prova', 'prova_id', true, 3, null);
        $this->addColumn('nome', 'Nome', 'VARCHAR', true, 45, null);
        $this->addColumn('tipo', 'Tipo', 'VARCHAR', true, 12, 'manual');
        $this->addColumn('qualidade_tempo', 'QualidadeTempo', 'VARCHAR', true, 8, 'humana');
        $this->addColumn('ativa', 'Ativa', 'BOOLEAN', true, 1, true);
        $this->addColumn('ordem', 'Ordem', 'INTEGER', true, null, 0);
        $this->addColumn('desativada_em_ms', 'DesativadaEmMs', 'BIGINT', false, null, null);
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
), 'RESTRICT', 'CASCADE', null, false);
        $this->addRelation('ContagemFonteUsuario', '\\Baja\\Model\\ContagemFonteUsuario', RelationMap::ONE_TO_MANY, array (
  0 =>
  array (
    0 => ':fonte_id',
    1 => ':fonte_id',
  ),
), 'CASCADE', 'CASCADE', 'ContagemFonteUsuarios', false);
        $this->addRelation('ContagemDispositivo', '\\Baja\\Model\\ContagemDispositivo', RelationMap::ONE_TO_MANY, array (
  0 =>
  array (
    0 => ':fonte_id',
    1 => ':fonte_id',
  ),
), 'CASCADE', 'CASCADE', 'ContagemDispositivos', false);
        $this->addRelation('ContagemPassagem', '\\Baja\\Model\\ContagemPassagem', RelationMap::ONE_TO_MANY, array (
  0 =>
  array (
    0 => ':fonte_id',
    1 => ':fonte_id',
  ),
), 'CASCADE', 'CASCADE', 'ContagemPassagems', false);
        $this->addRelation('ContagemTotal', '\\Baja\\Model\\ContagemTotal', RelationMap::ONE_TO_MANY, array (
  0 =>
  array (
    0 => ':fonte_id',
    1 => ':fonte_id',
  ),
), 'CASCADE', 'CASCADE', 'ContagemTotals', false);
    }

    /**
     * Method to invalidate the instance pool of all tables related to contagem_fonte     * by a foreign key with ON DELETE CASCADE
     */
    public static function clearRelatedInstancePool(): void
    {
        // Invalidate objects in related instance pools,
        // since one or more of them may be deleted by ON DELETE CASCADE/SETNULL rule.
        ContagemFonteUsuarioTableMap::clearInstancePool();
        ContagemDispositivoTableMap::clearInstancePool();
        ContagemPassagemTableMap::clearInstancePool();
        ContagemTotalTableMap::clearInstancePool();
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
        if ($row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('FonteId', TableMap::TYPE_PHPNAME, $indexType)] === null) {
            return null;
        }

        return null === $row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('FonteId', TableMap::TYPE_PHPNAME, $indexType)] || is_scalar($row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('FonteId', TableMap::TYPE_PHPNAME, $indexType)]) || is_callable([$row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('FonteId', TableMap::TYPE_PHPNAME, $indexType)], '__toString']) ? (string) $row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('FonteId', TableMap::TYPE_PHPNAME, $indexType)] : $row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('FonteId', TableMap::TYPE_PHPNAME, $indexType)];
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
                : self::translateFieldName('FonteId', TableMap::TYPE_PHPNAME, $indexType)
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
        return $withPrefix ? ContagemFonteTableMap::CLASS_DEFAULT : ContagemFonteTableMap::OM_CLASS;
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
     * @return array (ContagemFonte object, last column rank)
     */
    public static function populateObject(array $row, int $offset = 0, string $indexType = TableMap::TYPE_NUM): array
    {
        $key = ContagemFonteTableMap::getPrimaryKeyHashFromRow($row, $offset, $indexType);
        if (null !== ($obj = ContagemFonteTableMap::getInstanceFromPool($key))) {
            // We no longer rehydrate the object, since this can cause data loss.
            // See http://www.propelorm.org/ticket/509
            // $obj->hydrate($row, $offset, true); // rehydrate
            $col = $offset + ContagemFonteTableMap::NUM_HYDRATE_COLUMNS;
        } else {
            $cls = ContagemFonteTableMap::OM_CLASS;
            /** @var ContagemFonte $obj */
            $obj = new $cls();
            $col = $obj->hydrate($row, $offset, false, $indexType);
            ContagemFonteTableMap::addInstanceToPool($obj, $key);
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
            $key = ContagemFonteTableMap::getPrimaryKeyHashFromRow($row, 0, $dataFetcher->getIndexType());
            if (null !== ($obj = ContagemFonteTableMap::getInstanceFromPool($key))) {
                // We no longer rehydrate the object, since this can cause data loss.
                // See http://www.propelorm.org/ticket/509
                // $obj->hydrate($row, 0, true); // rehydrate
                $results[] = $obj;
            } else {
                /** @var ContagemFonte $obj */
                $obj = new $cls();
                $obj->hydrate($row);
                $results[] = $obj;
                ContagemFonteTableMap::addInstanceToPool($obj, $key);
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
            $criteria->addSelectColumn(ContagemFonteTableMap::COL_FONTE_ID);
            $criteria->addSelectColumn(ContagemFonteTableMap::COL_EVENTO_ID);
            $criteria->addSelectColumn(ContagemFonteTableMap::COL_PROVA_ID);
            $criteria->addSelectColumn(ContagemFonteTableMap::COL_NOME);
            $criteria->addSelectColumn(ContagemFonteTableMap::COL_TIPO);
            $criteria->addSelectColumn(ContagemFonteTableMap::COL_QUALIDADE_TEMPO);
            $criteria->addSelectColumn(ContagemFonteTableMap::COL_ATIVA);
            $criteria->addSelectColumn(ContagemFonteTableMap::COL_ORDEM);
            $criteria->addSelectColumn(ContagemFonteTableMap::COL_DESATIVADA_EM_MS);
        } else {
            $criteria->addSelectColumn($alias . '.fonte_id');
            $criteria->addSelectColumn($alias . '.evento_id');
            $criteria->addSelectColumn($alias . '.prova_id');
            $criteria->addSelectColumn($alias . '.nome');
            $criteria->addSelectColumn($alias . '.tipo');
            $criteria->addSelectColumn($alias . '.qualidade_tempo');
            $criteria->addSelectColumn($alias . '.ativa');
            $criteria->addSelectColumn($alias . '.ordem');
            $criteria->addSelectColumn($alias . '.desativada_em_ms');
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
            $criteria->removeSelectColumn(ContagemFonteTableMap::COL_FONTE_ID);
            $criteria->removeSelectColumn(ContagemFonteTableMap::COL_EVENTO_ID);
            $criteria->removeSelectColumn(ContagemFonteTableMap::COL_PROVA_ID);
            $criteria->removeSelectColumn(ContagemFonteTableMap::COL_NOME);
            $criteria->removeSelectColumn(ContagemFonteTableMap::COL_TIPO);
            $criteria->removeSelectColumn(ContagemFonteTableMap::COL_QUALIDADE_TEMPO);
            $criteria->removeSelectColumn(ContagemFonteTableMap::COL_ATIVA);
            $criteria->removeSelectColumn(ContagemFonteTableMap::COL_ORDEM);
            $criteria->removeSelectColumn(ContagemFonteTableMap::COL_DESATIVADA_EM_MS);
        } else {
            $criteria->removeSelectColumn($alias . '.fonte_id');
            $criteria->removeSelectColumn($alias . '.evento_id');
            $criteria->removeSelectColumn($alias . '.prova_id');
            $criteria->removeSelectColumn($alias . '.nome');
            $criteria->removeSelectColumn($alias . '.tipo');
            $criteria->removeSelectColumn($alias . '.qualidade_tempo');
            $criteria->removeSelectColumn($alias . '.ativa');
            $criteria->removeSelectColumn($alias . '.ordem');
            $criteria->removeSelectColumn($alias . '.desativada_em_ms');
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
        return Propel::getServiceContainer()->getDatabaseMap(ContagemFonteTableMap::DATABASE_NAME)->getTable(ContagemFonteTableMap::TABLE_NAME);
    }

    /**
     * Performs a DELETE on the database, given a ContagemFonte or Criteria object OR a primary key value.
     *
     * @param mixed $values Criteria or ContagemFonte object or primary key or array of primary keys
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
            $con = Propel::getServiceContainer()->getWriteConnection(ContagemFonteTableMap::DATABASE_NAME);
        }

        if ($values instanceof Criteria) {
            // rename for clarity
            $criteria = $values;
        } elseif ($values instanceof \Baja\Model\ContagemFonte) { // it's a model object
            // create criteria based on pk values
            $criteria = $values->buildPkeyCriteria();
        } else { // it's a primary key, or an array of pks
            $criteria = new Criteria(ContagemFonteTableMap::DATABASE_NAME);
            $criteria->add(ContagemFonteTableMap::COL_FONTE_ID, (array) $values, Criteria::IN);
        }

        $query = ContagemFonteQuery::create()->mergeWith($criteria);

        if ($values instanceof Criteria) {
            ContagemFonteTableMap::clearInstancePool();
        } elseif (!is_object($values)) { // it's a primary key, or an array of pks
            foreach ((array) $values as $singleval) {
                ContagemFonteTableMap::removeInstanceFromPool($singleval);
            }
        }

        return $query->delete($con);
    }

    /**
     * Deletes all rows from the contagem_fonte table.
     *
     * @param ConnectionInterface $con the connection to use
     * @return int The number of affected rows (if supported by underlying database driver).
     */
    public static function doDeleteAll(?ConnectionInterface $con = null): int
    {
        return ContagemFonteQuery::create()->doDeleteAll($con);
    }

    /**
     * Performs an INSERT on the database, given a ContagemFonte or Criteria object.
     *
     * @param mixed $criteria Criteria or ContagemFonte object containing data that is used to create the INSERT statement.
     * @param ConnectionInterface $con the ConnectionInterface connection to use
     * @return mixed The new primary key.
     * @throws \Propel\Runtime\Exception\PropelException Any exceptions caught during processing will be
     *                         rethrown wrapped into a PropelException.
     */
    public static function doInsert($criteria, ?ConnectionInterface $con = null)
    {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(ContagemFonteTableMap::DATABASE_NAME);
        }

        if ($criteria instanceof Criteria) {
            $criteria = clone $criteria; // rename for clarity
        } else {
            $criteria = $criteria->buildCriteria(); // build Criteria from ContagemFonte object
        }

        if ($criteria->containsKey(ContagemFonteTableMap::COL_FONTE_ID) && $criteria->keyContainsValue(ContagemFonteTableMap::COL_FONTE_ID) ) {
            throw new PropelException('Cannot insert a value for auto-increment primary key ('.ContagemFonteTableMap::COL_FONTE_ID.')');
        }


        // Set the correct dbName
        $query = ContagemFonteQuery::create()->mergeWith($criteria);

        // use transaction because $criteria could contain info
        // for more than one table (I guess, conceivably)
        return $con->transaction(function () use ($con, $query) {
            return $query->doInsert($con);
        });
    }

}
