<?php

namespace Baja\Model\Base;

use \Exception;
use \PDO;
use Baja\Model\ContagemDispositivo as ChildContagemDispositivo;
use Baja\Model\ContagemDispositivoQuery as ChildContagemDispositivoQuery;
use Baja\Model\ContagemFonte as ChildContagemFonte;
use Baja\Model\ContagemFonteQuery as ChildContagemFonteQuery;
use Baja\Model\ContagemPassagemQuery as ChildContagemPassagemQuery;
use Baja\Model\Map\ContagemPassagemTableMap;
use Propel\Runtime\Propel;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\ModelCriteria;
use Propel\Runtime\ActiveRecord\ActiveRecordInterface;
use Propel\Runtime\Collection\Collection;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Exception\BadMethodCallException;
use Propel\Runtime\Exception\LogicException;
use Propel\Runtime\Exception\PropelException;
use Propel\Runtime\Map\TableMap;
use Propel\Runtime\Parser\AbstractParser;

/**
 * Base class that represents a row from the 'contagem_passagem' table.
 *
 *
 *
 * @package    propel.generator.Baja.Model.Base
 */
abstract class ContagemPassagem implements ActiveRecordInterface
{
    /**
     * TableMap class name
     *
     * @var string
     */
    public const TABLE_MAP = '\\Baja\\Model\\Map\\ContagemPassagemTableMap';


    /**
     * attribute to determine if this object has previously been saved.
     * @var bool
     */
    protected $new = true;

    /**
     * attribute to determine whether this object has been deleted.
     * @var bool
     */
    protected $deleted = false;

    /**
     * The columns that have been modified in current object.
     * Tracking modified columns allows us to only update modified columns.
     * @var array
     */
    protected $modifiedColumns = [];

    /**
     * The (virtual) columns that are added at runtime
     * The formatters can add supplementary columns based on a resultset
     * @var array
     */
    protected $virtualColumns = [];

    /**
     * The value for the passagem_id field.
     *
     * @var        string
     */
    protected $passagem_id;

    /**
     * The value for the fonte_id field.
     *
     * @var        int
     */
    protected $fonte_id;

    /**
     * The value for the dispositivo_id field.
     *
     * @var        int
     */
    protected $dispositivo_id;

    /**
     * The value for the uuid_cliente field.
     *
     * @var        string
     */
    protected $uuid_cliente;

    /**
     * The value for the equipe_numero field.
     *
     * @var        int
     */
    protected $equipe_numero;

    /**
     * The value for the ts_ms field.
     *
     * @var        string
     */
    protected $ts_ms;

    /**
     * The value for the qualidade_tempo field.
     *
     * Note: this column has a database default value of: 'exato'
     * @var        string
     */
    protected $qualidade_tempo;

    /**
     * The value for the ts_dispositivo_ms field.
     *
     * @var        string
     */
    protected $ts_dispositivo_ms;

    /**
     * The value for the ancora_servidor_ms field.
     *
     * @var        string|null
     */
    protected $ancora_servidor_ms;

    /**
     * The value for the ancora_rtt_ms field.
     *
     * @var        int|null
     */
    protected $ancora_rtt_ms;

    /**
     * The value for the ancora_idade_ms field.
     *
     * @var        string|null
     */
    protected $ancora_idade_ms;

    /**
     * The value for the digitacao_ms field.
     *
     * @var        int|null
     */
    protected $digitacao_ms;

    /**
     * The value for the modo_entrada field.
     *
     * @var        string
     */
    protected $modo_entrada;

    /**
     * The value for the recebido_em_ms field.
     *
     * @var        string
     */
    protected $recebido_em_ms;

    /**
     * @var        ChildContagemFonte
     */
    protected $aContagemFonte;

    /**
     * @var        ChildContagemDispositivo
     */
    protected $aContagemDispositivo;

    /**
     * Flag to prevent endless save loop, if this object is referenced
     * by another object which falls in this transaction.
     *
     * @var bool
     */
    protected $alreadyInSave = false;

    /**
     * Applies default values to this object.
     * This method should be called from the object's constructor (or
     * equivalent initialization method).
     * @see __construct()
     */
    public function applyDefaultValues(): void
    {
        $this->qualidade_tempo = 'exato';
    }

    /**
     * Initializes internal state of Baja\Model\Base\ContagemPassagem object.
     * @see applyDefaults()
     */
    public function __construct()
    {
        $this->applyDefaultValues();
    }

    /**
     * Returns whether the object has been modified.
     *
     * @return bool True if the object has been modified.
     */
    public function isModified(): bool
    {
        return !!$this->modifiedColumns;
    }

    /**
     * Has specified column been modified?
     *
     * @param string $col column fully qualified name (TableMap::TYPE_COLNAME), e.g. Book::AUTHOR_ID
     * @return bool True if $col has been modified.
     */
    public function isColumnModified(string $col): bool
    {
        return $this->modifiedColumns && isset($this->modifiedColumns[$col]);
    }

    /**
     * Get the columns that have been modified in this object.
     * @return array A unique list of the modified column names for this object.
     */
    public function getModifiedColumns(): array
    {
        return $this->modifiedColumns ? array_keys($this->modifiedColumns) : [];
    }

    /**
     * Returns whether the object has ever been saved.  This will
     * be false, if the object was retrieved from storage or was created
     * and then saved.
     *
     * @return bool True, if the object has never been persisted.
     */
    public function isNew(): bool
    {
        return $this->new;
    }

    /**
     * Setter for the isNew attribute.  This method will be called
     * by Propel-generated children and objects.
     *
     * @param bool $b the state of the object.
     */
    public function setNew(bool $b): void
    {
        $this->new = $b;
    }

    /**
     * Whether this object has been deleted.
     * @return bool The deleted state of this object.
     */
    public function isDeleted(): bool
    {
        return $this->deleted;
    }

    /**
     * Specify whether this object has been deleted.
     * @param bool $b The deleted state of this object.
     * @return void
     */
    public function setDeleted(bool $b): void
    {
        $this->deleted = $b;
    }

    /**
     * Sets the modified state for the object to be false.
     * @param string $col If supplied, only the specified column is reset.
     * @return void
     */
    public function resetModified(?string $col = null): void
    {
        if (null !== $col) {
            unset($this->modifiedColumns[$col]);
        } else {
            $this->modifiedColumns = [];
        }
    }

    /**
     * Compares this with another <code>ContagemPassagem</code> instance.  If
     * <code>obj</code> is an instance of <code>ContagemPassagem</code>, delegates to
     * <code>equals(ContagemPassagem)</code>.  Otherwise, returns <code>false</code>.
     *
     * @param mixed $obj The object to compare to.
     * @return bool Whether equal to the object specified.
     */
    public function equals($obj): bool
    {
        if (!$obj instanceof static) {
            return false;
        }

        if ($this === $obj) {
            return true;
        }

        if (null === $this->getPrimaryKey() || null === $obj->getPrimaryKey()) {
            return false;
        }

        return $this->getPrimaryKey() === $obj->getPrimaryKey();
    }

    /**
     * Get the associative array of the virtual columns in this object
     *
     * @return array
     */
    public function getVirtualColumns(): array
    {
        return $this->virtualColumns;
    }

    /**
     * Checks the existence of a virtual column in this object
     *
     * @param string $name The virtual column name
     * @return bool
     */
    public function hasVirtualColumn(string $name): bool
    {
        return array_key_exists($name, $this->virtualColumns);
    }

    /**
     * Get the value of a virtual column in this object
     *
     * @param string $name The virtual column name
     * @return mixed
     *
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function getVirtualColumn(string $name)
    {
        if (!$this->hasVirtualColumn($name)) {
            throw new PropelException(sprintf('Cannot get value of nonexistent virtual column `%s`.', $name));
        }

        return $this->virtualColumns[$name];
    }

    /**
     * Set the value of a virtual column in this object
     *
     * @param string $name The virtual column name
     * @param mixed $value The value to give to the virtual column
     *
     * @return $this The current object, for fluid interface
     */
    public function setVirtualColumn(string $name, $value)
    {
        $this->virtualColumns[$name] = $value;

        return $this;
    }

