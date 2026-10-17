<?php

namespace Baja\Model\Map;

use Baja\Model\ContagemDecisao;
use Baja\Model\ContagemDecisaoQuery;
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
 * This class defines the structure of the 'contagem_decisao' table.
 *
 *
 *
 * This map class is used by Propel to do runtime db structure discovery.
 * For example, the createSelectSql() method checks the type of a given column used in an
 * ORDER BY clause to know whether it needs to apply SQL to make the ORDER BY case-insensitive
 * (i.e. if it's a text column type).
 */
class ContagemDecisaoTableMap extends TableMap
{
    use InstancePoolTrait;
    use TableMapTrait;

    /**
     * The (dot-path) name of this class
     */
    public const CLASS_NAME = 'Baja.Model.Map.ContagemDecisaoTableMap';

    /**
     * The default database name for this class
     */
    public const DATABASE_NAME = 'resultados';

    /**
     * The table name for this class
     */
    public const TABLE_NAME = 'contagem_decisao';

    /**
     * The PHP name of this class (PascalCase)
     */
    public const TABLE_PHP_NAME = 'ContagemDecisao';

    /**
     * The related Propel class for this table
     */
    public const OM_CLASS = '\\Baja\\Model\\ContagemDecisao';

    /**
     * A class that can be returned by this tableMap
     */
    public const CLASS_DEFAULT = 'Baja.Model.ContagemDecisao';

    /**
     * The total number of columns
     */
    public const NUM_COLUMNS = 15;

    /**
     * The number of lazy-loaded columns
     */
    public const NUM_LAZY_LOAD_COLUMNS = 0;

    /**
     * The number of columns to hydrate (NUM_COLUMNS - NUM_LAZY_LOAD_COLUMNS)
     */
    public const NUM_HYDRATE_COLUMNS = 15;

    /**
     * the column name for the decisao_id field
     */
    public const COL_DECISAO_ID = 'contagem_decisao.decisao_id';

    /**
     * the column name for the evento_id field
     */
    public const COL_EVENTO_ID = 'contagem_decisao.evento_id';

    /**
     * the column name for the prova_id field
     */
    public const COL_PROVA_ID = 'contagem_decisao.prova_id';

    /**
     * the column name for the tipo field
     */
    public const COL_TIPO = 'contagem_decisao.tipo';

    /**
     * the column name for the passagem_id field
     */
    public const COL_PASSAGEM_ID = 'contagem_decisao.passagem_id';

    /**
     * the column name for the passagem_alvo_id field
     */
    public const COL_PASSAGEM_ALVO_ID = 'contagem_decisao.passagem_alvo_id';

    /**
     * the column name for the fonte_id field
     */
    public const COL_FONTE_ID = 'contagem_decisao.fonte_id';

    /**
     * the column name for the equipe_numero field
     */
    public const COL_EQUIPE_NUMERO = 'contagem_decisao.equipe_numero';

    /**
     * the column name for the ts_ms field
     */
    public const COL_TS_MS = 'contagem_decisao.ts_ms';

    /**
     * the column name for the ts_fim_ms field
     */
    public const COL_TS_FIM_MS = 'contagem_decisao.ts_fim_ms';

    /**
     * the column name for the desfaz_decisao_id field
     */
    public const COL_DESFAZ_DECISAO_ID = 'contagem_decisao.desfaz_decisao_id';

    /**
     * the column name for the origem field
     */
    public const COL_ORIGEM = 'contagem_decisao.origem';

    /**
     * the column name for the usuario field
     */
    public const COL_USUARIO = 'contagem_decisao.usuario';

    /**
     * the column name for the motivo field
     */
    public const COL_MOTIVO = 'contagem_decisao.motivo';

    /**
     * the column name for the criado_em_ms field
     */
    public const COL_CRIADO_EM_MS = 'contagem_decisao.criado_em_ms';

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
        self::TYPE_PHPNAME       => ['DecisaoId', 'EventoId', 'ProvaId', 'Tipo', 'PassagemId', 'PassagemAlvoId', 'FonteId', 'EquipeNumero', 'TsMs', 'TsFimMs', 'DesfazDecisaoId', 'Origem', 'Usuario', 'Motivo', 'CriadoEmMs', ],
        self::TYPE_CAMELNAME     => ['decisaoId', 'eventoId', 'provaId', 'tipo', 'passagemId', 'passagemAlvoId', 'fonteId', 'equipeNumero', 'tsMs', 'tsFimMs', 'desfazDecisaoId', 'origem', 'usuario', 'motivo', 'criadoEmMs', ],
        self::TYPE_COLNAME       => [ContagemDecisaoTableMap::COL_DECISAO_ID, ContagemDecisaoTableMap::COL_EVENTO_ID, ContagemDecisaoTableMap::COL_PROVA_ID, ContagemDecisaoTableMap::COL_TIPO, ContagemDecisaoTableMap::COL_PASSAGEM_ID, ContagemDecisaoTableMap::COL_PASSAGEM_ALVO_ID, ContagemDecisaoTableMap::COL_FONTE_ID, ContagemDecisaoTableMap::COL_EQUIPE_NUMERO, ContagemDecisaoTableMap::COL_TS_MS, ContagemDecisaoTableMap::COL_TS_FIM_MS, ContagemDecisaoTableMap::COL_DESFAZ_DECISAO_ID, ContagemDecisaoTableMap::COL_ORIGEM, ContagemDecisaoTableMap::COL_USUARIO, ContagemDecisaoTableMap::COL_MOTIVO, ContagemDecisaoTableMap::COL_CRIADO_EM_MS, ],
        self::TYPE_FIELDNAME     => ['decisao_id', 'evento_id', 'prova_id', 'tipo', 'passagem_id', 'passagem_alvo_id', 'fonte_id', 'equipe_numero', 'ts_ms', 'ts_fim_ms', 'desfaz_decisao_id', 'origem', 'usuario', 'motivo', 'criado_em_ms', ],
        self::TYPE_NUM           => [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, ]
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
        self::TYPE_PHPNAME       => ['DecisaoId' => 0, 'EventoId' => 1, 'ProvaId' => 2, 'Tipo' => 3, 'PassagemId' => 4, 'PassagemAlvoId' => 5, 'FonteId' => 6, 'EquipeNumero' => 7, 'TsMs' => 8, 'TsFimMs' => 9, 'DesfazDecisaoId' => 10, 'Origem' => 11, 'Usuario' => 12, 'Motivo' => 13, 'CriadoEmMs' => 14, ],
        self::TYPE_CAMELNAME     => ['decisaoId' => 0, 'eventoId' => 1, 'provaId' => 2, 'tipo' => 3, 'passagemId' => 4, 'passagemAlvoId' => 5, 'fonteId' => 6, 'equipeNumero' => 7, 'tsMs' => 8, 'tsFimMs' => 9, 'desfazDecisaoId' => 10, 'origem' => 11, 'usuario' => 12, 'motivo' => 13, 'criadoEmMs' => 14, ],
        self::TYPE_COLNAME       => [ContagemDecisaoTableMap::COL_DECISAO_ID => 0, ContagemDecisaoTableMap::COL_EVENTO_ID => 1, ContagemDecisaoTableMap::COL_PROVA_ID => 2, ContagemDecisaoTableMap::COL_TIPO => 3, ContagemDecisaoTableMap::COL_PASSAGEM_ID => 4, ContagemDecisaoTableMap::COL_PASSAGEM_ALVO_ID => 5, ContagemDecisaoTableMap::COL_FONTE_ID => 6, ContagemDecisaoTableMap::COL_EQUIPE_NUMERO => 7, ContagemDecisaoTableMap::COL_TS_MS => 8, ContagemDecisaoTableMap::COL_TS_FIM_MS => 9, ContagemDecisaoTableMap::COL_DESFAZ_DECISAO_ID => 10, ContagemDecisaoTableMap::COL_ORIGEM => 11, ContagemDecisaoTableMap::COL_USUARIO => 12, ContagemDecisaoTableMap::COL_MOTIVO => 13, ContagemDecisaoTableMap::COL_CRIADO_EM_MS => 14, ],
        self::TYPE_FIELDNAME     => ['decisao_id' => 0, 'evento_id' => 1, 'prova_id' => 2, 'tipo' => 3, 'passagem_id' => 4, 'passagem_alvo_id' => 5, 'fonte_id' => 6, 'equipe_numero' => 7, 'ts_ms' => 8, 'ts_fim_ms' => 9, 'desfaz_decisao_id' => 10, 'origem' => 11, 'usuario' => 12, 'motivo' => 13, 'criado_em_ms' => 14, ],
        self::TYPE_NUM           => [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, ]
    ];

    /**
     * Holds a list of column names and their normalized version.
     *
     * @var array<string>
     */
    protected $normalizedColumnNameMap = [
        'DecisaoId' => 'DECISAO_ID',
        'ContagemDecisao.DecisaoId' => 'DECISAO_ID',
        'decisaoId' => 'DECISAO_ID',
        'contagemDecisao.decisaoId' => 'DECISAO_ID',
        'ContagemDecisaoTableMap::COL_DECISAO_ID' => 'DECISAO_ID',
        'COL_DECISAO_ID' => 'DECISAO_ID',
        'decisao_id' => 'DECISAO_ID',
        'contagem_decisao.decisao_id' => 'DECISAO_ID',
        'EventoId' => 'EVENTO_ID',
        'ContagemDecisao.EventoId' => 'EVENTO_ID',
        'eventoId' => 'EVENTO_ID',
        'contagemDecisao.eventoId' => 'EVENTO_ID',
        'ContagemDecisaoTableMap::COL_EVENTO_ID' => 'EVENTO_ID',
        'COL_EVENTO_ID' => 'EVENTO_ID',
        'evento_id' => 'EVENTO_ID',
        'contagem_decisao.evento_id' => 'EVENTO_ID',
        'ProvaId' => 'PROVA_ID',
        'ContagemDecisao.ProvaId' => 'PROVA_ID',
        'provaId' => 'PROVA_ID',
        'contagemDecisao.provaId' => 'PROVA_ID',
        'ContagemDecisaoTableMap::COL_PROVA_ID' => 'PROVA_ID',
        'COL_PROVA_ID' => 'PROVA_ID',
        'prova_id' => 'PROVA_ID',
        'contagem_decisao.prova_id' => 'PROVA_ID',
        'Tipo' => 'TIPO',
        'ContagemDecisao.Tipo' => 'TIPO',
        'tipo' => 'TIPO',
        'contagemDecisao.tipo' => 'TIPO',
        'ContagemDecisaoTableMap::COL_TIPO' => 'TIPO',
        'COL_TIPO' => 'TIPO',
        'contagem_decisao.tipo' => 'TIPO',
        'PassagemId' => 'PASSAGEM_ID',
        'ContagemDecisao.PassagemId' => 'PASSAGEM_ID',
        'passagemId' => 'PASSAGEM_ID',
        'contagemDecisao.passagemId' => 'PASSAGEM_ID',
        'ContagemDecisaoTableMap::COL_PASSAGEM_ID' => 'PASSAGEM_ID',
        'COL_PASSAGEM_ID' => 'PASSAGEM_ID',
        'passagem_id' => 'PASSAGEM_ID',
        'contagem_decisao.passagem_id' => 'PASSAGEM_ID',
        'PassagemAlvoId' => 'PASSAGEM_ALVO_ID',
        'ContagemDecisao.PassagemAlvoId' => 'PASSAGEM_ALVO_ID',
        'passagemAlvoId' => 'PASSAGEM_ALVO_ID',
        'contagemDecisao.passagemAlvoId' => 'PASSAGEM_ALVO_ID',
        'ContagemDecisaoTableMap::COL_PASSAGEM_ALVO_ID' => 'PASSAGEM_ALVO_ID',
        'COL_PASSAGEM_ALVO_ID' => 'PASSAGEM_ALVO_ID',
        'passagem_alvo_id' => 'PASSAGEM_ALVO_ID',
        'contagem_decisao.passagem_alvo_id' => 'PASSAGEM_ALVO_ID',
        'FonteId' => 'FONTE_ID',
        'ContagemDecisao.FonteId' => 'FONTE_ID',
        'fonteId' => 'FONTE_ID',
        'contagemDecisao.fonteId' => 'FONTE_ID',
        'ContagemDecisaoTableMap::COL_FONTE_ID' => 'FONTE_ID',
        'COL_FONTE_ID' => 'FONTE_ID',
        'fonte_id' => 'FONTE_ID',
        'contagem_decisao.fonte_id' => 'FONTE_ID',
        'EquipeNumero' => 'EQUIPE_NUMERO',
        'ContagemDecisao.EquipeNumero' => 'EQUIPE_NUMERO',
        'equipeNumero' => 'EQUIPE_NUMERO',
        'contagemDecisao.equipeNumero' => 'EQUIPE_NUMERO',
        'ContagemDecisaoTableMap::COL_EQUIPE_NUMERO' => 'EQUIPE_NUMERO',
        'COL_EQUIPE_NUMERO' => 'EQUIPE_NUMERO',
        'equipe_numero' => 'EQUIPE_NUMERO',
        'contagem_decisao.equipe_numero' => 'EQUIPE_NUMERO',
        'TsMs' => 'TS_MS',
        'ContagemDecisao.TsMs' => 'TS_MS',
        'tsMs' => 'TS_MS',
        'contagemDecisao.tsMs' => 'TS_MS',
        'ContagemDecisaoTableMap::COL_TS_MS' => 'TS_MS',
        'COL_TS_MS' => 'TS_MS',
        'ts_ms' => 'TS_MS',
        'contagem_decisao.ts_ms' => 'TS_MS',
        'TsFimMs' => 'TS_FIM_MS',
        'ContagemDecisao.TsFimMs' => 'TS_FIM_MS',
        'tsFimMs' => 'TS_FIM_MS',
        'contagemDecisao.tsFimMs' => 'TS_FIM_MS',
        'ContagemDecisaoTableMap::COL_TS_FIM_MS' => 'TS_FIM_MS',
        'COL_TS_FIM_MS' => 'TS_FIM_MS',
        'ts_fim_ms' => 'TS_FIM_MS',
        'contagem_decisao.ts_fim_ms' => 'TS_FIM_MS',
        'DesfazDecisaoId' => 'DESFAZ_DECISAO_ID',
        'ContagemDecisao.DesfazDecisaoId' => 'DESFAZ_DECISAO_ID',
        'desfazDecisaoId' => 'DESFAZ_DECISAO_ID',
        'contagemDecisao.desfazDecisaoId' => 'DESFAZ_DECISAO_ID',
        'ContagemDecisaoTableMap::COL_DESFAZ_DECISAO_ID' => 'DESFAZ_DECISAO_ID',
        'COL_DESFAZ_DECISAO_ID' => 'DESFAZ_DECISAO_ID',
        'desfaz_decisao_id' => 'DESFAZ_DECISAO_ID',
        'contagem_decisao.desfaz_decisao_id' => 'DESFAZ_DECISAO_ID',
        'Origem' => 'ORIGEM',
        'ContagemDecisao.Origem' => 'ORIGEM',
        'origem' => 'ORIGEM',
        'contagemDecisao.origem' => 'ORIGEM',
        'ContagemDecisaoTableMap::COL_ORIGEM' => 'ORIGEM',
        'COL_ORIGEM' => 'ORIGEM',
        'contagem_decisao.origem' => 'ORIGEM',
        'Usuario' => 'USUARIO',
        'ContagemDecisao.Usuario' => 'USUARIO',
        'usuario' => 'USUARIO',
        'contagemDecisao.usuario' => 'USUARIO',
        'ContagemDecisaoTableMap::COL_USUARIO' => 'USUARIO',
        'COL_USUARIO' => 'USUARIO',
        'contagem_decisao.usuario' => 'USUARIO',
        'Motivo' => 'MOTIVO',
        'ContagemDecisao.Motivo' => 'MOTIVO',
        'motivo' => 'MOTIVO',
        'contagemDecisao.motivo' => 'MOTIVO',
        'ContagemDecisaoTableMap::COL_MOTIVO' => 'MOTIVO',
        'COL_MOTIVO' => 'MOTIVO',
        'contagem_decisao.motivo' => 'MOTIVO',
        'CriadoEmMs' => 'CRIADO_EM_MS',
        'ContagemDecisao.CriadoEmMs' => 'CRIADO_EM_MS',
        'criadoEmMs' => 'CRIADO_EM_MS',
        'contagemDecisao.criadoEmMs' => 'CRIADO_EM_MS',
        'ContagemDecisaoTableMap::COL_CRIADO_EM_MS' => 'CRIADO_EM_MS',
        'COL_CRIADO_EM_MS' => 'CRIADO_EM_MS',
        'criado_em_ms' => 'CRIADO_EM_MS',
        'contagem_decisao.criado_em_ms' => 'CRIADO_EM_MS',
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
        $this->setName('contagem_decisao');
        $this->setPhpName('ContagemDecisao');
        $this->setIdentifierQuoting(false);
        $this->setClassName('\\Baja\\Model\\ContagemDecisao');
        $this->setPackage('Baja.Model');
        $this->setUseIdGenerator(true);
        // columns
        $this->addPrimaryKey('decisao_id', 'DecisaoId', 'BIGINT', true, null, null);
        $this->addForeignKey('evento_id', 'EventoId', 'CHAR', 'prova', 'evento_id', true, 4, null);
        $this->addForeignKey('prova_id', 'ProvaId', 'CHAR', 'prova', 'prova_id', true, 3, null);
        $this->addColumn('tipo', 'Tipo', 'VARCHAR', true, 16, null);
        $this->addColumn('passagem_id', 'PassagemId', 'BIGINT', false, null, null);
        $this->addColumn('passagem_alvo_id', 'PassagemAlvoId', 'BIGINT', false, null, null);
        $this->addColumn('fonte_id', 'FonteId', 'INTEGER', false, null, null);
        $this->addColumn('equipe_numero', 'EquipeNumero', 'INTEGER', false, null, null);
        $this->addColumn('ts_ms', 'TsMs', 'BIGINT', false, null, null);
        $this->addColumn('ts_fim_ms', 'TsFimMs', 'BIGINT', false, null, null);
        $this->addColumn('desfaz_decisao_id', 'DesfazDecisaoId', 'BIGINT', false, null, null);
        $this->addColumn('origem', 'Origem', 'VARCHAR', true, 10, 'revisor');
        $this->addColumn('usuario', 'Usuario', 'VARCHAR', false, 45, null);
        $this->addColumn('motivo', 'Motivo', 'LONGVARCHAR', false, null, null);
        $this->addColumn('criado_em_ms', 'CriadoEmMs', 'BIGINT', true, null, null);
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
        if ($row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('DecisaoId', TableMap::TYPE_PHPNAME, $indexType)] === null) {
            return null;
        }

        return null === $row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('DecisaoId', TableMap::TYPE_PHPNAME, $indexType)] || is_scalar($row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('DecisaoId', TableMap::TYPE_PHPNAME, $indexType)]) || is_callable([$row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('DecisaoId', TableMap::TYPE_PHPNAME, $indexType)], '__toString']) ? (string) $row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('DecisaoId', TableMap::TYPE_PHPNAME, $indexType)] : $row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('DecisaoId', TableMap::TYPE_PHPNAME, $indexType)];
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
                : self::translateFieldName('DecisaoId', TableMap::TYPE_PHPNAME, $indexType)
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
        return $withPrefix ? ContagemDecisaoTableMap::CLASS_DEFAULT : ContagemDecisaoTableMap::OM_CLASS;
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
     * @return array (ContagemDecisao object, last column rank)
     */
    public static function populateObject(array $row, int $offset = 0, string $indexType = TableMap::TYPE_NUM): array
    {
        $key = ContagemDecisaoTableMap::getPrimaryKeyHashFromRow($row, $offset, $indexType);
        if (null !== ($obj = ContagemDecisaoTableMap::getInstanceFromPool($key))) {
            // We no longer rehydrate the object, since this can cause data loss.
            // See http://www.propelorm.org/ticket/509
            // $obj->hydrate($row, $offset, true); // rehydrate
            $col = $offset + ContagemDecisaoTableMap::NUM_HYDRATE_COLUMNS;
        } else {
            $cls = ContagemDecisaoTableMap::OM_CLASS;
            /** @var ContagemDecisao $obj */
            $obj = new $cls();
            $col = $obj->hydrate($row, $offset, false, $indexType);
            ContagemDecisaoTableMap::addInstanceToPool($obj, $key);
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
            $key = ContagemDecisaoTableMap::getPrimaryKeyHashFromRow($row, 0, $dataFetcher->getIndexType());
            if (null !== ($obj = ContagemDecisaoTableMap::getInstanceFromPool($key))) {
                // We no longer rehydrate the object, since this can cause data loss.
                // See http://www.propelorm.org/ticket/509
                // $obj->hydrate($row, 0, true); // rehydrate
                $results[] = $obj;
            } else {
                /** @var ContagemDecisao $obj */
                $obj = new $cls();
                $obj->hydrate($row);
                $results[] = $obj;
                ContagemDecisaoTableMap::addInstanceToPool($obj, $key);
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
            $criteria->addSelectColumn(ContagemDecisaoTableMap::COL_DECISAO_ID);
            $criteria->addSelectColumn(ContagemDecisaoTableMap::COL_EVENTO_ID);
            $criteria->addSelectColumn(ContagemDecisaoTableMap::COL_PROVA_ID);
            $criteria->addSelectColumn(ContagemDecisaoTableMap::COL_TIPO);
            $criteria->addSelectColumn(ContagemDecisaoTableMap::COL_PASSAGEM_ID);
            $criteria->addSelectColumn(ContagemDecisaoTableMap::COL_PASSAGEM_ALVO_ID);
            $criteria->addSelectColumn(ContagemDecisaoTableMap::COL_FONTE_ID);
            $criteria->addSelectColumn(ContagemDecisaoTableMap::COL_EQUIPE_NUMERO);
            $criteria->addSelectColumn(ContagemDecisaoTableMap::COL_TS_MS);
            $criteria->addSelectColumn(ContagemDecisaoTableMap::COL_TS_FIM_MS);
            $criteria->addSelectColumn(ContagemDecisaoTableMap::COL_DESFAZ_DECISAO_ID);
            $criteria->addSelectColumn(ContagemDecisaoTableMap::COL_ORIGEM);
            $criteria->addSelectColumn(ContagemDecisaoTableMap::COL_USUARIO);
            $criteria->addSelectColumn(ContagemDecisaoTableMap::COL_MOTIVO);
            $criteria->addSelectColumn(ContagemDecisaoTableMap::COL_CRIADO_EM_MS);
        } else {
            $criteria->addSelectColumn($alias . '.decisao_id');
            $criteria->addSelectColumn($alias . '.evento_id');
            $criteria->addSelectColumn($alias . '.prova_id');
            $criteria->addSelectColumn($alias . '.tipo');
            $criteria->addSelectColumn($alias . '.passagem_id');
            $criteria->addSelectColumn($alias . '.passagem_alvo_id');
            $criteria->addSelectColumn($alias . '.fonte_id');
            $criteria->addSelectColumn($alias . '.equipe_numero');
            $criteria->addSelectColumn($alias . '.ts_ms');
            $criteria->addSelectColumn($alias . '.ts_fim_ms');
            $criteria->addSelectColumn($alias . '.desfaz_decisao_id');
            $criteria->addSelectColumn($alias . '.origem');
            $criteria->addSelectColumn($alias . '.usuario');
            $criteria->addSelectColumn($alias . '.motivo');
            $criteria->addSelectColumn($alias . '.criado_em_ms');
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
            $criteria->removeSelectColumn(ContagemDecisaoTableMap::COL_DECISAO_ID);
            $criteria->removeSelectColumn(ContagemDecisaoTableMap::COL_EVENTO_ID);
            $criteria->removeSelectColumn(ContagemDecisaoTableMap::COL_PROVA_ID);
            $criteria->removeSelectColumn(ContagemDecisaoTableMap::COL_TIPO);
            $criteria->removeSelectColumn(ContagemDecisaoTableMap::COL_PASSAGEM_ID);
            $criteria->removeSelectColumn(ContagemDecisaoTableMap::COL_PASSAGEM_ALVO_ID);
            $criteria->removeSelectColumn(ContagemDecisaoTableMap::COL_FONTE_ID);
            $criteria->removeSelectColumn(ContagemDecisaoTableMap::COL_EQUIPE_NUMERO);
            $criteria->removeSelectColumn(ContagemDecisaoTableMap::COL_TS_MS);
            $criteria->removeSelectColumn(ContagemDecisaoTableMap::COL_TS_FIM_MS);
            $criteria->removeSelectColumn(ContagemDecisaoTableMap::COL_DESFAZ_DECISAO_ID);
            $criteria->removeSelectColumn(ContagemDecisaoTableMap::COL_ORIGEM);
            $criteria->removeSelectColumn(ContagemDecisaoTableMap::COL_USUARIO);
            $criteria->removeSelectColumn(ContagemDecisaoTableMap::COL_MOTIVO);
            $criteria->removeSelectColumn(ContagemDecisaoTableMap::COL_CRIADO_EM_MS);
        } else {
            $criteria->removeSelectColumn($alias . '.decisao_id');
            $criteria->removeSelectColumn($alias . '.evento_id');
            $criteria->removeSelectColumn($alias . '.prova_id');
            $criteria->removeSelectColumn($alias . '.tipo');
            $criteria->removeSelectColumn($alias . '.passagem_id');
            $criteria->removeSelectColumn($alias . '.passagem_alvo_id');
            $criteria->removeSelectColumn($alias . '.fonte_id');
            $criteria->removeSelectColumn($alias . '.equipe_numero');
            $criteria->removeSelectColumn($alias . '.ts_ms');
            $criteria->removeSelectColumn($alias . '.ts_fim_ms');
            $criteria->removeSelectColumn($alias . '.desfaz_decisao_id');
            $criteria->removeSelectColumn($alias . '.origem');
            $criteria->removeSelectColumn($alias . '.usuario');
            $criteria->removeSelectColumn($alias . '.motivo');
            $criteria->removeSelectColumn($alias . '.criado_em_ms');
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
        return Propel::getServiceContainer()->getDatabaseMap(ContagemDecisaoTableMap::DATABASE_NAME)->getTable(ContagemDecisaoTableMap::TABLE_NAME);
    }

    /**
     * Performs a DELETE on the database, given a ContagemDecisao or Criteria object OR a primary key value.
     *
     * @param mixed $values Criteria or ContagemDecisao object or primary key or array of primary keys
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
            $con = Propel::getServiceContainer()->getWriteConnection(ContagemDecisaoTableMap::DATABASE_NAME);
        }

        if ($values instanceof Criteria) {
            // rename for clarity
            $criteria = $values;
        } elseif ($values instanceof \Baja\Model\ContagemDecisao) { // it's a model object
            // create criteria based on pk values
            $criteria = $values->buildPkeyCriteria();
        } else { // it's a primary key, or an array of pks
            $criteria = new Criteria(ContagemDecisaoTableMap::DATABASE_NAME);
            $criteria->add(ContagemDecisaoTableMap::COL_DECISAO_ID, (array) $values, Criteria::IN);
        }

        $query = ContagemDecisaoQuery::create()->mergeWith($criteria);

        if ($values instanceof Criteria) {
            ContagemDecisaoTableMap::clearInstancePool();
        } elseif (!is_object($values)) { // it's a primary key, or an array of pks
            foreach ((array) $values as $singleval) {
                ContagemDecisaoTableMap::removeInstanceFromPool($singleval);
            }
        }

        return $query->delete($con);
    }

    /**
     * Deletes all rows from the contagem_decisao table.
     *
     * @param ConnectionInterface $con the connection to use
     * @return int The number of affected rows (if supported by underlying database driver).
     */
    public static function doDeleteAll(?ConnectionInterface $con = null): int
    {
        return ContagemDecisaoQuery::create()->doDeleteAll($con);
    }

    /**
     * Performs an INSERT on the database, given a ContagemDecisao or Criteria object.
     *
     * @param mixed $criteria Criteria or ContagemDecisao object containing data that is used to create the INSERT statement.
     * @param ConnectionInterface $con the ConnectionInterface connection to use
     * @return mixed The new primary key.
     * @throws \Propel\Runtime\Exception\PropelException Any exceptions caught during processing will be
     *                         rethrown wrapped into a PropelException.
     */
    public static function doInsert($criteria, ?ConnectionInterface $con = null)
    {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(ContagemDecisaoTableMap::DATABASE_NAME);
        }

        if ($criteria instanceof Criteria) {
            $criteria = clone $criteria; // rename for clarity
        } else {
            $criteria = $criteria->buildCriteria(); // build Criteria from ContagemDecisao object
        }

        if ($criteria->containsKey(ContagemDecisaoTableMap::COL_DECISAO_ID) && $criteria->keyContainsValue(ContagemDecisaoTableMap::COL_DECISAO_ID) ) {
            throw new PropelException('Cannot insert a value for auto-increment primary key ('.ContagemDecisaoTableMap::COL_DECISAO_ID.')');
        }


        // Set the correct dbName
        $query = ContagemDecisaoQuery::create()->mergeWith($criteria);

        // use transaction because $criteria could contain info
        // for more than one table (I guess, conceivably)
        return $con->transaction(function () use ($con, $query) {
            return $query->doInsert($con);
        });
    }

}
