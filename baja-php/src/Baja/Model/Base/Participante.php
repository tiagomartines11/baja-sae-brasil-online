<?php

namespace Baja\Model\Base;

use \DateTime;
use \Exception;
use \PDO;
use Baja\Model\Evento as ChildEvento;
use Baja\Model\EventoQuery as ChildEventoQuery;
use Baja\Model\ParticipanteQuery as ChildParticipanteQuery;
use Baja\Model\User as ChildUser;
use Baja\Model\UserQuery as ChildUserQuery;
use Baja\Model\Map\ParticipanteTableMap;
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
use Propel\Runtime\Util\PropelDateTime;

/**
 * Base class that represents a row from the 'participantes' table.
 *
 *
 *
 * @package    propel.generator.Baja.Model.Base
 */
abstract class Participante implements ActiveRecordInterface
{
    /**
     * TableMap class name
     *
     * @var string
     */
    public const TABLE_MAP = '\\Baja\\Model\\Map\\ParticipanteTableMap';


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
     * The value for the nome field.
     *
     * @var        string|null
     */
    protected $nome;

    /**
     * The value for the funcao field.
     *
     * @var        string|null
     */
    protected $funcao;

    /**
     * The value for the cpf field.
     *
     * @var        string|null
     */
    protected $cpf;

    /**
     * The value for the documento_estrangeiro field.
     *
     * @var        string|null
     */
    protected $documento_estrangeiro;

    /**
     * The value for the evento field.
     *
     * @var        string
     */
    protected $evento;

    /**
     * The value for the token field.
     *
     * @var        string
     */
    protected $token;

    /**
     * The value for the criado_por field.
     *
     * @var        int|null
     */
    protected $criado_por;

    /**
     * The value for the criado_em field.
     *
     * @var        DateTime|null
     */
    protected $criado_em;

    /**
     * The value for the lote_id field.
     *
     * @var        string|null
     */
    protected $lote_id;

    /**
     * The value for the anulado_em field.
     *
     * @var        DateTime|null
     */
    protected $anulado_em;

    /**
     * The value for the anulado_por field.
     *
     * @var        int|null
     */
    protected $anulado_por;

    /**
     * The value for the anulado_motivo field.
     *
     * @var        string|null
     */
    protected $anulado_motivo;

    /**
     * @var        ChildEvento
     */
    protected $aEvento;

    /**
     * @var        ChildUser
     */
    protected $aUserRelatedByCriadoPor;

    /**
     * @var        ChildUser
     */
    protected $aUserRelatedByAnuladoPor;

    /**
     * Flag to prevent endless save loop, if this object is referenced
     * by another object which falls in this transaction.
     *
     * @var bool
     */
    protected $alreadyInSave = false;