    /**
     * Logs a message using Propel::log().
     *
     * @param string $msg
     * @param int $priority One of the Propel::LOG_* logging levels
     * @return void
     */
    protected function log(string $msg, int $priority = Propel::LOG_INFO): void
    {
        Propel::log(get_class($this) . ': ' . $msg, $priority);
    }

    /**
     * Export the current object properties to a string, using a given parser format
     * <code>
     * $book = BookQuery::create()->findPk(9012);
     * echo $book->exportTo('JSON');
     *  => {"Id":9012,"Title":"Don Juan","ISBN":"0140422161","Price":12.99,"PublisherId":1234,"AuthorId":5678}');
     * </code>
     *
     * @param \Propel\Runtime\Parser\AbstractParser|string $parser An AbstractParser instance, or a format name ('XML', 'YAML', 'JSON', 'CSV')
     * @param bool $includeLazyLoadColumns (optional) Whether to include lazy load(ed) columns. Defaults to TRUE.
     * @param string $keyType (optional) One of the class type constants TableMap::TYPE_PHPNAME, TableMap::TYPE_CAMELNAME, TableMap::TYPE_COLNAME, TableMap::TYPE_FIELDNAME, TableMap::TYPE_NUM. Defaults to TableMap::TYPE_PHPNAME.
     * @return string The exported data
     */
    public function exportTo($parser, bool $includeLazyLoadColumns = true, string $keyType = TableMap::TYPE_PHPNAME): string
    {
        if (!$parser instanceof AbstractParser) {
            $parser = AbstractParser::getParser($parser);
        }

        return $parser->fromArray($this->toArray($keyType, $includeLazyLoadColumns, array(), true));
    }

    /**
     * Clean up internal collections prior to serializing
     * Avoids recursive loops that turn into segmentation faults when serializing
     *
     * @return array<string>
     */
    public function __sleep(): array
    {
        $this->clearAllReferences();

        $cls = new \ReflectionClass($this);
        $propertyNames = [];
        $serializableProperties = array_diff($cls->getProperties(), $cls->getProperties(\ReflectionProperty::IS_STATIC));

        foreach($serializableProperties as $property) {
            $propertyNames[] = $property->getName();
        }

        return $propertyNames;
    }

    /**
     * Get the [passagem_id] column value.
     *
     * @return string
     */
    public function getPassagemId()
    {
        return $this->passagem_id;
    }

    /**
     * Get the [fonte_id] column value.
     *
     * @return int
     */
    public function getFonteId()
    {
        return $this->fonte_id;
    }

    /**
     * Get the [dispositivo_id] column value.
     *
     * @return int
     */
    public function getDispositivoId()
    {
        return $this->dispositivo_id;
    }

    /**
     * Get the [uuid_cliente] column value.
     *
     * @return string
     */
    public function getUuidCliente()
    {
        return $this->uuid_cliente;
    }

    /**
     * Get the [equipe_numero] column value.
     *
     * @return int
     */
    public function getEquipeNumero()
    {
        return $this->equipe_numero;
    }

    /**
     * Get the [ts_ms] column value.
     *
     * @return string
     */
    public function getTsMs()
    {
        return $this->ts_ms;
    }

    /**
     * Get the [qualidade_tempo] column value.
     *
     * @return string
     */
    public function getQualidadeTempo()
    {
        return $this->qualidade_tempo;
    }

    /**
     * Get the [ts_dispositivo_ms] column value.
     *
     * @return string
     */
    public function getTsDispositivoMs()
    {
        return $this->ts_dispositivo_ms;
    }

    /**
     * Get the [ancora_servidor_ms] column value.
     *
     * @return string|null
     */
    public function getAncoraServidorMs()
    {
        return $this->ancora_servidor_ms;
    }

    /**
     * Get the [ancora_rtt_ms] column value.
     *
     * @return int|null
     */
    public function getAncoraRttMs()
    {
        return $this->ancora_rtt_ms;
    }

    /**
     * Get the [ancora_idade_ms] column value.
     *
     * @return string|null
     */
    public function getAncoraIdadeMs()
    {
        return $this->ancora_idade_ms;
    }

    /**
     * Get the [digitacao_ms] column value.
     *
     * @return int|null
     */
    public function getDigitacaoMs()
    {
        return $this->digitacao_ms;
    }

    /**
     * Get the [modo_entrada] column value.
     *
     * @return string
     */
    public function getModoEntrada()
    {
        return $this->modo_entrada;
    }

    /**
     * Get the [recebido_em_ms] column value.
     *
     * @return string
     */
    public function getRecebidoEmMs()
    {
        return $this->recebido_em_ms;
    }

    /**
     * Set the value of [passagem_id] column.
     *
     * @param string $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setPassagemId($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->passagem_id !== $v) {
            $this->passagem_id = $v;
            $this->modifiedColumns[ContagemPassagemTableMap::COL_PASSAGEM_ID] = true;
        }

        return $this;
    }

    /**
     * Set the value of [fonte_id] column.
     *
     * @param int $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setFonteId($v)
    {
        if ($v !== null) {
            $v = (int) $v;
        }

        if ($this->fonte_id !== $v) {
            $this->fonte_id = $v;
            $this->modifiedColumns[ContagemPassagemTableMap::COL_FONTE_ID] = true;
        }

        if ($this->aContagemFonte !== null && $this->aContagemFonte->getFonteId() !== $v) {
            $this->aContagemFonte = null;
        }

        return $this;
    }

    /**
     * Set the value of [dispositivo_id] column.
     *
     * @param int $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setDispositivoId($v)
    {
        if ($v !== null) {
            $v = (int) $v;
        }

        if ($this->dispositivo_id !== $v) {
            $this->dispositivo_id = $v;
            $this->modifiedColumns[ContagemPassagemTableMap::COL_DISPOSITIVO_ID] = true;
        }

        if ($this->aContagemDispositivo !== null && $this->aContagemDispositivo->getDispositivoId() !== $v) {
            $this->aContagemDispositivo = null;
        }

        return $this;
    }

    /**
     * Set the value of [uuid_cliente] column.
     *
     * @param string $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setUuidCliente($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->uuid_cliente !== $v) {
            $this->uuid_cliente = $v;
            $this->modifiedColumns[ContagemPassagemTableMap::COL_UUID_CLIENTE] = true;
        }

        return $this;
    }

    /**
     * Set the value of [equipe_numero] column.
     *
     * @param int $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setEquipeNumero($v)
    {
        if ($v !== null) {
            $v = (int) $v;
        }

        if ($this->equipe_numero !== $v) {
            $this->equipe_numero = $v;
            $this->modifiedColumns[ContagemPassagemTableMap::COL_EQUIPE_NUMERO] = true;
        }

        return $this;
    }

    /**
     * Set the value of [ts_ms] column.
     *
     * @param string $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setTsMs($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->ts_ms !== $v) {
            $this->ts_ms = $v;
            $this->modifiedColumns[ContagemPassagemTableMap::COL_TS_MS] = true;
        }

        return $this;
    }

    /**
     * Set the value of [qualidade_tempo] column.
     *
     * @param string $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setQualidadeTempo($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->qualidade_tempo !== $v) {
            $this->qualidade_tempo = $v;
            $this->modifiedColumns[ContagemPassagemTableMap::COL_QUALIDADE_TEMPO] = true;
        }

        return $this;
    }

    /**
     * Set the value of [ts_dispositivo_ms] column.
     *
     * @param string $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setTsDispositivoMs($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->ts_dispositivo_ms !== $v) {
            $this->ts_dispositivo_ms = $v;
            $this->modifiedColumns[ContagemPassagemTableMap::COL_TS_DISPOSITIVO_MS] = true;
        }

        return $this;
    }

    /**
     * Set the value of [ancora_servidor_ms] column.
     *
     * @param string|null $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setAncoraServidorMs($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->ancora_servidor_ms !== $v) {
            $this->ancora_servidor_ms = $v;
            $this->modifiedColumns[ContagemPassagemTableMap::COL_ANCORA_SERVIDOR_MS] = true;
        }

        return $this;
    }

    /**
     * Set the value of [ancora_rtt_ms] column.
     *
     * @param int|null $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setAncoraRttMs($v)
    {
        if ($v !== null) {
            $v = (int) $v;
        }

        if ($this->ancora_rtt_ms !== $v) {
            $this->ancora_rtt_ms = $v;
            $this->modifiedColumns[ContagemPassagemTableMap::COL_ANCORA_RTT_MS] = true;
        }

        return $this;
    }

    /**
     * Set the value of [ancora_idade_ms] column.
     *
     * @param string|null $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setAncoraIdadeMs($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->ancora_idade_ms !== $v) {
            $this->ancora_idade_ms = $v;
            $this->modifiedColumns[ContagemPassagemTableMap::COL_ANCORA_IDADE_MS] = true;
        }

        return $this;
    }

    /**
     * Set the value of [digitacao_ms] column.
     *
     * @param int|null $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setDigitacaoMs($v)
    {
        if ($v !== null) {
            $v = (int) $v;
        }

        if ($this->digitacao_ms !== $v) {
            $this->digitacao_ms = $v;
            $this->modifiedColumns[ContagemPassagemTableMap::COL_DIGITACAO_MS] = true;
        }

        return $this;
    }

    /**
     * Set the value of [modo_entrada] column.
     *
     * @param string $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setModoEntrada($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->modo_entrada !== $v) {
            $this->modo_entrada = $v;
            $this->modifiedColumns[ContagemPassagemTableMap::COL_MODO_ENTRADA] = true;
        }

        return $this;
    }

    /**
     * Set the value of [recebido_em_ms] column.
     *
     * @param string $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setRecebidoEmMs($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->recebido_em_ms !== $v) {
            $this->recebido_em_ms = $v;
            $this->modifiedColumns[ContagemPassagemTableMap::COL_RECEBIDO_EM_MS] = true;
        }

        return $this;
    }

    /**
     * Indicates whether the columns in this object are only set to default values.
     *
     * This method can be used in conjunction with isModified() to indicate whether an object is both
     * modified _and_ has some values set which are non-default.
     *
     * @return bool Whether the columns in this object are only been set with default values.
     */
    public function hasOnlyDefaultValues(): bool
    {
            if ($this->qualidade_tempo !== 'exato') {
                return false;
            }

        // otherwise, everything was equal, so return TRUE
        return true;
    }

