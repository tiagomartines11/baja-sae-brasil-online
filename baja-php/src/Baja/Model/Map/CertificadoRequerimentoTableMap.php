<?php

namespace Baja\Model\Map;

use Baja\Model\CertificadoRequerimento;
use Baja\Model\CertificadoRequerimentoQuery;
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
 * This class defines the structure of the 'certificado_requerimento' table.
 *
 *
 *
 * This map class is used by Propel to do runtime db structure discovery.
 * For example, the createSelectSql() method checks the type of a given column used in an
 * ORDER BY clause to know whether it needs to apply SQL to make the ORDER BY case-insensitive
 * (i.e. if it's a text column type).
 */
class CertificadoRequerimentoTableMap extends TableMap
{
    use InstancePoolTrait;
    use TableMapTrait;

    /**
     * The (dot-path) name of this class
     */
    public const CLASS_NAME = 'Baja.Model.Map.CertificadoRequerimentoTableMap';

    /**
     * The default database name for this class
     */
    public const DATABASE_NAME = 'resultados';

    /**
     * The table name for this class
     */
    public const TABLE_NAME = 'certificado_requerimento';

    /**
     * The PHP name of this class (PascalCase)
     */
    public const TABLE_PHP_NAME = 'CertificadoRequerimento';

    /**
     * The related Propel class for this table
     */
    public const OM_CLASS = '\\Baja\\Model\\CertificadoRequerimento';

    /**
     * A class that can be returned by this tableMap
     */
    public const CLASS_DEFAULT = 'Baja.Model.CertificadoRequerimento';

    /**
     * The total number of columns
     */
    public const NUM_COLUMNS = 16;

    /**
     * The number of lazy-loaded columns
     */
    public const NUM_LAZY_LOAD_COLUMNS = 0;

    /**
     * The number of columns to hydrate (NUM_COLUMNS - NUM_LAZY_LOAD_COLUMNS)
     */
    public const NUM_HYDRATE_COLUMNS = 16;

    /**
     * the column name for the requerimento_id field
     */
    public const COL_REQUERIMENTO_ID = 'certificado_requerimento.requerimento_id';

    /**
     * the column name for the caso field
     */
    public const COL_CASO = 'certificado_requerimento.caso';

    /**
     * the column name for the token field
     */
    public const COL_TOKEN = 'certificado_requerimento.token';

    /**
     * the column name for the evento field
     */
    public const COL_EVENTO = 'certificado_requerimento.evento';

    /**
     * the column name for the evento_texto field
     */
    public const COL_EVENTO_TEXTO = 'certificado_requerimento.evento_texto';

    /**
     * the column name for the documento field
     */
    public const COL_DOCUMENTO = 'certificado_requerimento.documento';

    /**
     * the column name for the nome field
     */
    public const COL_NOME = 'certificado_requerimento.nome';

    /**
     * the column name for the email field
     */
    public const COL_EMAIL = 'certificado_requerimento.email';

    /**
     * the column name for the telefone field
     */
    public const COL_TELEFONE = 'certificado_requerimento.telefone';

    /**
     * the column name for the descricao field
     */
    public const COL_DESCRICAO = 'certificado_requerimento.descricao';

    /**
     * the column name for the criado_em field
     */
    public const COL_CRIADO_EM = 'certificado_requerimento.criado_em';

    /**
     * the column name for the avisado_em field
     */
    public const COL_AVISADO_EM = 'certificado_requerimento.avisado_em';

    /**
     * the column name for the status field
     */
    public const COL_STATUS = 'certificado_requerimento.status';

    /**
     * the column name for the resolvido_por field
     */
    public const COL_RESOLVIDO_POR = 'certificado_requerimento.resolvido_por';

    /**
     * the column name for the resolvido_em field
     */
    public const COL_RESOLVIDO_EM = 'certificado_requerimento.resolvido_em';

    /**
     * the column name for the resolucao field
     */
    public const COL_RESOLUCAO = 'certificado_requerimento.resolucao';

    /**
     * The default string format for model objects of the related table
     */
    public const DEFAULT_STRING_FORMAT = 'YAML';

    /** The enumerated values for the caso field */
    public const COL_CASO_INCORRETO = 'incorreto';
    public const COL_CASO_AUSENTE = 'ausente';
    public const COL_CASO_INDEVIDO = 'indevido';

    /** The enumerated values for the status field */
    public const COL_STATUS_ABERTO = 'aberto';
    public const COL_STATUS_EM_ANALISE = 'em_analise';
    public const COL_STATUS_RESOLVIDO = 'resolvido';
    public const COL_STATUS_RECUSADO = 'recusado';

    /**
     * holds an array of fieldnames
     *
     * first dimension keys are the type constants
     * e.g. self::$fieldNames[self::TYPE_PHPNAME][0] = 'Id'
     *
     * @var array<string, mixed>
     */
    protected static $fieldNames = [
        self::TYPE_PHPNAME       => ['RequerimentoId', 'Caso', 'Token', 'EventoId', 'EventoTexto', 'Documento', 'Nome', 'Email', 'Telefone', 'Descricao', 'CriadoEm', 'AvisadoEm', 'Status', 'ResolvidoPor', 'ResolvidoEm', 'Resolucao', ],
        self::TYPE_CAMELNAME     => ['requerimentoId', 'caso', 'token', 'eventoId', 'eventoTexto', 'documento', 'nome', 'email', 'telefone', 'descricao', 'criadoEm', 'avisadoEm', 'status', 'resolvidoPor', 'resolvidoEm', 'resolucao', ],
        self::TYPE_COLNAME       => [CertificadoRequerimentoTableMap::COL_REQUERIMENTO_ID, CertificadoRequerimentoTableMap::COL_CASO, CertificadoRequerimentoTableMap::COL_TOKEN, CertificadoRequerimentoTableMap::COL_EVENTO, CertificadoRequerimentoTableMap::COL_EVENTO_TEXTO, CertificadoRequerimentoTableMap::COL_DOCUMENTO, CertificadoRequerimentoTableMap::COL_NOME, CertificadoRequerimentoTableMap::COL_EMAIL, CertificadoRequerimentoTableMap::COL_TELEFONE, CertificadoRequerimentoTableMap::COL_DESCRICAO, CertificadoRequerimentoTableMap::COL_CRIADO_EM, CertificadoRequerimentoTableMap::COL_AVISADO_EM, CertificadoRequerimentoTableMap::COL_STATUS, CertificadoRequerimentoTableMap::COL_RESOLVIDO_POR, CertificadoRequerimentoTableMap::COL_RESOLVIDO_EM, CertificadoRequerimentoTableMap::COL_RESOLUCAO, ],
        self::TYPE_FIELDNAME     => ['requerimento_id', 'caso', 'token', 'evento', 'evento_texto', 'documento', 'nome', 'email', 'telefone', 'descricao', 'criado_em', 'avisado_em', 'status', 'resolvido_por', 'resolvido_em', 'resolucao', ],
        self::TYPE_NUM           => [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, ]
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
        self::TYPE_PHPNAME       => ['RequerimentoId' => 0, 'Caso' => 1, 'Token' => 2, 'EventoId' => 3, 'EventoTexto' => 4, 'Documento' => 5, 'Nome' => 6, 'Email' => 7, 'Telefone' => 8, 'Descricao' => 9, 'CriadoEm' => 10, 'AvisadoEm' => 11, 'Status' => 12, 'ResolvidoPor' => 13, 'ResolvidoEm' => 14, 'Resolucao' => 15, ],
        self::TYPE_CAMELNAME     => ['requerimentoId' => 0, 'caso' => 1, 'token' => 2, 'eventoId' => 3, 'eventoTexto' => 4, 'documento' => 5, 'nome' => 6, 'email' => 7, 'telefone' => 8, 'descricao' => 9, 'criadoEm' => 10, 'avisadoEm' => 11, 'status' => 12, 'resolvidoPor' => 13, 'resolvidoEm' => 14, 'resolucao' => 15, ],
        self::TYPE_COLNAME       => [CertificadoRequerimentoTableMap::COL_REQUERIMENTO_ID => 0, CertificadoRequerimentoTableMap::COL_CASO => 1, CertificadoRequerimentoTableMap::COL_TOKEN => 2, CertificadoRequerimentoTableMap::COL_EVENTO => 3, CertificadoRequerimentoTableMap::COL_EVENTO_TEXTO => 4, CertificadoRequerimentoTableMap::COL_DOCUMENTO => 5, CertificadoRequerimentoTableMap::COL_NOME => 6, CertificadoRequerimentoTableMap::COL_EMAIL => 7, CertificadoRequerimentoTableMap::COL_TELEFONE => 8, CertificadoRequerimentoTableMap::COL_DESCRICAO => 9, CertificadoRequerimentoTableMap::COL_CRIADO_EM => 10, CertificadoRequerimentoTableMap::COL_AVISADO_EM => 11, CertificadoRequerimentoTableMap::COL_STATUS => 12, CertificadoRequerimentoTableMap::COL_RESOLVIDO_POR => 13, CertificadoRequerimentoTableMap::COL_RESOLVIDO_EM => 14, CertificadoRequerimentoTableMap::COL_RESOLUCAO => 15, ],
        self::TYPE_FIELDNAME     => ['requerimento_id' => 0, 'caso' => 1, 'token' => 2, 'evento' => 3, 'evento_texto' => 4, 'documento' => 5, 'nome' => 6, 'email' => 7, 'telefone' => 8, 'descricao' => 9, 'criado_em' => 10, 'avisado_em' => 11, 'status' => 12, 'resolvido_por' => 13, 'resolvido_em' => 14, 'resolucao' => 15, ],
        self::TYPE_NUM           => [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, ]
    ];

    /**
     * Holds a list of column names and their normalized version.
     *
     * @var array<string>
     */
    protected $normalizedColumnNameMap = [
        'RequerimentoId' => 'REQUERIMENTO_ID',
        'CertificadoRequerimento.RequerimentoId' => 'REQUERIMENTO_ID',
        'requerimentoId' => 'REQUERIMENTO_ID',
        'certificadoRequerimento.requerimentoId' => 'REQUERIMENTO_ID',
        'CertificadoRequerimentoTableMap::COL_REQUERIMENTO_ID' => 'REQUERIMENTO_ID',
        'COL_REQUERIMENTO_ID' => 'REQUERIMENTO_ID',
        'requerimento_id' => 'REQUERIMENTO_ID',
        'certificado_requerimento.requerimento_id' => 'REQUERIMENTO_ID',
        'Caso' => 'CASO',
        'CertificadoRequerimento.Caso' => 'CASO',
        'caso' => 'CASO',
        'certificadoRequerimento.caso' => 'CASO',
        'CertificadoRequerimentoTableMap::COL_CASO' => 'CASO',
        'COL_CASO' => 'CASO',
        'certificado_requerimento.caso' => 'CASO',
        'Token' => 'TOKEN',
        'CertificadoRequerimento.Token' => 'TOKEN',
        'token' => 'TOKEN',
        'certificadoRequerimento.token' => 'TOKEN',
        'CertificadoRequerimentoTableMap::COL_TOKEN' => 'TOKEN',
        'COL_TOKEN' => 'TOKEN',
        'certificado_requerimento.token' => 'TOKEN',
        'EventoId' => 'EVENTO',
        'CertificadoRequerimento.EventoId' => 'EVENTO',
        'eventoId' => 'EVENTO',
        'certificadoRequerimento.eventoId' => 'EVENTO',
        'CertificadoRequerimentoTableMap::COL_EVENTO' => 'EVENTO',
        'COL_EVENTO' => 'EVENTO',
        'evento' => 'EVENTO',
        'certificado_requerimento.evento' => 'EVENTO',
        'EventoTexto' => 'EVENTO_TEXTO',
        'CertificadoRequerimento.EventoTexto' => 'EVENTO_TEXTO',
        'eventoTexto' => 'EVENTO_TEXTO',
        'certificadoRequerimento.eventoTexto' => 'EVENTO_TEXTO',
        'CertificadoRequerimentoTableMap::COL_EVENTO_TEXTO' => 'EVENTO_TEXTO',
        'COL_EVENTO_TEXTO' => 'EVENTO_TEXTO',
        'evento_texto' => 'EVENTO_TEXTO',
        'certificado_requerimento.evento_texto' => 'EVENTO_TEXTO',
        'Documento' => 'DOCUMENTO',
        'CertificadoRequerimento.Documento' => 'DOCUMENTO',
        'documento' => 'DOCUMENTO',
        'certificadoRequerimento.documento' => 'DOCUMENTO',
        'CertificadoRequerimentoTableMap::COL_DOCUMENTO' => 'DOCUMENTO',
        'COL_DOCUMENTO' => 'DOCUMENTO',
        'certificado_requerimento.documento' => 'DOCUMENTO',
        'Nome' => 'NOME',
        'CertificadoRequerimento.Nome' => 'NOME',
        'nome' => 'NOME',
        'certificadoRequerimento.nome' => 'NOME',
        'CertificadoRequerimentoTableMap::COL_NOME' => 'NOME',
        'COL_NOME' => 'NOME',
        'certificado_requerimento.nome' => 'NOME',
        'Email' => 'EMAIL',
        'CertificadoRequerimento.Email' => 'EMAIL',
        'email' => 'EMAIL',
        'certificadoRequerimento.email' => 'EMAIL',
        'CertificadoRequerimentoTableMap::COL_EMAIL' => 'EMAIL',
        'COL_EMAIL' => 'EMAIL',
        'certificado_requerimento.email' => 'EMAIL',
        'Telefone' => 'TELEFONE',
        'CertificadoRequerimento.Telefone' => 'TELEFONE',
        'telefone' => 'TELEFONE',
        'certificadoRequerimento.telefone' => 'TELEFONE',
        'CertificadoRequerimentoTableMap::COL_TELEFONE' => 'TELEFONE',
        'COL_TELEFONE' => 'TELEFONE',
        'certificado_requerimento.telefone' => 'TELEFONE',
        'Descricao' => 'DESCRICAO',
        'CertificadoRequerimento.Descricao' => 'DESCRICAO',
        'descricao' => 'DESCRICAO',
        'certificadoRequerimento.descricao' => 'DESCRICAO',
        'CertificadoRequerimentoTableMap::COL_DESCRICAO' => 'DESCRICAO',
        'COL_DESCRICAO' => 'DESCRICAO',
        'certificado_requerimento.descricao' => 'DESCRICAO',
        'CriadoEm' => 'CRIADO_EM',
        'CertificadoRequerimento.CriadoEm' => 'CRIADO_EM',
        'criadoEm' => 'CRIADO_EM',
        'certificadoRequerimento.criadoEm' => 'CRIADO_EM',
        'CertificadoRequerimentoTableMap::COL_CRIADO_EM' => 'CRIADO_EM',
        'COL_CRIADO_EM' => 'CRIADO_EM',
        'criado_em' => 'CRIADO_EM',
        'certificado_requerimento.criado_em' => 'CRIADO_EM',
        'AvisadoEm' => 'AVISADO_EM',
        'CertificadoRequerimento.AvisadoEm' => 'AVISADO_EM',
        'avisadoEm' => 'AVISADO_EM',
        'certificadoRequerimento.avisadoEm' => 'AVISADO_EM',
        'CertificadoRequerimentoTableMap::COL_AVISADO_EM' => 'AVISADO_EM',
        'COL_AVISADO_EM' => 'AVISADO_EM',
        'avisado_em' => 'AVISADO_EM',
        'certificado_requerimento.avisado_em' => 'AVISADO_EM',
        'Status' => 'STATUS',
        'CertificadoRequerimento.Status' => 'STATUS',
        'status' => 'STATUS',
        'certificadoRequerimento.status' => 'STATUS',
        'CertificadoRequerimentoTableMap::COL_STATUS' => 'STATUS',
        'COL_STATUS' => 'STATUS',
        'certificado_requerimento.status' => 'STATUS',
        'ResolvidoPor' => 'RESOLVIDO_POR',
        'CertificadoRequerimento.ResolvidoPor' => 'RESOLVIDO_POR',
        'resolvidoPor' => 'RESOLVIDO_POR',
        'certificadoRequerimento.resolvidoPor' => 'RESOLVIDO_POR',
        'CertificadoRequerimentoTableMap::COL_RESOLVIDO_POR' => 'RESOLVIDO_POR',
        'COL_RESOLVIDO_POR' => 'RESOLVIDO_POR',
        'resolvido_por' => 'RESOLVIDO_POR',
        'certificado_requerimento.resolvido_por' => 'RESOLVIDO_POR',
        'ResolvidoEm' => 'RESOLVIDO_EM',
        'CertificadoRequerimento.ResolvidoEm' => 'RESOLVIDO_EM',
        'resolvidoEm' => 'RESOLVIDO_EM',
        'certificadoRequerimento.resolvidoEm' => 'RESOLVIDO_EM',
        'CertificadoRequerimentoTableMap::COL_RESOLVIDO_EM' => 'RESOLVIDO_EM',
        'COL_RESOLVIDO_EM' => 'RESOLVIDO_EM',
        'resolvido_em' => 'RESOLVIDO_EM',
        'certificado_requerimento.resolvido_em' => 'RESOLVIDO_EM',
        'Resolucao' => 'RESOLUCAO',
        'CertificadoRequerimento.Resolucao' => 'RESOLUCAO',
        'resolucao' => 'RESOLUCAO',
        'certificadoRequerimento.resolucao' => 'RESOLUCAO',
        'CertificadoRequerimentoTableMap::COL_RESOLUCAO' => 'RESOLUCAO',
        'COL_RESOLUCAO' => 'RESOLUCAO',
        'certificado_requerimento.resolucao' => 'RESOLUCAO',
    ];

    /**
     * The enumerated values for this table
     *
     * @var array<string, array<string>>
     */
    protected static $enumValueSets = [
                CertificadoRequerimentoTableMap::COL_CASO => [
                            self::COL_CASO_INCORRETO,
            self::COL_CASO_AUSENTE,
            self::COL_CASO_INDEVIDO,
        ],
                CertificadoRequerimentoTableMap::COL_STATUS => [
                            self::COL_STATUS_ABERTO,
            self::COL_STATUS_EM_ANALISE,
            self::COL_STATUS_RESOLVIDO,
            self::COL_STATUS_RECUSADO,
        ],
    ];

    /**
     * Gets the list of values for all ENUM and SET columns
     * @return array
     */
    public static function getValueSets(): array
    {
      return static::$enumValueSets;
    }

    /**
     * Gets the list of values for an ENUM or SET column
     * @param string $colname
     * @return array list of possible values for the column
     */
    public static function getValueSet(string $colname): array
    {
        $valueSets = self::getValueSets();

        return $valueSets[$colname];
    }

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
        $this->setName('certificado_requerimento');
        $this->setPhpName('CertificadoRequerimento');
        $this->setIdentifierQuoting(false);
        $this->setClassName('\\Baja\\Model\\CertificadoRequerimento');
        $this->setPackage('Baja.Model');
        $this->setUseIdGenerator(false);
        // columns
        $this->addPrimaryKey('requerimento_id', 'RequerimentoId', 'CHAR', true, 22, null);
        $this->addColumn('caso', 'Caso', 'ENUM', true, null, null);
        $this->getColumn('caso')->setValueSet(array (
  0 => 'incorreto',
  1 => 'ausente',
  2 => 'indevido',
));
        $this->addColumn('token', 'Token', 'CHAR', false, 22, null);
        $this->addColumn('evento', 'EventoId', 'CHAR', false, 4, null);
        $this->addColumn('evento_texto', 'EventoTexto', 'VARCHAR', false, 160, null);
        $this->addColumn('documento', 'Documento', 'VARCHAR', true, 32, null);
        $this->addColumn('nome', 'Nome', 'VARCHAR', true, 300, null);
        $this->addColumn('email', 'Email', 'VARCHAR', true, 254, null);
        $this->addColumn('telefone', 'Telefone', 'VARCHAR', false, 40, null);
        $this->addColumn('descricao', 'Descricao', 'VARCHAR', true, 2000, null);
        $this->addColumn('criado_em', 'CriadoEm', 'TIMESTAMP', true, null, null);
        $this->addColumn('avisado_em', 'AvisadoEm', 'TIMESTAMP', false, null, null);
        $this->addColumn('status', 'Status', 'ENUM', true, null, 'aberto');
        $this->getColumn('status')->setValueSet(array (
  0 => 'aberto',
  1 => 'em_analise',
  2 => 'resolvido',
  3 => 'recusado',
));
        $this->addForeignKey('resolvido_por', 'ResolvidoPor', 'INTEGER', 'user', 'user_id', false, null, null);
        $this->addColumn('resolvido_em', 'ResolvidoEm', 'TIMESTAMP', false, null, null);
        $this->addColumn('resolucao', 'Resolucao', 'VARCHAR', false, 1000, null);
    }

    /**
     * Build the RelationMap objects for this table relationships
     *
     * @return void
     */
    public function buildRelations(): void
    {
        $this->addRelation('User', '\\Baja\\Model\\User', RelationMap::MANY_TO_ONE, array (
  0 =>
  array (
    0 => ':resolvido_por',
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
        if ($row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('RequerimentoId', TableMap::TYPE_PHPNAME, $indexType)] === null) {
            return null;
        }

        return null === $row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('RequerimentoId', TableMap::TYPE_PHPNAME, $indexType)] || is_scalar($row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('RequerimentoId', TableMap::TYPE_PHPNAME, $indexType)]) || is_callable([$row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('RequerimentoId', TableMap::TYPE_PHPNAME, $indexType)], '__toString']) ? (string) $row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('RequerimentoId', TableMap::TYPE_PHPNAME, $indexType)] : $row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('RequerimentoId', TableMap::TYPE_PHPNAME, $indexType)];
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
                : self::translateFieldName('RequerimentoId', TableMap::TYPE_PHPNAME, $indexType)
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
        return $withPrefix ? CertificadoRequerimentoTableMap::CLASS_DEFAULT : CertificadoRequerimentoTableMap::OM_CLASS;
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
     * @return array (CertificadoRequerimento object, last column rank)
     */
    public static function populateObject(array $row, int $offset = 0, string $indexType = TableMap::TYPE_NUM): array
    {
        $key = CertificadoRequerimentoTableMap::getPrimaryKeyHashFromRow($row, $offset, $indexType);
        if (null !== ($obj = CertificadoRequerimentoTableMap::getInstanceFromPool($key))) {
            // We no longer rehydrate the object, since this can cause data loss.
            // See http://www.propelorm.org/ticket/509
            // $obj->hydrate($row, $offset, true); // rehydrate
            $col = $offset + CertificadoRequerimentoTableMap::NUM_HYDRATE_COLUMNS;
        } else {
            $cls = CertificadoRequerimentoTableMap::OM_CLASS;
            /** @var CertificadoRequerimento $obj */
            $obj = new $cls();
            $col = $obj->hydrate($row, $offset, false, $indexType);
            CertificadoRequerimentoTableMap::addInstanceToPool($obj, $key);
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
            $key = CertificadoRequerimentoTableMap::getPrimaryKeyHashFromRow($row, 0, $dataFetcher->getIndexType());
            if (null !== ($obj = CertificadoRequerimentoTableMap::getInstanceFromPool($key))) {
                // We no longer rehydrate the object, since this can cause data loss.
                // See http://www.propelorm.org/ticket/509
                // $obj->hydrate($row, 0, true); // rehydrate
                $results[] = $obj;
            } else {
                /** @var CertificadoRequerimento $obj */
                $obj = new $cls();
                $obj->hydrate($row);
                $results[] = $obj;
                CertificadoRequerimentoTableMap::addInstanceToPool($obj, $key);
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
            $criteria->addSelectColumn(CertificadoRequerimentoTableMap::COL_REQUERIMENTO_ID);
            $criteria->addSelectColumn(CertificadoRequerimentoTableMap::COL_CASO);
            $criteria->addSelectColumn(CertificadoRequerimentoTableMap::COL_TOKEN);
            $criteria->addSelectColumn(CertificadoRequerimentoTableMap::COL_EVENTO);
            $criteria->addSelectColumn(CertificadoRequerimentoTableMap::COL_EVENTO_TEXTO);
            $criteria->addSelectColumn(CertificadoRequerimentoTableMap::COL_DOCUMENTO);
            $criteria->addSelectColumn(CertificadoRequerimentoTableMap::COL_NOME);
            $criteria->addSelectColumn(CertificadoRequerimentoTableMap::COL_EMAIL);
            $criteria->addSelectColumn(CertificadoRequerimentoTableMap::COL_TELEFONE);
            $criteria->addSelectColumn(CertificadoRequerimentoTableMap::COL_DESCRICAO);
            $criteria->addSelectColumn(CertificadoRequerimentoTableMap::COL_CRIADO_EM);
            $criteria->addSelectColumn(CertificadoRequerimentoTableMap::COL_AVISADO_EM);
            $criteria->addSelectColumn(CertificadoRequerimentoTableMap::COL_STATUS);
            $criteria->addSelectColumn(CertificadoRequerimentoTableMap::COL_RESOLVIDO_POR);
            $criteria->addSelectColumn(CertificadoRequerimentoTableMap::COL_RESOLVIDO_EM);
            $criteria->addSelectColumn(CertificadoRequerimentoTableMap::COL_RESOLUCAO);
        } else {
            $criteria->addSelectColumn($alias . '.requerimento_id');
            $criteria->addSelectColumn($alias . '.caso');
            $criteria->addSelectColumn($alias . '.token');
            $criteria->addSelectColumn($alias . '.evento');
            $criteria->addSelectColumn($alias . '.evento_texto');
            $criteria->addSelectColumn($alias . '.documento');
            $criteria->addSelectColumn($alias . '.nome');
            $criteria->addSelectColumn($alias . '.email');
            $criteria->addSelectColumn($alias . '.telefone');
            $criteria->addSelectColumn($alias . '.descricao');
            $criteria->addSelectColumn($alias . '.criado_em');
            $criteria->addSelectColumn($alias . '.avisado_em');
            $criteria->addSelectColumn($alias . '.status');
            $criteria->addSelectColumn($alias . '.resolvido_por');
            $criteria->addSelectColumn($alias . '.resolvido_em');
            $criteria->addSelectColumn($alias . '.resolucao');
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
            $criteria->removeSelectColumn(CertificadoRequerimentoTableMap::COL_REQUERIMENTO_ID);
            $criteria->removeSelectColumn(CertificadoRequerimentoTableMap::COL_CASO);
            $criteria->removeSelectColumn(CertificadoRequerimentoTableMap::COL_TOKEN);
            $criteria->removeSelectColumn(CertificadoRequerimentoTableMap::COL_EVENTO);
            $criteria->removeSelectColumn(CertificadoRequerimentoTableMap::COL_EVENTO_TEXTO);
            $criteria->removeSelectColumn(CertificadoRequerimentoTableMap::COL_DOCUMENTO);
            $criteria->removeSelectColumn(CertificadoRequerimentoTableMap::COL_NOME);
            $criteria->removeSelectColumn(CertificadoRequerimentoTableMap::COL_EMAIL);
            $criteria->removeSelectColumn(CertificadoRequerimentoTableMap::COL_TELEFONE);
            $criteria->removeSelectColumn(CertificadoRequerimentoTableMap::COL_DESCRICAO);
            $criteria->removeSelectColumn(CertificadoRequerimentoTableMap::COL_CRIADO_EM);
            $criteria->removeSelectColumn(CertificadoRequerimentoTableMap::COL_AVISADO_EM);
            $criteria->removeSelectColumn(CertificadoRequerimentoTableMap::COL_STATUS);
            $criteria->removeSelectColumn(CertificadoRequerimentoTableMap::COL_RESOLVIDO_POR);
            $criteria->removeSelectColumn(CertificadoRequerimentoTableMap::COL_RESOLVIDO_EM);
            $criteria->removeSelectColumn(CertificadoRequerimentoTableMap::COL_RESOLUCAO);
        } else {
            $criteria->removeSelectColumn($alias . '.requerimento_id');
            $criteria->removeSelectColumn($alias . '.caso');
            $criteria->removeSelectColumn($alias . '.token');
            $criteria->removeSelectColumn($alias . '.evento');
            $criteria->removeSelectColumn($alias . '.evento_texto');
            $criteria->removeSelectColumn($alias . '.documento');
            $criteria->removeSelectColumn($alias . '.nome');
            $criteria->removeSelectColumn($alias . '.email');
            $criteria->removeSelectColumn($alias . '.telefone');
            $criteria->removeSelectColumn($alias . '.descricao');
            $criteria->removeSelectColumn($alias . '.criado_em');
            $criteria->removeSelectColumn($alias . '.avisado_em');
            $criteria->removeSelectColumn($alias . '.status');
            $criteria->removeSelectColumn($alias . '.resolvido_por');
            $criteria->removeSelectColumn($alias . '.resolvido_em');
            $criteria->removeSelectColumn($alias . '.resolucao');
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
        return Propel::getServiceContainer()->getDatabaseMap(CertificadoRequerimentoTableMap::DATABASE_NAME)->getTable(CertificadoRequerimentoTableMap::TABLE_NAME);
    }

    /**
     * Performs a DELETE on the database, given a CertificadoRequerimento or Criteria object OR a primary key value.
     *
     * @param mixed $values Criteria or CertificadoRequerimento object or primary key or array of primary keys
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
            $con = Propel::getServiceContainer()->getWriteConnection(CertificadoRequerimentoTableMap::DATABASE_NAME);
        }

        if ($values instanceof Criteria) {
            // rename for clarity
            $criteria = $values;
        } elseif ($values instanceof \Baja\Model\CertificadoRequerimento) { // it's a model object
            // create criteria based on pk values
            $criteria = $values->buildPkeyCriteria();
        } else { // it's a primary key, or an array of pks
            $criteria = new Criteria(CertificadoRequerimentoTableMap::DATABASE_NAME);
            $criteria->add(CertificadoRequerimentoTableMap::COL_REQUERIMENTO_ID, (array) $values, Criteria::IN);
        }

        $query = CertificadoRequerimentoQuery::create()->mergeWith($criteria);

        if ($values instanceof Criteria) {
            CertificadoRequerimentoTableMap::clearInstancePool();
        } elseif (!is_object($values)) { // it's a primary key, or an array of pks
            foreach ((array) $values as $singleval) {
                CertificadoRequerimentoTableMap::removeInstanceFromPool($singleval);
            }
        }

        return $query->delete($con);
    }

    /**
     * Deletes all rows from the certificado_requerimento table.
     *
     * @param ConnectionInterface $con the connection to use
     * @return int The number of affected rows (if supported by underlying database driver).
     */
    public static function doDeleteAll(?ConnectionInterface $con = null): int
    {
        return CertificadoRequerimentoQuery::create()->doDeleteAll($con);
    }

    /**
     * Performs an INSERT on the database, given a CertificadoRequerimento or Criteria object.
     *
     * @param mixed $criteria Criteria or CertificadoRequerimento object containing data that is used to create the INSERT statement.
     * @param ConnectionInterface $con the ConnectionInterface connection to use
     * @return mixed The new primary key.
     * @throws \Propel\Runtime\Exception\PropelException Any exceptions caught during processing will be
     *                         rethrown wrapped into a PropelException.
     */
    public static function doInsert($criteria, ?ConnectionInterface $con = null)
    {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(CertificadoRequerimentoTableMap::DATABASE_NAME);
        }

        if ($criteria instanceof Criteria) {
            $criteria = clone $criteria; // rename for clarity
        } else {
            $criteria = $criteria->buildCriteria(); // build Criteria from CertificadoRequerimento object
        }


        // Set the correct dbName
        $query = CertificadoRequerimentoQuery::create()->mergeWith($criteria);

        // use transaction because $criteria could contain info
        // for more than one table (I guess, conceivably)
        return $con->transaction(function () use ($con, $query) {
            return $query->doInsert($con);
        });
    }

}