    /**
     * Initializes internal state of Baja\Model\Base\Participante object.
     */
    public function __construct()
    {
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
     * Compares this with another <code>Participante</code> instance.  If
     * <code>obj</code> is an instance of <code>Participante</code>, delegates to
     * <code>equals(Participante)</code>.  Otherwise, returns <code>false</code>.
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
     * Get the [nome] column value.
     *
     * @return string|null
     */
    public function getNome()
    {
        return $this->nome;
    }

    /**
     * Get the [funcao] column value.
     *
     * @return string|null
     */
    public function getFuncao()
    {
        return $this->funcao;
    }

    /**
     * Get the [cpf] column value.
     *
     * @return string|null
     */
    public function getCpf()
    {
        return $this->cpf;
    }

    /**
     * Get the [documento_estrangeiro] column value.
     *
     * @return string|null
     */
    public function getDocumentoEstrangeiro()
    {
        return $this->documento_estrangeiro;
    }

    /**
     * Get the [evento] column value.
     *
     * @return string
     */
    public function getEventoId()
    {
        return $this->evento;
    }

    /**
     * Get the [token] column value.
     *
     * @return string
     */
    public function getToken()
    {
        return $this->token;
    }

    /**
     * Get the [criado_por] column value.
     *
     * @return int|null
     */
    public function getCriadoPor()
    {
        return $this->criado_por;
    }

    /**
     * Get the [optionally formatted] temporal [criado_em] column value.
     *
     *
     * @param string|null $format The date/time format string (either date()-style or strftime()-style).
     *   If format is NULL, then the raw DateTime object will be returned.
     *
     * @return string|DateTime|null Formatted date/time value as string or DateTime object (if format is NULL), NULL if column is NULL, and 0 if column value is 0000-00-00 00:00:00.
     *
     * @throws \Propel\Runtime\Exception\PropelException - if unable to parse/validate the date/time value.
     *
     * @psalm-return ($format is null ? DateTime|null : string|null)
     */
    public function getCriadoEm($format = null)
    {
        if ($format === null) {
            return $this->criado_em;
        } else {
            return $this->criado_em instanceof \DateTimeInterface ? $this->criado_em->format($format) : null;
        }
    }

    /**
     * Get the [lote_id] column value.
     *
     * @return string|null
     */
    public function getLoteId()
    {
        return $this->lote_id;
    }

    /**
     * Get the [optionally formatted] temporal [anulado_em] column value.
     *
     *
     * @param string|null $format The date/time format string (either date()-style or strftime()-style).
     *   If format is NULL, then the raw DateTime object will be returned.
     *
     * @return string|DateTime|null Formatted date/time value as string or DateTime object (if format is NULL), NULL if column is NULL, and 0 if column value is 0000-00-00 00:00:00.
     *
     * @throws \Propel\Runtime\Exception\PropelException - if unable to parse/validate the date/time value.
     *
     * @psalm-return ($format is null ? DateTime|null : string|null)
     */
    public function getAnuladoEm($format = null)
    {
        if ($format === null) {
            return $this->anulado_em;
        } else {
            return $this->anulado_em instanceof \DateTimeInterface ? $this->anulado_em->format($format) : null;
        }
    }

    /**
     * Get the [anulado_por] column value.
     *
     * @return int|null
     */
    public function getAnuladoPor()
    {
        return $this->anulado_por;
    }

    /**
     * Get the [anulado_motivo] column value.
     *
     * @return string|null
     */
    public function getAnuladoMotivo()
    {
        return $this->anulado_motivo;
    }

    /**
     * Set the value of [nome] column.
     *
     * @param string|null $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setNome($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->nome !== $v) {
            $this->nome = $v;
            $this->modifiedColumns[ParticipanteTableMap::COL_NOME] = true;
        }

        return $this;
    }

    /**
     * Set the value of [funcao] column.
     *
     * @param string|null $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setFuncao($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->funcao !== $v) {
            $this->funcao = $v;
            $this->modifiedColumns[ParticipanteTableMap::COL_FUNCAO] = true;
        }

        return $this;
    }

    /**
     * Set the value of [cpf] column.
     *
     * @param string|null $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setCpf($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->cpf !== $v) {
            $this->cpf = $v;
            $this->modifiedColumns[ParticipanteTableMap::COL_CPF] = true;
        }

        return $this;
    }

    /**
     * Set the value of [documento_estrangeiro] column.
     *
     * @param string|null $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setDocumentoEstrangeiro($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->documento_estrangeiro !== $v) {
            $this->documento_estrangeiro = $v;
            $this->modifiedColumns[ParticipanteTableMap::COL_DOCUMENTO_ESTRANGEIRO] = true;
        }

        return $this;
    }

    /**
     * Set the value of [evento] column.
     *
     * @param string $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setEventoId($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->evento !== $v) {
            $this->evento = $v;
            $this->modifiedColumns[ParticipanteTableMap::COL_EVENTO] = true;
        }

        if ($this->aEvento !== null && $this->aEvento->getEventoId() !== $v) {
            $this->aEvento = null;
        }

        return $this;
    }

    /**
     * Set the value of [token] column.
     *
     * @param string $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setToken($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->token !== $v) {
            $this->token = $v;
            $this->modifiedColumns[ParticipanteTableMap::COL_TOKEN] = true;
        }

        return $this;
    }

    /**
     * Set the value of [criado_por] column.
     *
     * @param int|null $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setCriadoPor($v)
    {
        if ($v !== null) {
            $v = (int) $v;
        }

        if ($this->criado_por !== $v) {
            $this->criado_por = $v;
            $this->modifiedColumns[ParticipanteTableMap::COL_CRIADO_POR] = true;
        }

        if ($this->aUserRelatedByCriadoPor !== null && $this->aUserRelatedByCriadoPor->getUserId() !== $v) {
            $this->aUserRelatedByCriadoPor = null;
        }

        return $this;
    }

    /**
     * Sets the value of [criado_em] column to a normalized version of the date/time value specified.
     *
     * @param string|integer|\DateTimeInterface|null $v string, integer (timestamp), or \DateTimeInterface value.
     *               Empty strings are treated as NULL.
     * @return $this The current object (for fluent API support)
     */
    public function setCriadoEm($v)
    {
        $dt = PropelDateTime::newInstance($v, null, 'DateTime');
        if ($this->criado_em !== null || $dt !== null) {
            if ($this->criado_em === null || $dt === null || $dt->format("Y-m-d H:i:s.u") !== $this->criado_em->format("Y-m-d H:i:s.u")) {
                $this->criado_em = $dt === null ? null : clone $dt;
                $this->modifiedColumns[ParticipanteTableMap::COL_CRIADO_EM] = true;
            }
        } // if either are not null

        return $this;
    }

    /**
     * Set the value of [lote_id] column.
     *
     * @param string|null $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setLoteId($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->lote_id !== $v) {
            $this->lote_id = $v;
            $this->modifiedColumns[ParticipanteTableMap::COL_LOTE_ID] = true;
        }

        return $this;
    }

    /**
     * Sets the value of [anulado_em] column to a normalized version of the date/time value specified.
     *
     * @param string|integer|\DateTimeInterface|null $v string, integer (timestamp), or \DateTimeInterface value.
     *               Empty strings are treated as NULL.
     * @return $this The current object (for fluent API support)
     */
    public function setAnuladoEm($v)
    {
        $dt = PropelDateTime::newInstance($v, null, 'DateTime');
        if ($this->anulado_em !== null || $dt !== null) {
            if ($this->anulado_em === null || $dt === null || $dt->format("Y-m-d H:i:s.u") !== $this->anulado_em->format("Y-m-d H:i:s.u")) {
                $this->anulado_em = $dt === null ? null : clone $dt;
                $this->modifiedColumns[ParticipanteTableMap::COL_ANULADO_EM] = true;
            }
        } // if either are not null

        return $this;
    }

    /**
     * Set the value of [anulado_por] column.
     *
     * @param int|null $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setAnuladoPor($v)
    {
        if ($v !== null) {
            $v = (int) $v;
        }

        if ($this->anulado_por !== $v) {
            $this->anulado_por = $v;
            $this->modifiedColumns[ParticipanteTableMap::COL_ANULADO_POR] = true;
        }

        if ($this->aUserRelatedByAnuladoPor !== null && $this->aUserRelatedByAnuladoPor->getUserId() !== $v) {
            $this->aUserRelatedByAnuladoPor = null;
        }

        return $this;
    }

    /**
     * Set the value of [anulado_motivo] column.
     *
     * @param string|null $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setAnuladoMotivo($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->anulado_motivo !== $v) {
            $this->anulado_motivo = $v;
            $this->modifiedColumns[ParticipanteTableMap::COL_ANULADO_MOTIVO] = true;
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

            $col = $row[TableMap::TYPE_NUM == $indexType ? 0 + $startcol : ParticipanteTableMap::translateFieldName('Nome', TableMap::TYPE_PHPNAME, $indexType)];
            $this->nome = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 1 + $startcol : ParticipanteTableMap::translateFieldName('Funcao', TableMap::TYPE_PHPNAME, $indexType)];
            $this->funcao = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 2 + $startcol : ParticipanteTableMap::translateFieldName('Cpf', TableMap::TYPE_PHPNAME, $indexType)];
            $this->cpf = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 3 + $startcol : ParticipanteTableMap::translateFieldName('DocumentoEstrangeiro', TableMap::TYPE_PHPNAME, $indexType)];
            $this->documento_estrangeiro = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 4 + $startcol : ParticipanteTableMap::translateFieldName('EventoId', TableMap::TYPE_PHPNAME, $indexType)];
            $this->evento = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 5 + $startcol : ParticipanteTableMap::translateFieldName('Token', TableMap::TYPE_PHPNAME, $indexType)];
            $this->token = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 6 + $startcol : ParticipanteTableMap::translateFieldName('CriadoPor', TableMap::TYPE_PHPNAME, $indexType)];
            $this->criado_por = (null !== $col) ? (int) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 7 + $startcol : ParticipanteTableMap::translateFieldName('CriadoEm', TableMap::TYPE_PHPNAME, $indexType)];
            if ($col === '0000-00-00 00:00:00') {
                $col = null;
            }
            $this->criado_em = (null !== $col) ? PropelDateTime::newInstance($col, null, 'DateTime') : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 8 + $startcol : ParticipanteTableMap::translateFieldName('LoteId', TableMap::TYPE_PHPNAME, $indexType)];
            $this->lote_id = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 9 + $startcol : ParticipanteTableMap::translateFieldName('AnuladoEm', TableMap::TYPE_PHPNAME, $indexType)];
            if ($col === '0000-00-00 00:00:00') {
                $col = null;
            }
            $this->anulado_em = (null !== $col) ? PropelDateTime::newInstance($col, null, 'DateTime') : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 10 + $startcol : ParticipanteTableMap::translateFieldName('AnuladoPor', TableMap::TYPE_PHPNAME, $indexType)];
            $this->anulado_por = (null !== $col) ? (int) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 11 + $startcol : ParticipanteTableMap::translateFieldName('AnuladoMotivo', TableMap::TYPE_PHPNAME, $indexType)];
            $this->anulado_motivo = (null !== $col) ? (string) $col : null;

            $this->resetModified();
            $this->setNew(false);

            if ($rehydrate) {
                $this->ensureConsistency();
            }

            return $startcol + 12; // 12 = ParticipanteTableMap::NUM_HYDRATE_COLUMNS.

        } catch (Exception $e) {
            throw new PropelException(sprintf('Error populating %s object', '\\Baja\\Model\\Participante'), 0, $e);
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
        if ($this->aEvento !== null && $this->evento !== $this->aEvento->getEventoId()) {
            $this->aEvento = null;
        }
        if ($this->aUserRelatedByCriadoPor !== null && $this->criado_por !== $this->aUserRelatedByCriadoPor->getUserId()) {
            $this->aUserRelatedByCriadoPor = null;
        }
        if ($this->aUserRelatedByAnuladoPor !== null && $this->anulado_por !== $this->aUserRelatedByAnuladoPor->getUserId()) {
            $this->aUserRelatedByAnuladoPor = null;
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
            $con = Propel::getServiceContainer()->getReadConnection(ParticipanteTableMap::DATABASE_NAME);
        }

        // We don't need to alter the object instance pool; we're just modifying this instance
        // already in the pool.

        $dataFetcher = ChildParticipanteQuery::create(null, $this->buildPkeyCriteria())->setFormatter(ModelCriteria::FORMAT_STATEMENT)->find($con);
        $row = $dataFetcher->fetch();
        $dataFetcher->close();
        if (!$row) {
            throw new PropelException('Cannot find matching row in the database to reload object values.');
        }
        $this->hydrate($row, 0, true, $dataFetcher->getIndexType()); // rehydrate

        if ($deep) {  // also de-associate any related objects?

            $this->aEvento = null;
            $this->aUserRelatedByCriadoPor = null;
            $this->aUserRelatedByAnuladoPor = null;
        } // if (deep)
    }

    /**
     * Removes this object from datastore and sets delete attribute.
     *
     * @param ConnectionInterface $con
     * @return void
     * @throws \Propel\Runtime\Exception\PropelException
     * @see Participante::setDeleted()
     * @see Participante::isDeleted()
     */
    public function delete(?ConnectionInterface $con = null): void
    {
        if ($this->isDeleted()) {
            throw new PropelException("This object has already been deleted.");
        }

        if ($con === null) {
            $con = Propel::getServiceContainer()->getWriteConnection(ParticipanteTableMap::DATABASE_NAME);
        }

        $con->transaction(function () use ($con) {
            $deleteQuery = ChildParticipanteQuery::create()
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
            $con = Propel::getServiceContainer()->getWriteConnection(ParticipanteTableMap::DATABASE_NAME);
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
                ParticipanteTableMap::addInstanceToPool($this);
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

            if ($this->aEvento !== null) {
                if ($this->aEvento->isModified() || $this->aEvento->isNew()) {
                    $affectedRows += $this->aEvento->save($con);
                }
                $this->setEvento($this->aEvento);
            }

            if ($this->aUserRelatedByCriadoPor !== null) {
                if ($this->aUserRelatedByCriadoPor->isModified() || $this->aUserRelatedByCriadoPor->isNew()) {
                    $affectedRows += $this->aUserRelatedByCriadoPor->save($con);
                }
                $this->setUserRelatedByCriadoPor($this->aUserRelatedByCriadoPor);
            }

            if ($this->aUserRelatedByAnuladoPor !== null) {
                if ($this->aUserRelatedByAnuladoPor->isModified() || $this->aUserRelatedByAnuladoPor->isNew()) {
                    $affectedRows += $this->aUserRelatedByAnuladoPor->save($con);
                }
                $this->setUserRelatedByAnuladoPor($this->aUserRelatedByAnuladoPor);
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


         // check the columns in natural order for more readable SQL queries
        if ($this->isColumnModified(ParticipanteTableMap::COL_NOME)) {
            $modifiedColumns[':p' . $index++]  = 'nome';
        }
        if ($this->isColumnModified(ParticipanteTableMap::COL_FUNCAO)) {
            $modifiedColumns[':p' . $index++]  = 'funcao';
        }
        if ($this->isColumnModified(ParticipanteTableMap::COL_CPF)) {
            $modifiedColumns[':p' . $index++]  = 'cpf';
        }
        if ($this->isColumnModified(ParticipanteTableMap::COL_DOCUMENTO_ESTRANGEIRO)) {
            $modifiedColumns[':p' . $index++]  = 'documento_estrangeiro';
        }
        if ($this->isColumnModified(ParticipanteTableMap::COL_EVENTO)) {
            $modifiedColumns[':p' . $index++]  = 'evento';
        }
        if ($this->isColumnModified(ParticipanteTableMap::COL_TOKEN)) {
            $modifiedColumns[':p' . $index++]  = 'token';
        }
        if ($this->isColumnModified(ParticipanteTableMap::COL_CRIADO_POR)) {
            $modifiedColumns[':p' . $index++]  = 'criado_por';
        }
        if ($this->isColumnModified(ParticipanteTableMap::COL_CRIADO_EM)) {
            $modifiedColumns[':p' . $index++]  = 'criado_em';
        }
        if ($this->isColumnModified(ParticipanteTableMap::COL_LOTE_ID)) {
            $modifiedColumns[':p' . $index++]  = 'lote_id';
        }
        if ($this->isColumnModified(ParticipanteTableMap::COL_ANULADO_EM)) {
            $modifiedColumns[':p' . $index++]  = 'anulado_em';
        }
        if ($this->isColumnModified(ParticipanteTableMap::COL_ANULADO_POR)) {
            $modifiedColumns[':p' . $index++]  = 'anulado_por';
        }
        if ($this->isColumnModified(ParticipanteTableMap::COL_ANULADO_MOTIVO)) {
            $modifiedColumns[':p' . $index++]  = 'anulado_motivo';
        }

        $sql = sprintf(
            'INSERT INTO participantes (%s) VALUES (%s)',
            implode(', ', $modifiedColumns),
            implode(', ', array_keys($modifiedColumns))
        );

        try {
            $stmt = $con->prepare($sql);
            foreach ($modifiedColumns as $identifier => $columnName) {
                switch ($columnName) {
                    case 'nome':
                        $stmt->bindValue($identifier, $this->nome, PDO::PARAM_STR);

                        break;
                    case 'funcao':
                        $stmt->bindValue($identifier, $this->funcao, PDO::PARAM_STR);

                        break;
                    case 'cpf':
                        $stmt->bindValue($identifier, $this->cpf, PDO::PARAM_STR);

                        break;
                    case 'documento_estrangeiro':
                        $stmt->bindValue($identifier, $this->documento_estrangeiro, PDO::PARAM_STR);

                        break;
                    case 'evento':
                        $stmt->bindValue($identifier, $this->evento, PDO::PARAM_STR);

                        break;
                    case 'token':
                        $stmt->bindValue($identifier, $this->token, PDO::PARAM_STR);

                        break;
                    case 'criado_por':
                        $stmt->bindValue($identifier, $this->criado_por, PDO::PARAM_INT);

                        break;
                    case 'criado_em':
                        $stmt->bindValue($identifier, $this->criado_em ? $this->criado_em->format("Y-m-d H:i:s.u") : null, PDO::PARAM_STR);

                        break;
                    case 'lote_id':
                        $stmt->bindValue($identifier, $this->lote_id, PDO::PARAM_STR);

                        break;
                    case 'anulado_em':
                        $stmt->bindValue($identifier, $this->anulado_em ? $this->anulado_em->format("Y-m-d H:i:s.u") : null, PDO::PARAM_STR);

                        break;
                    case 'anulado_por':
                        $stmt->bindValue($identifier, $this->anulado_por, PDO::PARAM_INT);

                        break;
                    case 'anulado_motivo':
                        $stmt->bindValue($identifier, $this->anulado_motivo, PDO::PARAM_STR);

                        break;
                }
            }
            $stmt->execute();
        } catch (Exception $e) {
            Propel::log($e->getMessage(), Propel::LOG_ERR);
            throw new PropelException(sprintf('Unable to execute INSERT statement [%s]', $sql), 0, $e);
        }

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
        $pos = ParticipanteTableMap::translateFieldName($name, $type, TableMap::TYPE_NUM);
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
                return $this->getNome();

            case 1:
                return $this->getFuncao();

            case 2:
                return $this->getCpf();

            case 3:
                return $this->getDocumentoEstrangeiro();

            case 4:
                return $this->getEventoId();

            case 5:
                return $this->getToken();

            case 6:
                return $this->getCriadoPor();

            case 7:
                return $this->getCriadoEm();

            case 8:
                return $this->getLoteId();

            case 9:
                return $this->getAnuladoEm();

            case 10:
                return $this->getAnuladoPor();

            case 11:
                return $this->getAnuladoMotivo();

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
        if (isset($alreadyDumpedObjects['Participante'][$this->hashCode()])) {
            return ['*RECURSION*'];
        }
        $alreadyDumpedObjects['Participante'][$this->hashCode()] = true;
        $keys = ParticipanteTableMap::getFieldNames($keyType);
        $result = [
            $keys[0] => $this->getNome(),
            $keys[1] => $this->getFuncao(),
            $keys[2] => $this->getCpf(),
            $keys[3] => $this->getDocumentoEstrangeiro(),
            $keys[4] => $this->getEventoId(),
            $keys[5] => $this->getToken(),
            $keys[6] => $this->getCriadoPor(),
            $keys[7] => $this->getCriadoEm(),
            $keys[8] => $this->getLoteId(),
            $keys[9] => $this->getAnuladoEm(),
            $keys[10] => $this->getAnuladoPor(),
            $keys[11] => $this->getAnuladoMotivo(),
        ];
        if ($result[$keys[7]] instanceof \DateTimeInterface) {
            $result[$keys[7]] = $result[$keys[7]]->format('Y-m-d H:i:s.u');
        }

        if ($result[$keys[9]] instanceof \DateTimeInterface) {
            $result[$keys[9]] = $result[$keys[9]]->format('Y-m-d H:i:s.u');
        }

        $virtualColumns = $this->virtualColumns;
        foreach ($virtualColumns as $key => $virtualColumn) {
            $result[$key] = $virtualColumn;
        }

        if ($includeForeignObjects) {
            if (null !== $this->aEvento) {

                switch ($keyType) {
                    case TableMap::TYPE_CAMELNAME:
                        $key = 'evento';
                        break;
                    case TableMap::TYPE_FIELDNAME:
                        $key = 'evento';
                        break;
                    default:
                        $key = 'Evento';
                }

                $result[$key] = $this->aEvento->toArray($keyType, $includeLazyLoadColumns,  $alreadyDumpedObjects, true);
            }
            if (null !== $this->aUserRelatedByCriadoPor) {

                switch ($keyType) {
                    case TableMap::TYPE_CAMELNAME:
                        $key = 'user';
                        break;
                    case TableMap::TYPE_FIELDNAME:
                        $key = 'user';
                        break;
                    default:
                        $key = 'User';
                }

                $result[$key] = $this->aUserRelatedByCriadoPor->toArray($keyType, $includeLazyLoadColumns,  $alreadyDumpedObjects, true);
            }
            if (null !== $this->aUserRelatedByAnuladoPor) {

                switch ($keyType) {
                    case TableMap::TYPE_CAMELNAME:
                        $key = 'user';
                        break;
                    case TableMap::TYPE_FIELDNAME:
                        $key = 'user';
                        break;
                    default:
                        $key = 'User';
                }

                $result[$key] = $this->aUserRelatedByAnuladoPor->toArray($keyType, $includeLazyLoadColumns,  $alreadyDumpedObjects, true);
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
        $pos = ParticipanteTableMap::translateFieldName($name, $type, TableMap::TYPE_NUM);

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
                $this->setNome($value);
                break;
            case 1:
                $this->setFuncao($value);
                break;
            case 2:
                $this->setCpf($value);
                break;
            case 3:
                $this->setDocumentoEstrangeiro($value);
                break;
            case 4:
                $this->setEventoId($value);
                break;
            case 5:
                $this->setToken($value);
                break;
            case 6:
                $this->setCriadoPor($value);
                break;
            case 7:
                $this->setCriadoEm($value);
                break;
            case 8:
                $this->setLoteId($value);
                break;
            case 9:
                $this->setAnuladoEm($value);
                break;
            case 10:
                $this->setAnuladoPor($value);
                break;
            case 11:
                $this->setAnuladoMotivo($value);
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
        $keys = ParticipanteTableMap::getFieldNames($keyType);

        if (array_key_exists($keys[0], $arr)) {
            $this->setNome($arr[$keys[0]]);
        }
        if (array_key_exists($keys[1], $arr)) {
            $this->setFuncao($arr[$keys[1]]);
        }
        if (array_key_exists($keys[2], $arr)) {
            $this->setCpf($arr[$keys[2]]);
        }
        if (array_key_exists($keys[3], $arr)) {
            $this->setDocumentoEstrangeiro($arr[$keys[3]]);
        }
        if (array_key_exists($keys[4], $arr)) {
            $this->setEventoId($arr[$keys[4]]);
        }
        if (array_key_exists($keys[5], $arr)) {
            $this->setToken($arr[$keys[5]]);
        }
        if (array_key_exists($keys[6], $arr)) {
            $this->setCriadoPor($arr[$keys[6]]);
        }
        if (array_key_exists($keys[7], $arr)) {
            $this->setCriadoEm($arr[$keys[7]]);
        }
        if (array_key_exists($keys[8], $arr)) {
            $this->setLoteId($arr[$keys[8]]);
        }
        if (array_key_exists($keys[9], $arr)) {
            $this->setAnuladoEm($arr[$keys[9]]);
        }
        if (array_key_exists($keys[10], $arr)) {
            $this->setAnuladoPor($arr[$keys[10]]);
        }
        if (array_key_exists($keys[11], $arr)) {
            $this->setAnuladoMotivo($arr[$keys[11]]);
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
        $criteria = new Criteria(ParticipanteTableMap::DATABASE_NAME);

        if ($this->isColumnModified(ParticipanteTableMap::COL_NOME)) {
            $criteria->add(ParticipanteTableMap::COL_NOME, $this->nome);
        }
        if ($this->isColumnModified(ParticipanteTableMap::COL_FUNCAO)) {
            $criteria->add(ParticipanteTableMap::COL_FUNCAO, $this->funcao);
        }
        if ($this->isColumnModified(ParticipanteTableMap::COL_CPF)) {
            $criteria->add(ParticipanteTableMap::COL_CPF, $this->cpf);
        }
        if ($this->isColumnModified(ParticipanteTableMap::COL_DOCUMENTO_ESTRANGEIRO)) {
            $criteria->add(ParticipanteTableMap::COL_DOCUMENTO_ESTRANGEIRO, $this->documento_estrangeiro);
        }
        if ($this->isColumnModified(ParticipanteTableMap::COL_EVENTO)) {
            $criteria->add(ParticipanteTableMap::COL_EVENTO, $this->evento);
        }
        if ($this->isColumnModified(ParticipanteTableMap::COL_TOKEN)) {
            $criteria->add(ParticipanteTableMap::COL_TOKEN, $this->token);
        }
        if ($this->isColumnModified(ParticipanteTableMap::COL_CRIADO_POR)) {
            $criteria->add(ParticipanteTableMap::COL_CRIADO_POR, $this->criado_por);
        }
        if ($this->isColumnModified(ParticipanteTableMap::COL_CRIADO_EM)) {
            $criteria->add(ParticipanteTableMap::COL_CRIADO_EM, $this->criado_em);
        }
        if ($this->isColumnModified(ParticipanteTableMap::COL_LOTE_ID)) {
            $criteria->add(ParticipanteTableMap::COL_LOTE_ID, $this->lote_id);
        }
        if ($this->isColumnModified(ParticipanteTableMap::COL_ANULADO_EM)) {
            $criteria->add(ParticipanteTableMap::COL_ANULADO_EM, $this->anulado_em);
        }
        if ($this->isColumnModified(ParticipanteTableMap::COL_ANULADO_POR)) {
            $criteria->add(ParticipanteTableMap::COL_ANULADO_POR, $this->anulado_por);
        }
        if ($this->isColumnModified(ParticipanteTableMap::COL_ANULADO_MOTIVO)) {
            $criteria->add(ParticipanteTableMap::COL_ANULADO_MOTIVO, $this->anulado_motivo);
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
        $criteria = ChildParticipanteQuery::create();
        $criteria->add(ParticipanteTableMap::COL_TOKEN, $this->token);

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
        $validPk = null !== $this->getToken();

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
        return $this->getToken();
    }

    /**
     * Generic method to set the primary key (token column).
     *
     * @param string|null $key Primary key.
     * @return void
     */
    public function setPrimaryKey(?string $key = null): void
    {
        $this->setToken($key);
    }

    /**
     * Returns true if the primary key for this object is null.
     *
     * @return bool
     */
    public function isPrimaryKeyNull(): bool
    {
        return null === $this->getToken();
    }

    /**
     * Sets contents of passed object to values from current object.
     *
     * If desired, this method can also make copies of all associated (fkey referrers)
     * objects.
     *
     * @param object $copyObj An object of \Baja\Model\Participante (or compatible) type.
     * @param bool $deepCopy Whether to also copy all rows that refer (by fkey) to the current row.
     * @param bool $makeNew Whether to reset autoincrement PKs and make the object new.
     * @throws \Propel\Runtime\Exception\PropelException
     * @return void
     */
    public function copyInto(object $copyObj, bool $deepCopy = false, bool $makeNew = true): void
    {
        $copyObj->setNome($this->getNome());
        $copyObj->setFuncao($this->getFuncao());
        $copyObj->setCpf($this->getCpf());
        $copyObj->setDocumentoEstrangeiro($this->getDocumentoEstrangeiro());
        $copyObj->setEventoId($this->getEventoId());
        $copyObj->setToken($this->getToken());
        $copyObj->setCriadoPor($this->getCriadoPor());
        $copyObj->setCriadoEm($this->getCriadoEm());
        $copyObj->setLoteId($this->getLoteId());
        $copyObj->setAnuladoEm($this->getAnuladoEm());
        $copyObj->setAnuladoPor($this->getAnuladoPor());
        $copyObj->setAnuladoMotivo($this->getAnuladoMotivo());
        if ($makeNew) {
            $copyObj->setNew(true);
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
     * @return \Baja\Model\Participante Clone of current object.
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
     * Declares an association between this object and a ChildEvento object.
     *
     * @param ChildEvento $v
     * @return $this The current object (for fluent API support)
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function setEvento(ChildEvento $v = null)
    {
        if ($v === null) {
            $this->setEventoId(NULL);
        } else {
            $this->setEventoId($v->getEventoId());
        }

        $this->aEvento = $v;

        // Add binding for other direction of this n:n relationship.
        // If this object has already been added to the ChildEvento object, it will not be re-added.
        if ($v !== null) {
            $v->addParticipante($this);
        }


        return $this;
    }


    /**
     * Get the associated ChildEvento object
     *
     * @param ConnectionInterface $con Optional Connection object.
     * @return ChildEvento The associated ChildEvento object.
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function getEvento(?ConnectionInterface $con = null)
    {
        if ($this->aEvento === null && (($this->evento !== "" && $this->evento !== null))) {
            $this->aEvento = ChildEventoQuery::create()->findPk($this->evento, $con);
            /* The following can be used additionally to
                guarantee the related object contains a reference
                to this object.  This level of coupling may, however, be
                undesirable since it could result in an only partially populated collection
                in the referenced object.
                $this->aEvento->addParticipantes($this);
             */
        }

        return $this->aEvento;
    }

    /**
     * Declares an association between this object and a ChildUser object.
     *
     * @param ChildUser|null $v
     * @return $this The current object (for fluent API support)
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function setUserRelatedByCriadoPor(ChildUser $v = null)
    {
        if ($v === null) {
            $this->setCriadoPor(NULL);
        } else {
            $this->setCriadoPor($v->getUserId());
        }

        $this->aUserRelatedByCriadoPor = $v;

        // Add binding for other direction of this n:n relationship.
        // If this object has already been added to the ChildUser object, it will not be re-added.
        if ($v !== null) {
            $v->addParticipanteRelatedByCriadoPor($this);
        }


        return $this;
    }


    /**
     * Get the associated ChildUser object
     *
     * @param ConnectionInterface $con Optional Connection object.
     * @return ChildUser|null The associated ChildUser object.
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function getUserRelatedByCriadoPor(?ConnectionInterface $con = null)
    {
        if ($this->aUserRelatedByCriadoPor === null && ($this->criado_por != 0)) {
            $this->aUserRelatedByCriadoPor = ChildUserQuery::create()->findPk($this->criado_por, $con);
            /* The following can be used additionally to
                guarantee the related object contains a reference
                to this object.  This level of coupling may, however, be
                undesirable since it could result in an only partially populated collection
                in the referenced object.
                $this->aUserRelatedByCriadoPor->addParticipantesRelatedByCriadoPor($this);
             */
        }

        return $this->aUserRelatedByCriadoPor;
    }

    /**
     * Declares an association between this object and a ChildUser object.
     *
     * @param ChildUser|null $v
     * @return $this The current object (for fluent API support)
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function setUserRelatedByAnuladoPor(ChildUser $v = null)
    {
        if ($v === null) {
            $this->setAnuladoPor(NULL);
        } else {
            $this->setAnuladoPor($v->getUserId());
        }

        $this->aUserRelatedByAnuladoPor = $v;

        // Add binding for other direction of this n:n relationship.
        // If this object has already been added to the ChildUser object, it will not be re-added.
        if ($v !== null) {
            $v->addParticipanteRelatedByAnuladoPor($this);
        }


        return $this;
    }


    /**
     * Get the associated ChildUser object
     *
     * @param ConnectionInterface $con Optional Connection object.
     * @return ChildUser|null The associated ChildUser object.
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function getUserRelatedByAnuladoPor(?ConnectionInterface $con = null)
    {
        if ($this->aUserRelatedByAnuladoPor === null && ($this->anulado_por != 0)) {
            $this->aUserRelatedByAnuladoPor = ChildUserQuery::create()->findPk($this->anulado_por, $con);
            /* The following can be used additionally to
                guarantee the related object contains a reference
                to this object.  This level of coupling may, however, be
                undesirable since it could result in an only partially populated collection
                in the referenced object.
                $this->aUserRelatedByAnuladoPor->addParticipantesRelatedByAnuladoPor($this);
             */
        }

        return $this->aUserRelatedByAnuladoPor;
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
        if (null !== $this->aEvento) {
            $this->aEvento->removeParticipante($this);
        }
        if (null !== $this->aUserRelatedByCriadoPor) {
            $this->aUserRelatedByCriadoPor->removeParticipanteRelatedByCriadoPor($this);
        }
        if (null !== $this->aUserRelatedByAnuladoPor) {
            $this->aUserRelatedByAnuladoPor->removeParticipanteRelatedByAnuladoPor($this);
        }
        $this->nome = null;
        $this->funcao = null;
        $this->cpf = null;
        $this->documento_estrangeiro = null;
        $this->evento = null;
        $this->token = null;
        $this->criado_por = null;
        $this->criado_em = null;
        $this->lote_id = null;
        $this->anulado_em = null;
        $this->anulado_por = null;
        $this->anulado_motivo = null;
        $this->alreadyInSave = false;
        $this->clearAllReferences();
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

        $this->aEvento = null;
        $this->aUserRelatedByCriadoPor = null;
        $this->aUserRelatedByAnuladoPor = null;
        return $this;
    }

    /**
     * Return the string representation of this object
     *
     * @return string
     */
    public function __toString()
    {
        return (string) $this->exportTo(ParticipanteTableMap::DEFAULT_STRING_FORMAT);
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