    /**
     * Hydrates (populates) the object variables with values from the database resultset.
     *
     * An offset (0-based "start column") is specified so that objects can be hydrated
     * with a subset of the columns in the resultset rows.  This is needed, for example,
     * for results of JOIN queries where the resultset row includes columns from two or
     * more tables.
     *
     * @param array $row The row returned by DataFetcher->fetch().
     * @param int $startcol 0-based offset column which indicates which resultset column to start with.
     * @param bool $rehydrate Whether this object is being re-hydrated from the database.
     * @param string $indexType The index type of $row. Mostly DataFetcher->getIndexType().
                                  One of the class type constants TableMap::TYPE_PHPNAME, TableMap::TYPE_CAMELNAME
     *                            TableMap::TYPE_COLNAME, TableMap::TYPE_FIELDNAME, TableMap::TYPE_NUM.
     *
     * @return int next starting column
     * @throws \Propel\Runtime\Exception\PropelException - Any caught Exception will be rewrapped as a PropelException.
     */
    public function hydrate(array $row, int $startcol = 0, bool $rehydrate = false, string $indexType = TableMap::TYPE_NUM): int
    {
        try {

            $col = $row[TableMap::TYPE_NUM == $indexType ? 0 + $startcol : ContagemPassagemTableMap::translateFieldName('PassagemId', TableMap::TYPE_PHPNAME, $indexType)];
            $this->passagem_id = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 1 + $startcol : ContagemPassagemTableMap::translateFieldName('FonteId', TableMap::TYPE_PHPNAME, $indexType)];
            $this->fonte_id = (null !== $col) ? (int) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 2 + $startcol : ContagemPassagemTableMap::translateFieldName('DispositivoId', TableMap::TYPE_PHPNAME, $indexType)];
            $this->dispositivo_id = (null !== $col) ? (int) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 3 + $startcol : ContagemPassagemTableMap::translateFieldName('UuidCliente', TableMap::TYPE_PHPNAME, $indexType)];
            $this->uuid_cliente = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 4 + $startcol : ContagemPassagemTableMap::translateFieldName('EquipeNumero', TableMap::TYPE_PHPNAME, $indexType)];
            $this->equipe_numero = (null !== $col) ? (int) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 5 + $startcol : ContagemPassagemTableMap::translateFieldName('TsMs', TableMap::TYPE_PHPNAME, $indexType)];
            $this->ts_ms = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 6 + $startcol : ContagemPassagemTableMap::translateFieldName('QualidadeTempo', TableMap::TYPE_PHPNAME, $indexType)];
            $this->qualidade_tempo = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 7 + $startcol : ContagemPassagemTableMap::translateFieldName('TsDispositivoMs', TableMap::TYPE_PHPNAME, $indexType)];
            $this->ts_dispositivo_ms = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 8 + $startcol : ContagemPassagemTableMap::translateFieldName('AncoraServidorMs', TableMap::TYPE_PHPNAME, $indexType)];
            $this->ancora_servidor_ms = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 9 + $startcol : ContagemPassagemTableMap::translateFieldName('AncoraRttMs', TableMap::TYPE_PHPNAME, $indexType)];
            $this->ancora_rtt_ms = (null !== $col) ? (int) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 10 + $startcol : ContagemPassagemTableMap::translateFieldName('AncoraIdadeMs', TableMap::TYPE_PHPNAME, $indexType)];
            $this->ancora_idade_ms = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 11 + $startcol : ContagemPassagemTableMap::translateFieldName('DigitacaoMs', TableMap::TYPE_PHPNAME, $indexType)];
            $this->digitacao_ms = (null !== $col) ? (int) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 12 + $startcol : ContagemPassagemTableMap::translateFieldName('ModoEntrada', TableMap::TYPE_PHPNAME, $indexType)];
            $this->modo_entrada = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 13 + $startcol : ContagemPassagemTableMap::translateFieldName('RecebidoEmMs', TableMap::TYPE_PHPNAME, $indexType)];
            $this->recebido_em_ms = (null !== $col) ? (string) $col : null;

            $this->resetModified();
            $this->setNew(false);

            if ($rehydrate) {
                $this->ensureConsistency();
            }

            return $startcol + 14; // 14 = ContagemPassagemTableMap::NUM_HYDRATE_COLUMNS.

        } catch (Exception $e) {
            throw new PropelException(sprintf('Error populating %s object', '\\Baja\\Model\\ContagemPassagem'), 0, $e);
        }
    }

    /**
     * Checks and repairs the internal consistency of the object.
     *
     * This method is executed after an already-instantiated object is re-hydrated
     * from the database.  It exists to check any foreign keys to make sure that
     * the objects related to the current object are correct based on foreign key.
     *
     * You can override this method in the stub class, but you should always invoke
     * the base method from the overridden method (i.e. parent::ensureConsistency()),
     * in case your model changes.
     *
     * @throws \Propel\Runtime\Exception\PropelException
     * @return void
     */
    public function ensureConsistency(): void
    {
        if ($this->aContagemFonte !== null && $this->fonte_id !== $this->aContagemFonte->getFonteId()) {
            $this->aContagemFonte = null;
        }
        if ($this->aContagemDispositivo !== null && $this->dispositivo_id !== $this->aContagemDispositivo->getDispositivoId()) {
            $this->aContagemDispositivo = null;
        }
    }

    /**
     * Reloads this object from datastore based on primary key and (optionally) resets all associated objects.
     *
     * This will only work if the object has been saved and has a valid primary key set.
     *
     * @param bool $deep (optional) Whether to also de-associated any related objects.
     * @param ConnectionInterface $con (optional) The ConnectionInterface connection to use.
     * @return void
     * @throws \Propel\Runtime\Exception\PropelException - if this object is deleted, unsaved or doesn't have pk match in db
     */
    public function reload(bool $deep = false, ?ConnectionInterface $con = null): void
    {
        if ($this->isDeleted()) {
            throw new PropelException("Cannot reload a deleted object.");
        }

        if ($this->isNew()) {
            throw new PropelException("Cannot reload an unsaved object.");
        }

        if ($con === null) {
            $con = Propel::getServiceContainer()->getReadConnection(ContagemPassagemTableMap::DATABASE_NAME);
        }

        // We don't need to alter the object instance pool; we're just modifying this instance
        // already in the pool.

        $dataFetcher = ChildContagemPassagemQuery::create(null, $this->buildPkeyCriteria())->setFormatter(ModelCriteria::FORMAT_STATEMENT)->find($con);
        $row = $dataFetcher->fetch();
        $dataFetcher->close();
        if (!$row) {
            throw new PropelException('Cannot find matching row in the database to reload object values.');
        }
        $this->hydrate($row, 0, true, $dataFetcher->getIndexType()); // rehydrate

        if ($deep) {  // also de-associate any related objects?

            $this->aContagemFonte = null;
            $this->aContagemDispositivo = null;
        } // if (deep)
    }

    /**
     * Removes this object from datastore and sets delete attribute.
     *
     * @param ConnectionInterface $con
     * @return void
     * @throws \Propel\Runtime\Exception\PropelException
     * @see ContagemPassagem::setDeleted()
     * @see ContagemPassagem::isDeleted()
     */
    public function delete(?ConnectionInterface $con = null): void
    {
        if ($this->isDeleted()) {
            throw new PropelException("This object has already been deleted.");
        }

        if ($con === null) {
            $con = Propel::getServiceContainer()->getWriteConnection(ContagemPassagemTableMap::DATABASE_NAME);
        }

        $con->transaction(function () use ($con) {
            $deleteQuery = ChildContagemPassagemQuery::create()
                ->filterByPrimaryKey($this->getPrimaryKey());
            $ret = $this->preDelete($con);
            if ($ret) {
                $deleteQuery->delete($con);
                $this->postDelete($con);
                $this->setDeleted(true);
            }
        });
    }

    /**
     * Persists this object to the database.
     *
     * If the object is new, it inserts it; otherwise an update is performed.
     * All modified related objects will also be persisted in the doSave()
     * method.  This method wraps all precipitate database operations in a
     * single transaction.
     *
     * @param ConnectionInterface $con
     * @return int The number of rows affected by this insert/update and any referring fk objects' save() operations.
     * @throws \Propel\Runtime\Exception\PropelException
     * @see doSave()
     */
    public function save(?ConnectionInterface $con = null): int
    {
        if ($this->isDeleted()) {
            throw new PropelException("You cannot save an object that has been deleted.");
        }

        if ($this->alreadyInSave) {
            return 0;
        }

        if ($con === null) {
            $con = Propel::getServiceContainer()->getWriteConnection(ContagemPassagemTableMap::DATABASE_NAME);
        }

        return $con->transaction(function () use ($con) {
            $ret = $this->preSave($con);
            $isInsert = $this->isNew();
            if ($isInsert) {
                $ret = $ret && $this->preInsert($con);
            } else {
                $ret = $ret && $this->preUpdate($con);
            }
            if ($ret) {
                $affectedRows = $this->doSave($con);
                if ($isInsert) {
                    $this->postInsert($con);
                } else {
                    $this->postUpdate($con);
                }
                $this->postSave($con);
                ContagemPassagemTableMap::addInstanceToPool($this);
            } else {
                $affectedRows = 0;
            }

            return $affectedRows;
        });
    }

    /**
     * Performs the work of inserting or updating the row in the database.
     *
     * If the object is new, it inserts it; otherwise an update is performed.
     * All related objects are also updated in this method.
     *
     * @param ConnectionInterface $con
     * @return int The number of rows affected by this insert/update and any referring fk objects' save() operations.
     * @throws \Propel\Runtime\Exception\PropelException
     * @see save()
     */
    protected function doSave(ConnectionInterface $con): int
    {
        $affectedRows = 0; // initialize var to track total num of affected rows
        if (!$this->alreadyInSave) {
            $this->alreadyInSave = true;

            // We call the save method on the following object(s) if they
            // were passed to this object by their corresponding set
            // method.  This object relates to these object(s) by a
            // foreign key reference.

            if ($this->aContagemFonte !== null) {
                if ($this->aContagemFonte->isModified() || $this->aContagemFonte->isNew()) {
                    $affectedRows += $this->aContagemFonte->save($con);
                }
                $this->setContagemFonte($this->aContagemFonte);
            }

            if ($this->aContagemDispositivo !== null) {
                if ($this->aContagemDispositivo->isModified() || $this->aContagemDispositivo->isNew()) {
                    $affectedRows += $this->aContagemDispositivo->save($con);
                }
                $this->setContagemDispositivo($this->aContagemDispositivo);
            }

            if ($this->isNew() || $this->isModified()) {
                // persist changes
                if ($this->isNew()) {
                    $this->doInsert($con);
                    $affectedRows += 1;
                } else {
                    $affectedRows += $this->doUpdate($con);
                }
                $this->resetModified();
            }

            $this->alreadyInSave = false;

        }

        return $affectedRows;
    }

    /**
     * Insert the row in the database.
     *
     * @param ConnectionInterface $con
     *
     * @throws \Propel\Runtime\Exception\PropelException
     * @see doSave()
     */
    protected function doInsert(ConnectionInterface $con): void
    {
        $modifiedColumns = [];
        $index = 0;

        $this->modifiedColumns[ContagemPassagemTableMap::COL_PASSAGEM_ID] = true;
        if (null !== $this->passagem_id) {
            throw new PropelException('Cannot insert a value for auto-increment primary key (' . ContagemPassagemTableMap::COL_PASSAGEM_ID . ')');
        }

         // check the columns in natural order for more readable SQL queries
        if ($this->isColumnModified(ContagemPassagemTableMap::COL_PASSAGEM_ID)) {
            $modifiedColumns[':p' . $index++]  = 'passagem_id';
        }
        if ($this->isColumnModified(ContagemPassagemTableMap::COL_FONTE_ID)) {
            $modifiedColumns[':p' . $index++]  = 'fonte_id';
        }
        if ($this->isColumnModified(ContagemPassagemTableMap::COL_DISPOSITIVO_ID)) {
            $modifiedColumns[':p' . $index++]  = 'dispositivo_id';
        }
        if ($this->isColumnModified(ContagemPassagemTableMap::COL_UUID_CLIENTE)) {
            $modifiedColumns[':p' . $index++]  = 'uuid_cliente';
        }
        if ($this->isColumnModified(ContagemPassagemTableMap::COL_EQUIPE_NUMERO)) {
            $modifiedColumns[':p' . $index++]  = 'equipe_numero';
        }
        if ($this->isColumnModified(ContagemPassagemTableMap::COL_TS_MS)) {
            $modifiedColumns[':p' . $index++]  = 'ts_ms';
        }
        if ($this->isColumnModified(ContagemPassagemTableMap::COL_QUALIDADE_TEMPO)) {
            $modifiedColumns[':p' . $index++]  = 'qualidade_tempo';
        }
        if ($this->isColumnModified(ContagemPassagemTableMap::COL_TS_DISPOSITIVO_MS)) {
            $modifiedColumns[':p' . $index++]  = 'ts_dispositivo_ms';
        }
        if ($this->isColumnModified(ContagemPassagemTableMap::COL_ANCORA_SERVIDOR_MS)) {
            $modifiedColumns[':p' . $index++]  = 'ancora_servidor_ms';
        }
        if ($this->isColumnModified(ContagemPassagemTableMap::COL_ANCORA_RTT_MS)) {
            $modifiedColumns[':p' . $index++]  = 'ancora_rtt_ms';
        }
        if ($this->isColumnModified(ContagemPassagemTableMap::COL_ANCORA_IDADE_MS)) {
            $modifiedColumns[':p' . $index++]  = 'ancora_idade_ms';
        }
        if ($this->isColumnModified(ContagemPassagemTableMap::COL_DIGITACAO_MS)) {
            $modifiedColumns[':p' . $index++]  = 'digitacao_ms';
        }
        if ($this->isColumnModified(ContagemPassagemTableMap::COL_MODO_ENTRADA)) {
            $modifiedColumns[':p' . $index++]  = 'modo_entrada';
        }
        if ($this->isColumnModified(ContagemPassagemTableMap::COL_RECEBIDO_EM_MS)) {
            $modifiedColumns[':p' . $index++]  = 'recebido_em_ms';
        }

        $sql = sprintf(
            'INSERT INTO contagem_passagem (%s) VALUES (%s)',
            implode(', ', $modifiedColumns),
            implode(', ', array_keys($modifiedColumns))
        );

        try {
            $stmt = $con->prepare($sql);
            foreach ($modifiedColumns as $identifier => $columnName) {
                switch ($columnName) {
                    case 'passagem_id':
                        $stmt->bindValue($identifier, $this->passagem_id, PDO::PARAM_INT);

                        break;
                    case 'fonte_id':
                        $stmt->bindValue($identifier, $this->fonte_id, PDO::PARAM_INT);

                        break;
                    case 'dispositivo_id':
                        $stmt->bindValue($identifier, $this->dispositivo_id, PDO::PARAM_INT);

                        break;
                    case 'uuid_cliente':
                        $stmt->bindValue($identifier, $this->uuid_cliente, PDO::PARAM_STR);

                        break;
                    case 'equipe_numero':
                        $stmt->bindValue($identifier, $this->equipe_numero, PDO::PARAM_INT);

                        break;
                    case 'ts_ms':
                        $stmt->bindValue($identifier, $this->ts_ms, PDO::PARAM_INT);

                        break;
                    case 'qualidade_tempo':
                        $stmt->bindValue($identifier, $this->qualidade_tempo, PDO::PARAM_STR);

                        break;
                    case 'ts_dispositivo_ms':
                        $stmt->bindValue($identifier, $this->ts_dispositivo_ms, PDO::PARAM_INT);

                        break;
                    case 'ancora_servidor_ms':
                        $stmt->bindValue($identifier, $this->ancora_servidor_ms, PDO::PARAM_INT);

                        break;
                    case 'ancora_rtt_ms':
                        $stmt->bindValue($identifier, $this->ancora_rtt_ms, PDO::PARAM_INT);

                        break;
                    case 'ancora_idade_ms':
                        $stmt->bindValue($identifier, $this->ancora_idade_ms, PDO::PARAM_INT);

                        break;
                    case 'digitacao_ms':
                        $stmt->bindValue($identifier, $this->digitacao_ms, PDO::PARAM_INT);

                        break;
                    case 'modo_entrada':
                        $stmt->bindValue($identifier, $this->modo_entrada, PDO::PARAM_STR);

                        break;
                    case 'recebido_em_ms':
                        $stmt->bindValue($identifier, $this->recebido_em_ms, PDO::PARAM_INT);

                        break;
                }
            }
            $stmt->execute();
        } catch (Exception $e) {
            Propel::log($e->getMessage(), Propel::LOG_ERR);
            throw new PropelException(sprintf('Unable to execute INSERT statement [%s]', $sql), 0, $e);
        }

        try {
            $pk = $con->lastInsertId();
        } catch (Exception $e) {
            throw new PropelException('Unable to get autoincrement id.', 0, $e);
        }
        $this->setPassagemId($pk);

        $this->setNew(false);
    }

    /**
     * Update the row in the database.
     *
     * @param ConnectionInterface $con
     *
     * @return int Number of updated rows
     * @see doSave()
     */
    protected function doUpdate(ConnectionInterface $con): int
    {
        $selectCriteria = $this->buildPkeyCriteria();
        $valuesCriteria = $this->buildCriteria();

        return $selectCriteria->doUpdate($valuesCriteria, $con);
    }

    /**
     * Retrieves a field from the object by name passed in as a string.
     *
     * @param string $name name
     * @param string $type The type of fieldname the $name is of:
     *                     one of the class type constants TableMap::TYPE_PHPNAME, TableMap::TYPE_CAMELNAME
     *                     TableMap::TYPE_COLNAME, TableMap::TYPE_FIELDNAME, TableMap::TYPE_NUM.
     *                     Defaults to TableMap::TYPE_PHPNAME.
     * @return mixed Value of field.
     */
    public function getByName(string $name, string $type = TableMap::TYPE_PHPNAME)
    {
        $pos = ContagemPassagemTableMap::translateFieldName($name, $type, TableMap::TYPE_NUM);
        $field = $this->getByPosition($pos);

        return $field;
    }

    /**
     * Retrieves a field from the object by Position as specified in the xml schema.
     * Zero-based.
     *
     * @param int $pos Position in XML schema
     * @return mixed Value of field at $pos
     */
    public function getByPosition(int $pos)
    {
        switch ($pos) {
            case 0:
                return $this->getPassagemId();

            case 1:
                return $this->getFonteId();

            case 2:
                return $this->getDispositivoId();

            case 3:
                return $this->getUuidCliente();

            case 4:
                return $this->getEquipeNumero();

            case 5:
                return $this->getTsMs();

            case 6:
                return $this->getQualidadeTempo();

            case 7:
                return $this->getTsDispositivoMs();

            case 8:
                return $this->getAncoraServidorMs();

            case 9:
                return $this->getAncoraRttMs();

            case 10:
                return $this->getAncoraIdadeMs();

            case 11:
                return $this->getDigitacaoMs();

            case 12:
                return $this->getModoEntrada();

            case 13:
                return $this->getRecebidoEmMs();

            default:
                return null;
        } // switch()
    }

    /**
     * Exports the object as an array.
     *
     * You can specify the key type of the array by passing one of the class
     * type constants.
     *
     * @param string $keyType (optional) One of the class type constants TableMap::TYPE_PHPNAME, TableMap::TYPE_CAMELNAME,
     *                    TableMap::TYPE_COLNAME, TableMap::TYPE_FIELDNAME, TableMap::TYPE_NUM.
     *                    Defaults to TableMap::TYPE_PHPNAME.
     * @param bool $includeLazyLoadColumns (optional) Whether to include lazy loaded columns. Defaults to TRUE.
     * @param array $alreadyDumpedObjects List of objects to skip to avoid recursion
     * @param bool $includeForeignObjects (optional) Whether to include hydrated related objects. Default to FALSE.
     *
     * @return array An associative array containing the field names (as keys) and field values
     */
    public function toArray(string $keyType = TableMap::TYPE_PHPNAME, bool $includeLazyLoadColumns = true, array $alreadyDumpedObjects = [], bool $includeForeignObjects = false): array
    {
        if (isset($alreadyDumpedObjects['ContagemPassagem'][$this->hashCode()])) {
            return ['*RECURSION*'];
        }
        $alreadyDumpedObjects['ContagemPassagem'][$this->hashCode()] = true;
        $keys = ContagemPassagemTableMap::getFieldNames($keyType);
        $result = [
            $keys[0] => $this->getPassagemId(),
            $keys[1] => $this->getFonteId(),
            $keys[2] => $this->getDispositivoId(),
            $keys[3] => $this->getUuidCliente(),
            $keys[4] => $this->getEquipeNumero(),
            $keys[5] => $this->getTsMs(),
            $keys[6] => $this->getQualidadeTempo(),
            $keys[7] => $this->getTsDispositivoMs(),
            $keys[8] => $this->getAncoraServidorMs(),
            $keys[9] => $this->getAncoraRttMs(),
            $keys[10] => $this->getAncoraIdadeMs(),
            $keys[11] => $this->getDigitacaoMs(),
            $keys[12] => $this->getModoEntrada(),
            $keys[13] => $this->getRecebidoEmMs(),
        ];
        $virtualColumns = $this->virtualColumns;
        foreach ($virtualColumns as $key => $virtualColumn) {
            $result[$key] = $virtualColumn;
        }

        if ($includeForeignObjects) {
            if (null !== $this->aContagemFonte) {

                switch ($keyType) {
                    case TableMap::TYPE_CAMELNAME:
                        $key = 'contagemFonte';
                        break;
                    case TableMap::TYPE_FIELDNAME:
                        $key = 'contagem_fonte';
                        break;
                    default:
                        $key = 'ContagemFonte';
                }

                $result[$key] = $this->aContagemFonte->toArray($keyType, $includeLazyLoadColumns,  $alreadyDumpedObjects, true);
            }
            if (null !== $this->aContagemDispositivo) {

                switch ($keyType) {
                    case TableMap::TYPE_CAMELNAME:
                        $key = 'contagemDispositivo';
                        break;
                    case TableMap::TYPE_FIELDNAME:
                        $key = 'contagem_dispositivo';
                        break;
                    default:
                        $key = 'ContagemDispositivo';
                }

                $result[$key] = $this->aContagemDispositivo->toArray($keyType, $includeLazyLoadColumns,  $alreadyDumpedObjects, true);
            }
        }

        return $result;
    }

    /**
     * Sets a field from the object by name passed in as a string.
     *
     * @param string $name
     * @param mixed $value field value
     * @param string $type The type of fieldname the $name is of:
     *                one of the class type constants TableMap::TYPE_PHPNAME, TableMap::TYPE_CAMELNAME
     *                TableMap::TYPE_COLNAME, TableMap::TYPE_FIELDNAME, TableMap::TYPE_NUM.
     *                Defaults to TableMap::TYPE_PHPNAME.
     * @return $this
     */
    public function setByName(string $name, $value, string $type = TableMap::TYPE_PHPNAME)
    {
        $pos = ContagemPassagemTableMap::translateFieldName($name, $type, TableMap::TYPE_NUM);

        $this->setByPosition($pos, $value);

        return $this;
    }

    /**
     * Sets a field from the object by Position as specified in the xml schema.
     * Zero-based.
     *
     * @param int $pos position in xml schema
     * @param mixed $value field value
     * @return $this
     */
    public function setByPosition(int $pos, $value)
    {
        switch ($pos) {
            case 0:
                $this->setPassagemId($value);
                break;
            case 1:
                $this->setFonteId($value);
                break;
            case 2:
                $this->setDispositivoId($value);
                break;
            case 3:
                $this->setUuidCliente($value);
                break;
            case 4:
                $this->setEquipeNumero($value);
                break;
            case 5:
                $this->setTsMs($value);
                break;
            case 6:
                $this->setQualidadeTempo($value);
                break;
            case 7:
                $this->setTsDispositivoMs($value);
                break;
            case 8:
                $this->setAncoraServidorMs($value);
                break;
            case 9:
                $this->setAncoraRttMs($value);
                break;
            case 10:
                $this->setAncoraIdadeMs($value);
                break;
            case 11:
                $this->setDigitacaoMs($value);
                break;
            case 12:
                $this->setModoEntrada($value);
                break;
            case 13:
                $this->setRecebidoEmMs($value);
                break;
        } // switch()

        return $this;
    }

    /**
     * Populates the object using an array.
     *
     * This is particularly useful when populating an object from one of the
     * request arrays (e.g. $_POST).  This method goes through the column
     * names, checking to see whether a matching key exists in populated
     * array. If so the setByName() method is called for that column.
     *
     * You can specify the key type of the array by additionally passing one
     * of the class type constants TableMap::TYPE_PHPNAME, TableMap::TYPE_CAMELNAME,
     * TableMap::TYPE_COLNAME, TableMap::TYPE_FIELDNAME, TableMap::TYPE_NUM.
     * The default key type is the column's TableMap::TYPE_PHPNAME.
     *
     * @param array $arr An array to populate the object from.
     * @param string $keyType The type of keys the array uses.
     * @return $this
     */
    public function fromArray(array $arr, string $keyType = TableMap::TYPE_PHPNAME)
    {
        $keys = ContagemPassagemTableMap::getFieldNames($keyType);

        if (array_key_exists($keys[0], $arr)) {
            $this->setPassagemId($arr[$keys[0]]);
        }
        if (array_key_exists($keys[1], $arr)) {
            $this->setFonteId($arr[$keys[1]]);
        }
        if (array_key_exists($keys[2], $arr)) {
            $this->setDispositivoId($arr[$keys[2]]);
        }
        if (array_key_exists($keys[3], $arr)) {
            $this->setUuidCliente($arr[$keys[3]]);
        }
        if (array_key_exists($keys[4], $arr)) {
            $this->setEquipeNumero($arr[$keys[4]]);
        }
        if (array_key_exists($keys[5], $arr)) {
            $this->setTsMs($arr[$keys[5]]);
        }
        if (array_key_exists($keys[6], $arr)) {
            $this->setQualidadeTempo($arr[$keys[6]]);
        }
        if (array_key_exists($keys[7], $arr)) {
            $this->setTsDispositivoMs($arr[$keys[7]]);
        }
        if (array_key_exists($keys[8], $arr)) {
            $this->setAncoraServidorMs($arr[$keys[8]]);
        }
        if (array_key_exists($keys[9], $arr)) {
            $this->setAncoraRttMs($arr[$keys[9]]);
        }
        if (array_key_exists($keys[10], $arr)) {
            $this->setAncoraIdadeMs($arr[$keys[10]]);
        }
        if (array_key_exists($keys[11], $arr)) {
            $this->setDigitacaoMs($arr[$keys[11]]);
        }
        if (array_key_exists($keys[12], $arr)) {
            $this->setModoEntrada($arr[$keys[12]]);
        }
        if (array_key_exists($keys[13], $arr)) {
            $this->setRecebidoEmMs($arr[$keys[13]]);
        }

        return $this;
    }

     /**
     * Populate the current object from a string, using a given parser format
     * <code>
     * $book = new Book();
     * $book->importFrom('JSON', '{"Id":9012,"Title":"Don Juan","ISBN":"0140422161","Price":12.99,"PublisherId":1234,"AuthorId":5678}');
     * </code>
     *
     * You can specify the key type of the array by additionally passing one
     * of the class type constants TableMap::TYPE_PHPNAME, TableMap::TYPE_CAMELNAME,
     * TableMap::TYPE_COLNAME, TableMap::TYPE_FIELDNAME, TableMap::TYPE_NUM.
     * The default key type is the column's TableMap::TYPE_PHPNAME.
     *
     * @param mixed $parser A AbstractParser instance,
     *                       or a format name ('XML', 'YAML', 'JSON', 'CSV')
     * @param string $data The source data to import from
     * @param string $keyType The type of keys the array uses.
     *
     * @return $this The current object, for fluid interface
     */
    public function importFrom($parser, string $data, string $keyType = TableMap::TYPE_PHPNAME)
    {
        if (!$parser instanceof AbstractParser) {
            $parser = AbstractParser::getParser($parser);
        }

        $this->fromArray($parser->toArray($data), $keyType);

        return $this;
    }

    /**
     * Build a Criteria object containing the values of all modified columns in this object.
     *
     * @return \Propel\Runtime\ActiveQuery\Criteria The Criteria object containing all modified values.
     */
    public function buildCriteria(): Criteria
    {
        $criteria = new Criteria(ContagemPassagemTableMap::DATABASE_NAME);

        if ($this->isColumnModified(ContagemPassagemTableMap::COL_PASSAGEM_ID)) {
            $criteria->add(ContagemPassagemTableMap::COL_PASSAGEM_ID, $this->passagem_id);
        }
        if ($this->isColumnModified(ContagemPassagemTableMap::COL_FONTE_ID)) {
            $criteria->add(ContagemPassagemTableMap::COL_FONTE_ID, $this->fonte_id);
        }
        if ($this->isColumnModified(ContagemPassagemTableMap::COL_DISPOSITIVO_ID)) {
            $criteria->add(ContagemPassagemTableMap::COL_DISPOSITIVO_ID, $this->dispositivo_id);
        }
        if ($this->isColumnModified(ContagemPassagemTableMap::COL_UUID_CLIENTE)) {
            $criteria->add(ContagemPassagemTableMap::COL_UUID_CLIENTE, $this->uuid_cliente);
        }
        if ($this->isColumnModified(ContagemPassagemTableMap::COL_EQUIPE_NUMERO)) {
            $criteria->add(ContagemPassagemTableMap::COL_EQUIPE_NUMERO, $this->equipe_numero);
        }
        if ($this->isColumnModified(ContagemPassagemTableMap::COL_TS_MS)) {
            $criteria->add(ContagemPassagemTableMap::COL_TS_MS, $this->ts_ms);
        }
        if ($this->isColumnModified(ContagemPassagemTableMap::COL_QUALIDADE_TEMPO)) {
            $criteria->add(ContagemPassagemTableMap::COL_QUALIDADE_TEMPO, $this->qualidade_tempo);
        }
        if ($this->isColumnModified(ContagemPassagemTableMap::COL_TS_DISPOSITIVO_MS)) {
            $criteria->add(ContagemPassagemTableMap::COL_TS_DISPOSITIVO_MS, $this->ts_dispositivo_ms);
        }
        if ($this->isColumnModified(ContagemPassagemTableMap::COL_ANCORA_SERVIDOR_MS)) {
            $criteria->add(ContagemPassagemTableMap::COL_ANCORA_SERVIDOR_MS, $this->ancora_servidor_ms);
        }
        if ($this->isColumnModified(ContagemPassagemTableMap::COL_ANCORA_RTT_MS)) {
            $criteria->add(ContagemPassagemTableMap::COL_ANCORA_RTT_MS, $this->ancora_rtt_ms);
        }
        if ($this->isColumnModified(ContagemPassagemTableMap::COL_ANCORA_IDADE_MS)) {
            $criteria->add(ContagemPassagemTableMap::COL_ANCORA_IDADE_MS, $this->ancora_idade_ms);
        }
        if ($this->isColumnModified(ContagemPassagemTableMap::COL_DIGITACAO_MS)) {
            $criteria->add(ContagemPassagemTableMap::COL_DIGITACAO_MS, $this->digitacao_ms);
        }
        if ($this->isColumnModified(ContagemPassagemTableMap::COL_MODO_ENTRADA)) {
            $criteria->add(ContagemPassagemTableMap::COL_MODO_ENTRADA, $this->modo_entrada);
        }
        if ($this->isColumnModified(ContagemPassagemTableMap::COL_RECEBIDO_EM_MS)) {
            $criteria->add(ContagemPassagemTableMap::COL_RECEBIDO_EM_MS, $this->recebido_em_ms);
        }

        return $criteria;
    }

    /**
     * Builds a Criteria object containing the primary key for this object.
     *
     * Unlike buildCriteria() this method includes the primary key values regardless
     * of whether they have been modified.
     *
     * @throws LogicException if no primary key is defined
     *
     * @return \Propel\Runtime\ActiveQuery\Criteria The Criteria object containing value(s) for primary key(s).
     */
    public function buildPkeyCriteria(): Criteria
    {
        $criteria = ChildContagemPassagemQuery::create();
        $criteria->add(ContagemPassagemTableMap::COL_PASSAGEM_ID, $this->passagem_id);

        return $criteria;
    }

    /**
     * If the primary key is not null, return the hashcode of the
     * primary key. Otherwise, return the hash code of the object.
     *
     * @return int|string Hashcode
     */
    public function hashCode()
    {
        $validPk = null !== $this->getPassagemId();

        $validPrimaryKeyFKs = 0;
        $primaryKeyFKs = [];

        if ($validPk) {
            return crc32(json_encode($this->getPrimaryKey(), JSON_UNESCAPED_UNICODE));
        } elseif ($validPrimaryKeyFKs) {
            return crc32(json_encode($primaryKeyFKs, JSON_UNESCAPED_UNICODE));
        }

        return spl_object_hash($this);
    }

    /**
     * Returns the primary key for this object (row).
     * @return string
     */
    public function getPrimaryKey()
    {
        return $this->getPassagemId();
    }

    /**
     * Generic method to set the primary key (passagem_id column).
     *
     * @param string|null $key Primary key.
     * @return void
     */
    public function setPrimaryKey(?string $key = null): void
    {
        $this->setPassagemId($key);
    }

    /**
     * Returns true if the primary key for this object is null.
     *
     * @return bool
     */
    public function isPrimaryKeyNull(): bool
    {
        return null === $this->getPassagemId();
    }

    /**
     * Sets contents of passed object to values from current object.
     *
     * If desired, this method can also make copies of all associated (fkey referrers)
     * objects.
     *
     * @param object $copyObj An object of \Baja\Model\ContagemPassagem (or compatible) type.
     * @param bool $deepCopy Whether to also copy all rows that refer (by fkey) to the current row.
     * @param bool $makeNew Whether to reset autoincrement PKs and make the object new.
     * @throws \Propel\Runtime\Exception\PropelException
     * @return void
     */
    public function copyInto(object $copyObj, bool $deepCopy = false, bool $makeNew = true): void
    {
        $copyObj->setFonteId($this->getFonteId());
        $copyObj->setDispositivoId($this->getDispositivoId());
        $copyObj->setUuidCliente($this->getUuidCliente());
        $copyObj->setEquipeNumero($this->getEquipeNumero());
        $copyObj->setTsMs($this->getTsMs());
        $copyObj->setQualidadeTempo($this->getQualidadeTempo());
        $copyObj->setTsDispositivoMs($this->getTsDispositivoMs());
        $copyObj->setAncoraServidorMs($this->getAncoraServidorMs());
        $copyObj->setAncoraRttMs($this->getAncoraRttMs());
        $copyObj->setAncoraIdadeMs($this->getAncoraIdadeMs());
        $copyObj->setDigitacaoMs($this->getDigitacaoMs());
        $copyObj->setModoEntrada($this->getModoEntrada());
        $copyObj->setRecebidoEmMs($this->getRecebidoEmMs());
        if ($makeNew) {
            $copyObj->setNew(true);
            $copyObj->setPassagemId(NULL); // this is a auto-increment column, so set to default value
        }
    }

    /**
     * Makes a copy of this object that will be inserted as a new row in table when saved.
     * It creates a new object filling in the simple attributes, but skipping any primary
     * keys that are defined for the table.
     *
     * If desired, this method can also make copies of all associated (fkey referrers)
     * objects.
     *
     * @param bool $deepCopy Whether to also copy all rows that refer (by fkey) to the current row.
     * @return \Baja\Model\ContagemPassagem Clone of current object.
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function copy(bool $deepCopy = false)
    {
        // we use get_class(), because this might be a subclass
        $clazz = get_class($this);
        $copyObj = new $clazz();
        $this->copyInto($copyObj, $deepCopy);

        return $copyObj;
    }

    /**
     * Declares an association between this object and a ChildContagemFonte object.
     *
     * @param ChildContagemFonte $v
     * @return $this The current object (for fluent API support)
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function setContagemFonte(ChildContagemFonte $v = null)
    {
        if ($v === null) {
            $this->setFonteId(NULL);
        } else {
            $this->setFonteId($v->getFonteId());
        }

        $this->aContagemFonte = $v;

        // Add binding for other direction of this n:n relationship.
        // If this object has already been added to the ChildContagemFonte object, it will not be re-added.
        if ($v !== null) {
            $v->addContagemPassagem($this);
        }


        return $this;
    }


    /**
     * Get the associated ChildContagemFonte object
     *
     * @param ConnectionInterface $con Optional Connection object.
     * @return ChildContagemFonte The associated ChildContagemFonte object.
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function getContagemFonte(?ConnectionInterface $con = null)
    {
        if ($this->aContagemFonte === null && ($this->fonte_id != 0)) {
            $this->aContagemFonte = ChildContagemFonteQuery::create()->findPk($this->fonte_id, $con);
            /* The following can be used additionally to
                guarantee the related object contains a reference
                to this object.  This level of coupling may, however, be
                undesirable since it could result in an only partially populated collection
                in the referenced object.
                $this->aContagemFonte->addContagemPassagems($this);
             */
        }

        return $this->aContagemFonte;
    }

    /**
     * Declares an association between this object and a ChildContagemDispositivo object.
     *
     * @param ChildContagemDispositivo $v
     * @return $this The current object (for fluent API support)
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function setContagemDispositivo(ChildContagemDispositivo $v = null)
    {
        if ($v === null) {
            $this->setDispositivoId(NULL);
        } else {
            $this->setDispositivoId($v->getDispositivoId());
        }

        $this->aContagemDispositivo = $v;

        // Add binding for other direction of this n:n relationship.
        // If this object has already been added to the ChildContagemDispositivo object, it will not be re-added.
        if ($v !== null) {
            $v->addContagemPassagem($this);
        }


        return $this;
    }


    /**
     * Get the associated ChildContagemDispositivo object
     *
     * @param ConnectionInterface $con Optional Connection object.
     * @return ChildContagemDispositivo The associated ChildContagemDispositivo object.
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function getContagemDispositivo(?ConnectionInterface $con = null)
    {
        if ($this->aContagemDispositivo === null && ($this->dispositivo_id != 0)) {
            $this->aContagemDispositivo = ChildContagemDispositivoQuery::create()->findPk($this->dispositivo_id, $con);
            /* The following can be used additionally to
                guarantee the related object contains a reference
                to this object.  This level of coupling may, however, be
                undesirable since it could result in an only partially populated collection
                in the referenced object.
                $this->aContagemDispositivo->addContagemPassagems($this);
             */
        }

        return $this->aContagemDispositivo;
    }

    /**
     * Clears the current object, sets all attributes to their default values and removes
     * outgoing references as well as back-references (from other objects to this one. Results probably in a database
     * change of those foreign objects when you call `save` there).
     *
     * @return $this
     */
    public function clear()
    {
        if (null !== $this->aContagemFonte) {
            $this->aContagemFonte->removeContagemPassagem($this);
        }
        if (null !== $this->aContagemDispositivo) {
            $this->aContagemDispositivo->removeContagemPassagem($this);
        }
        $this->passagem_id = null;
        $this->fonte_id = null;
        $this->dispositivo_id = null;
        $this->uuid_cliente = null;
        $this->equipe_numero = null;
        $this->ts_ms = null;
        $this->qualidade_tempo = null;
        $this->ts_dispositivo_ms = null;
        $this->ancora_servidor_ms = null;
        $this->ancora_rtt_ms = null;
        $this->ancora_idade_ms = null;
        $this->digitacao_ms = null;
        $this->modo_entrada = null;
        $this->recebido_em_ms = null;
        $this->alreadyInSave = false;
        $this->clearAllReferences();
        $this->applyDefaultValues();
        $this->resetModified();
        $this->setNew(true);
        $this->setDeleted(false);

        return $this;
    }

    /**
     * Resets all references and back-references to other model objects or collections of model objects.
     *
     * This method is used to reset all php object references (not the actual reference in the database).
     * Necessary for object serialisation.
     *
     * @param bool $deep Whether to also clear the references on all referrer objects.
     * @return $this
     */
    public function clearAllReferences(bool $deep = false)
    {
        if ($deep) {
        } // if ($deep)

        $this->aContagemFonte = null;
        $this->aContagemDispositivo = null;
        return $this;
    }

    /**
     * Return the string representation of this object
     *
     * @return string
     */
    public function __toString()
    {
        return (string) $this->exportTo(ContagemPassagemTableMap::DEFAULT_STRING_FORMAT);
    }

    /**
     * Code to be run before persisting the object
     * @param ConnectionInterface|null $con
     * @return bool
     */
    public function preSave(?ConnectionInterface $con = null): bool
    {
                return true;
    }

    /**
     * Code to be run after persisting the object
     * @param ConnectionInterface|null $con
     * @return void
     */
    public function postSave(?ConnectionInterface $con = null): void
    {
            }

    /**
     * Code to be run before inserting to database
     * @param ConnectionInterface|null $con
     * @return bool
     */
    public function preInsert(?ConnectionInterface $con = null): bool
    {
                return true;
    }

    /**
     * Code to be run after inserting to database
     * @param ConnectionInterface|null $con
     * @return void
     */
    public function postInsert(?ConnectionInterface $con = null): void
    {
            }

    /**
     * Code to be run before updating the object in database
     * @param ConnectionInterface|null $con
     * @return bool
     */
    public function preUpdate(?ConnectionInterface $con = null): bool
    {
                return true;
    }

    /**
     * Code to be run after updating the object in database
     * @param ConnectionInterface|null $con
     * @return void
     */
    public function postUpdate(?ConnectionInterface $con = null): void
    {
            }

    /**
     * Code to be run before deleting the object in database
     * @param ConnectionInterface|null $con
     * @return bool
     */
    public function preDelete(?ConnectionInterface $con = null): bool
    {
                return true;
    }

    /**
     * Code to be run after deleting the object in database
     * @param ConnectionInterface|null $con
     * @return void
     */
    public function postDelete(?ConnectionInterface $con = null): void
    {
            }


    /**
     * Derived method to catches calls to undefined methods.
     *
     * Provides magic import/export method support (fromXML()/toXML(), fromYAML()/toYAML(), etc.).
     * Allows to define default __call() behavior if you overwrite __call()
     *
     * @param string $name
     * @param mixed $params
     *
     * @return array|string
     */
    public function __call($name, $params)
    {
        if (0 === strpos($name, 'get')) {
            $virtualColumn = substr($name, 3);
            if ($this->hasVirtualColumn($virtualColumn)) {
                return $this->getVirtualColumn($virtualColumn);
            }

            $virtualColumn = lcfirst($virtualColumn);
            if ($this->hasVirtualColumn($virtualColumn)) {
                return $this->getVirtualColumn($virtualColumn);
            }
        }

        if (0 === strpos($name, 'from')) {
            $format = substr($name, 4);
            $inputData = $params[0];
            $keyType = $params[1] ?? TableMap::TYPE_PHPNAME;

            return $this->importFrom($format, $inputData, $keyType);
        }

        if (0 === strpos($name, 'to')) {
            $format = substr($name, 2);
            $includeLazyLoadColumns = $params[0] ?? true;
            $keyType = $params[1] ?? TableMap::TYPE_PHPNAME;

            return $this->exportTo($format, $includeLazyLoadColumns, $keyType);
        }

        throw new BadMethodCallException(sprintf('Call to undefined method: %s.', $name));
    }

}
