<?php

namespace Baja\Model\Base;

use \DateTime;
use \Exception;
use \PDO;
use Baja\Model\CertificadoRequerimentoQuery as ChildCertificadoRequerimentoQuery;
use Baja\Model\User as ChildUser;
use Baja\Model\UserQuery as ChildUserQuery;
use Baja\Model\Map\CertificadoRequerimentoTableMap;
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
 * Base class that represents a row from the 'certificado_requerimento' table.
 *
 *
 *
 * @package    propel.generator.Baja.Model.Base
 */
abstract class CertificadoRequerimento implements ActiveRecordInterface
{
    /**
     * TableMap class name
     *
     * @var string
     */
    public const TABLE_MAP = '\\Baja\\Model\\Map\\CertificadoRequerimentoTableMap';


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
     * The value for the requerimento_id field.
     *
     * @var        string
     */
    protected $requerimento_id;

    /**
     * The value for the caso field.
     *
     * @var        int
     */
    protected $caso;

    /**
     * The value for the token field.
     *
     * @var        string|null
     */
    protected $token;

    /**
     * The value for the evento field.
     *
     * @var        string|null
     */
    protected $evento;

    /**
     * The value for the evento_texto field.
     *
     * @var        string|null
     */
    protected $evento_texto;

    /**
     * The value for the documento field.
     *
     * @var        string
     */
    protected $documento;

    /**
     * The value for the nome field.
     *
     * @var        string
     */
    protected $nome;

    /**
     * The value for the email field.
     *
     * @var        string
     */
    protected $email;

    /**
     * The value for the telefone field.
     *
     * @var        string|null
     */
    protected $telefone;

    /**
     * The value for the descricao field.
     *
     * @var        string
     */
    protected $descricao;

    /**
     * The value for the criado_em field.
     *
     * @var        DateTime
     */
    protected $criado_em;

    /**
     * The value for the avisado_em field.
     *
     * @var        DateTime|null
     */
    protected $avisado_em;

    /**
     * The value for the status field.
     *
     * Note: this column has a database default value of: 0
     * @var        int
     */
    protected $status;

    /**
     * The value for the resolvido_por field.
     *
     * @var        int|null
     */
    protected $resolvido_por;

    /**
     * The value for the resolvido_em field.
     *
     * @var        DateTime|null
     */
    protected $resolvido_em;

    /**
     * The value for the resolucao field.
     *
     * @var        string|null
     */
    protected $resolucao;

    /**
     * @var        ChildUser
     */
    protected $aUser;

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
        $this->status = 0;
    }

    /**
     * Initializes internal state of Baja\Model\Base\CertificadoRequerimento object.
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
     * Compares this with another <code>CertificadoRequerimento</code> instance.  If
     * <code>obj</code> is an instance of <code>CertificadoRequerimento</code>, delegates to
     * <code>equals(CertificadoRequerimento)</code>.  Otherwise, returns <code>false</code>.
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
     * Get the [requerimento_id] column value.
     *
     * @return string
     */
    public function getRequerimentoId()
    {
        return $this->requerimento_id;
    }

    /**
     * Get the [caso] column value.
     *
     * @return string|null
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function getCaso()
    {
        if (null === $this->caso) {
            return null;
        }
        $valueSet = CertificadoRequerimentoTableMap::getValueSet(CertificadoRequerimentoTableMap::COL_CASO);
        if (!isset($valueSet[$this->caso])) {
            throw new PropelException('Unknown stored enum key: ' . $this->caso);
        }

        return $valueSet[$this->caso];
    }

    /**
     * Get the [token] column value.
     *
     * @return string|null
     */
    public function getToken()
    {
        return $this->token;
    }

    /**
     * Get the [evento] column value.
     *
     * @return string|null
     */
    public function getEventoId()
    {
        return $this->evento;
    }

    /**
     * Get the [evento_texto] column value.
     *
     * @return string|null
     */
    public function getEventoTexto()
    {
        return $this->evento_texto;
    }

    /**
     * Get the [documento] column value.
     *
     * @return string
     */
    public function getDocumento()
    {
        return $this->documento;
    }

    /**
     * Get the [nome] column value.
     *
     * @return string
     */
    public function getNome()
    {
        return $this->nome;
    }

    /**
     * Get the [email] column value.
     *
     * @return string
     */
    public function getEmail()
    {
        return $this->email;
    }

    /**
     * Get the [telefone] column value.
     *
     * @return string|null
     */
    public function getTelefone()
    {
        return $this->telefone;
    }

    /**
     * Get the [descricao] column value.
     *
     * @return string
     */
    public function getDescricao()
    {
        return $this->descricao;
    }

    /**
     * Get the [optionally formatted] temporal [criado_em] column value.
     *
     *
     * @param string|null $format The date/time format string (either date()-style or strftime()-style).
     *   If format is NULL, then the raw DateTime object will be returned.
     *
     * @return string|DateTime Formatted date/time value as string or DateTime object (if format is NULL), and 0 if column value is 0000-00-00 00:00:00.
     *
     * @throws \Propel\Runtime\Exception\PropelException - if unable to parse/validate the date/time value.
     *
     * @psalm-return ($format is null ? DateTime : string)
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
     * Get the [optionally formatted] temporal [avisado_em] column value.
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
    public function getAvisadoEm($format = null)
    {
        if ($format === null) {
            return $this->avisado_em;
        } else {
            return $this->avisado_em instanceof \DateTimeInterface ? $this->avisado_em->format($format) : null;
        }
    }

    /**
     * Get the [status] column value.
     *
     * @return string|null
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function getStatus()
    {
        if (null === $this->status) {
            return null;
        }
        $valueSet = CertificadoRequerimentoTableMap::getValueSet(CertificadoRequerimentoTableMap::COL_STATUS);
        if (!isset($valueSet[$this->status])) {
            throw new PropelException('Unknown stored enum key: ' . $this->status);
        }

        return $valueSet[$this->status];
    }

    /**
     * Get the [resolvido_por] column value.
     *
     * @return int|null
     */
    public function getResolvidoPor()
    {
        return $this->resolvido_por;
    }

    /**
     * Get the [optionally formatted] temporal [resolvido_em] column value.
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
    public function getResolvidoEm($format = null)
    {
        if ($format === null) {
            return $this->resolvido_em;
        } else {
            return $this->resolvido_em instanceof \DateTimeInterface ? $this->resolvido_em->format($format) : null;
        }
    }

    /**
     * Get the [resolucao] column value.
     *
     * @return string|null
     */
    public function getResolucao()
    {
        return $this->resolucao;
    }

    /**
     * Set the value of [requerimento_id] column.
     *
     * @param string $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setRequerimentoId($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->requerimento_id !== $v) {
            $this->requerimento_id = $v;
            $this->modifiedColumns[CertificadoRequerimentoTableMap::COL_REQUERIMENTO_ID] = true;
        }

        return $this;
    }

    /**
     * Set the value of [caso] column.
     *
     * @param string $v new value
     * @return $this The current object (for fluent API support)
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function setCaso($v)
    {
        if ($v !== null) {
            $valueSet = CertificadoRequerimentoTableMap::getValueSet(CertificadoRequerimentoTableMap::COL_CASO);
            if (!in_array($v, $valueSet)) {
                throw new PropelException(sprintf('Value "%s" is not accepted in this enumerated column', $v));
            }
            $v = array_search($v, $valueSet);
        }

        if ($this->caso !== $v) {
            $this->caso = $v;
            $this->modifiedColumns[CertificadoRequerimentoTableMap::COL_CASO] = true;
        }

        return $this;
    }

    /**
     * Set the value of [token] column.
     *
     * @param string|null $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setToken($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->token !== $v) {
            $this->token = $v;
            $this->modifiedColumns[CertificadoRequerimentoTableMap::COL_TOKEN] = true;
        }

        return $this;
    }

    /**
     * Set the value of [evento] column.
     *
     * @param string|null $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setEventoId($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->evento !== $v) {
            $this->evento = $v;
            $this->modifiedColumns[CertificadoRequerimentoTableMap::COL_EVENTO] = true;
        }

        return $this;
    }

    /**
     * Set the value of [evento_texto] column.
     *
     * @param string|null $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setEventoTexto($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->evento_texto !== $v) {
            $this->evento_texto = $v;
            $this->modifiedColumns[CertificadoRequerimentoTableMap::COL_EVENTO_TEXTO] = true;
        }

        return $this;
    }

    /**
     * Set the value of [documento] column.
     *
     * @param string $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setDocumento($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->documento !== $v) {
            $this->documento = $v;
            $this->modifiedColumns[CertificadoRequerimentoTableMap::COL_DOCUMENTO] = true;
        }

        return $this;
    }

    /**
     * Set the value of [nome] column.
     *
     * @param string $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setNome($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->nome !== $v) {
            $this->nome = $v;
            $this->modifiedColumns[CertificadoRequerimentoTableMap::COL_NOME] = true;
        }

        return $this;
    }

    /**
     * Set the value of [email] column.
     *
     * @param string $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setEmail($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->email !== $v) {
            $this->email = $v;
            $this->modifiedColumns[CertificadoRequerimentoTableMap::COL_EMAIL] = true;
        }

        return $this;
    }

    /**
     * Set the value of [telefone] column.
     *
     * @param string|null $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setTelefone($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->telefone !== $v) {
            $this->telefone = $v;
            $this->modifiedColumns[CertificadoRequerimentoTableMap::COL_TELEFONE] = true;
        }

        return $this;
    }

    /**
     * Set the value of [descricao] column.
     *
     * @param string $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setDescricao($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->descricao !== $v) {
            $this->descricao = $v;
            $this->modifiedColumns[CertificadoRequerimentoTableMap::COL_DESCRICAO] = true;
        }

        return $this;
    }

    /**
     * Sets the value of [criado_em] column to a normalized version of the date/time value specified.
     *
     * @param string|integer|\DateTimeInterface $v string, integer (timestamp), or \DateTimeInterface value.
     *               Empty strings are treated as NULL.
     * @return $this The current object (for fluent API support)
     */
    public function setCriadoEm($v)
    {
        $dt = PropelDateTime::newInstance($v, null, 'DateTime');
        if ($this->criado_em !== null || $dt !== null) {
            if ($this->criado_em === null || $dt === null || $dt->format("Y-m-d H:i:s.u") !== $this->criado_em->format("Y-m-d H:i:s.u")) {
                $this->criado_em = $dt === null ? null : clone $dt;
                $this->modifiedColumns[CertificadoRequerimentoTableMap::COL_CRIADO_EM] = true;
            }
        } // if either are not null

        return $this;
    }

    /**
     * Sets the value of [avisado_em] column to a normalized version of the date/time value specified.
     *
     * @param string|integer|\DateTimeInterface|null $v string, integer (timestamp), or \DateTimeInterface value.
     *               Empty strings are treated as NULL.
     * @return $this The current object (for fluent API support)
     */
    public function setAvisadoEm($v)
    {
        $dt = PropelDateTime::newInstance($v, null, 'DateTime');
        if ($this->avisado_em !== null || $dt !== null) {
            if ($this->avisado_em === null || $dt === null || $dt->format("Y-m-d H:i:s.u") !== $this->avisado_em->format("Y-m-d H:i:s.u")) {
                $this->avisado_em = $dt === null ? null : clone $dt;
                $this->modifiedColumns[CertificadoRequerimentoTableMap::COL_AVISADO_EM] = true;
            }
        } // if either are not null

        return $this;
    }

    /**
     * Set the value of [status] column.
     *
     * @param string $v new value
     * @return $this The current object (for fluent API support)
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function setStatus($v)
    {
        if ($v !== null) {
            $valueSet = CertificadoRequerimentoTableMap::getValueSet(CertificadoRequerimentoTableMap::COL_STATUS);
            if (!in_array($v, $valueSet)) {
                throw new PropelException(sprintf('Value "%s" is not accepted in this enumerated column', $v));
            }
            $v = array_search($v, $valueSet);
        }

        if ($this->status !== $v) {
            $this->status = $v;
            $this->modifiedColumns[CertificadoRequerimentoTableMap::COL_STATUS] = true;
        }

        return $this;
    }

    /**
     * Set the value of [resolvido_por] column.
     *
     * @param int|null $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setResolvidoPor($v)
    {
        if ($v !== null) {
            $v = (int) $v;
        }

        if ($this->resolvido_por !== $v) {
            $this->resolvido_por = $v;
            $this->modifiedColumns[CertificadoRequerimentoTableMap::COL_RESOLVIDO_POR] = true;
        }

        if ($this->aUser !== null && $this->aUser->getUserId() !== $v) {
            $this->aUser = null;
        }

        return $this;
    }

    /**
     * Sets the value of [resolvido_em] column to a normalized version of the date/time value specified.
     *
     * @param string|integer|\DateTimeInterface|null $v string, integer (timestamp), or \DateTimeInterface value.
     *               Empty strings are treated as NULL.
     * @return $this The current object (for fluent API support)
     */
    public function setResolvidoEm($v)
    {
        $dt = PropelDateTime::newInstance($v, null, 'DateTime');
        if ($this->resolvido_em !== null || $dt !== null) {
            if ($this->resolvido_em === null || $dt === null || $dt->format("Y-m-d H:i:s.u") !== $this->resolvido_em->format("Y-m-d H:i:s.u")) {
                $this->resolvido_em = $dt === null ? null : clone $dt;
                $this->modifiedColumns[CertificadoRequerimentoTableMap::COL_RESOLVIDO_EM] = true;
            }
        } // if either are not null

        return $this;
    }

    /**
     * Set the value of [resolucao] column.
     *
     * @param string|null $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setResolucao($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->resolucao !== $v) {
            $this->resolucao = $v;
            $this->modifiedColumns[CertificadoRequerimentoTableMap::COL_RESOLUCAO] = true;
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
            if ($this->status !== 0) {
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

            $col = $row[TableMap::TYPE_NUM == $indexType ? 0 + $startcol : CertificadoRequerimentoTableMap::translateFieldName('RequerimentoId', TableMap::TYPE_PHPNAME, $indexType)];
            $this->requerimento_id = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 1 + $startcol : CertificadoRequerimentoTableMap::translateFieldName('Caso', TableMap::TYPE_PHPNAME, $indexType)];
            $this->caso = (null !== $col) ? (int) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 2 + $startcol : CertificadoRequerimentoTableMap::translateFieldName('Token', TableMap::TYPE_PHPNAME, $indexType)];
            $this->token = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 3 + $startcol : CertificadoRequerimentoTableMap::translateFieldName('EventoId', TableMap::TYPE_PHPNAME, $indexType)];
            $this->evento = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 4 + $startcol : CertificadoRequerimentoTableMap::translateFieldName('EventoTexto', TableMap::TYPE_PHPNAME, $indexType)];
            $this->evento_texto = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 5 + $startcol : CertificadoRequerimentoTableMap::translateFieldName('Documento', TableMap::TYPE_PHPNAME, $indexType)];
            $this->documento = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 6 + $startcol : CertificadoRequerimentoTableMap::translateFieldName('Nome', TableMap::TYPE_PHPNAME, $indexType)];
            $this->nome = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 7 + $startcol : CertificadoRequerimentoTableMap::translateFieldName('Email', TableMap::TYPE_PHPNAME, $indexType)];
            $this->email = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 8 + $startcol : CertificadoRequerimentoTableMap::translateFieldName('Telefone', TableMap::TYPE_PHPNAME, $indexType)];
            $this->telefone = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 9 + $startcol : CertificadoRequerimentoTableMap::translateFieldName('Descricao', TableMap::TYPE_PHPNAME, $indexType)];
            $this->descricao = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 10 + $startcol : CertificadoRequerimentoTableMap::translateFieldName('CriadoEm', TableMap::TYPE_PHPNAME, $indexType)];
            if ($col === '0000-00-00 00:00:00') {
                $col = null;
            }
            $this->criado_em = (null !== $col) ? PropelDateTime::newInstance($col, null, 'DateTime') : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 11 + $startcol : CertificadoRequerimentoTableMap::translateFieldName('AvisadoEm', TableMap::TYPE_PHPNAME, $indexType)];
            if ($col === '0000-00-00 00:00:00') {
                $col = null;
            }
            $this->avisado_em = (null !== $col) ? PropelDateTime::newInstance($col, null, 'DateTime') : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 12 + $startcol : CertificadoRequerimentoTableMap::translateFieldName('Status', TableMap::TYPE_PHPNAME, $indexType)];
            $this->status = (null !== $col) ? (int) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 13 + $startcol : CertificadoRequerimentoTableMap::translateFieldName('ResolvidoPor', TableMap::TYPE_PHPNAME, $indexType)];
            $this->resolvido_por = (null !== $col) ? (int) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 14 + $startcol : CertificadoRequerimentoTableMap::translateFieldName('ResolvidoEm', TableMap::TYPE_PHPNAME, $indexType)];
            if ($col === '0000-00-00 00:00:00') {
                $col = null;
            }
            $this->resolvido_em = (null !== $col) ? PropelDateTime::newInstance($col, null, 'DateTime') : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 15 + $startcol : CertificadoRequerimentoTableMap::translateFieldName('Resolucao', TableMap::TYPE_PHPNAME, $indexType)];
            $this->resolucao = (null !== $col) ? (string) $col : null;

            $this->resetModified();
            $this->setNew(false);

            if ($rehydrate) {
                $this->ensureConsistency();
            }

            return $startcol + 16; // 16 = CertificadoRequerimentoTableMap::NUM_HYDRATE_COLUMNS.

        } catch (Exception $e) {
            throw new PropelException(sprintf('Error populating %s object', '\\Baja\\Model\\CertificadoRequerimento'), 0, $e);
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
        if ($this->aUser !== null && $this->resolvido_por !== $this->aUser->getUserId()) {
            $this->aUser = null;
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
            $con = Propel::getServiceContainer()->getReadConnection(CertificadoRequerimentoTableMap::DATABASE_NAME);
        }

        // We don't need to alter the object instance pool; we're just modifying this instance
        // already in the pool.

        $dataFetcher = ChildCertificadoRequerimentoQuery::create(null, $this->buildPkeyCriteria())->setFormatter(ModelCriteria::FORMAT_STATEMENT)->find($con);
        $row = $dataFetcher->fetch();
        $dataFetcher->close();
        if (!$row) {
            throw new PropelException('Cannot find matching row in the database to reload object values.');
        }
        $this->hydrate($row, 0, true, $dataFetcher->getIndexType()); // rehydrate

        if ($deep) {  // also de-associate any related objects?

            $this->aUser = null;
        } // if (deep)
    }

    /**
     * Removes this object from datastore and sets delete attribute.
     *
     * @param ConnectionInterface $con
     * @return void
     * @throws \Propel\Runtime\Exception\PropelException
     * @see CertificadoRequerimento::setDeleted()
     * @see CertificadoRequerimento::isDeleted()
     */
    public function delete(?ConnectionInterface $con = null): void
    {
        if ($this->isDeleted()) {
            throw new PropelException("This object has already been deleted.");
        }

        if ($con === null) {
            $con = Propel::getServiceContainer()->getWriteConnection(CertificadoRequerimentoTableMap::DATABASE_NAME);
        }

        $con->transaction(function () use ($con) {
            $deleteQuery = ChildCertificadoRequerimentoQuery::create()
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
            $con = Propel::getServiceContainer()->getWriteConnection(CertificadoRequerimentoTableMap::DATABASE_NAME);
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
                CertificadoRequerimentoTableMap::addInstanceToPool($this);
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

            if ($this->aUser !== null) {
                if ($this->aUser->isModified() || $this->aUser->isNew()) {
                    $affectedRows += $this->aUser->save($con);
                }
                $this->setUser($this->aUser);
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
        if ($this->isColumnModified(CertificadoRequerimentoTableMap::COL_REQUERIMENTO_ID)) {
            $modifiedColumns[':p' . $index++]  = 'requerimento_id';
        }
        if ($this->isColumnModified(CertificadoRequerimentoTableMap::COL_CASO)) {
            $modifiedColumns[':p' . $index++]  = 'caso';
        }
        if ($this->isColumnModified(CertificadoRequerimentoTableMap::COL_TOKEN)) {
            $modifiedColumns[':p' . $index++]  = 'token';
        }
        if ($this->isColumnModified(CertificadoRequerimentoTableMap::COL_EVENTO)) {
            $modifiedColumns[':p' . $index++]  = 'evento';
        }
        if ($this->isColumnModified(CertificadoRequerimentoTableMap::COL_EVENTO_TEXTO)) {
            $modifiedColumns[':p' . $index++]  = 'evento_texto';
        }
        if ($this->isColumnModified(CertificadoRequerimentoTableMap::COL_DOCUMENTO)) {
            $modifiedColumns[':p' . $index++]  = 'documento';
        }
        if ($this->isColumnModified(CertificadoRequerimentoTableMap::COL_NOME)) {
            $modifiedColumns[':p' . $index++]  = 'nome';
        }
        if ($this->isColumnModified(CertificadoRequerimentoTableMap::COL_EMAIL)) {
            $modifiedColumns[':p' . $index++]  = 'email';
        }
        if ($this->isColumnModified(CertificadoRequerimentoTableMap::COL_TELEFONE)) {
            $modifiedColumns[':p' . $index++]  = 'telefone';
        }
        if ($this->isColumnModified(CertificadoRequerimentoTableMap::COL_DESCRICAO)) {
            $modifiedColumns[':p' . $index++]  = 'descricao';
        }
        if ($this->isColumnModified(CertificadoRequerimentoTableMap::COL_CRIADO_EM)) {
            $modifiedColumns[':p' . $index++]  = 'criado_em';
        }
        if ($this->isColumnModified(CertificadoRequerimentoTableMap::COL_AVISADO_EM)) {
            $modifiedColumns[':p' . $index++]  = 'avisado_em';
        }
        if ($this->isColumnModified(CertificadoRequerimentoTableMap::COL_STATUS)) {
            $modifiedColumns[':p' . $index++]  = 'status';
        }
        if ($this->isColumnModified(CertificadoRequerimentoTableMap::COL_RESOLVIDO_POR)) {
            $modifiedColumns[':p' . $index++]  = 'resolvido_por';
        }
        if ($this->isColumnModified(CertificadoRequerimentoTableMap::COL_RESOLVIDO_EM)) {
            $modifiedColumns[':p' . $index++]  = 'resolvido_em';
        }
        if ($this->isColumnModified(CertificadoRequerimentoTableMap::COL_RESOLUCAO)) {
            $modifiedColumns[':p' . $index++]  = 'resolucao';
        }

        $sql = sprintf(
            'INSERT INTO certificado_requerimento (%s) VALUES (%s)',
            implode(', ', $modifiedColumns),
            implode(', ', array_keys($modifiedColumns))
        );

        try {
            $stmt = $con->prepare($sql);
            foreach ($modifiedColumns as $identifier => $columnName) {
                switch ($columnName) {
                    case 'requerimento_id':
                        $stmt->bindValue($identifier, $this->requerimento_id, PDO::PARAM_STR);

                        break;
                    case 'caso':
                        $stmt->bindValue($identifier, $this->caso, PDO::PARAM_INT);

                        break;
                    case 'token':
                        $stmt->bindValue($identifier, $this->token, PDO::PARAM_STR);

                        break;
                    case 'evento':
                        $stmt->bindValue($identifier, $this->evento, PDO::PARAM_STR);

                        break;
                    case 'evento_texto':
                        $stmt->bindValue($identifier, $this->evento_texto, PDO::PARAM_STR);

                        break;
                    case 'documento':
                        $stmt->bindValue($identifier, $this->documento, PDO::PARAM_STR);

                        break;
                    case 'nome':
                        $stmt->bindValue($identifier, $this->nome, PDO::PARAM_STR);

                        break;
                    case 'email':
                        $stmt->bindValue($identifier, $this->email, PDO::PARAM_STR);

                        break;
                    case 'telefone':
                        $stmt->bindValue($identifier, $this->telefone, PDO::PARAM_STR);

                        break;
                    case 'descricao':
                        $stmt->bindValue($identifier, $this->descricao, PDO::PARAM_STR);

                        break;
                    case 'criado_em':
                        $stmt->bindValue($identifier, $this->criado_em ? $this->criado_em->format("Y-m-d H:i:s.u") : null, PDO::PARAM_STR);

                        break;
                    case 'avisado_em':
                        $stmt->bindValue($identifier, $this->avisado_em ? $this->avisado_em->format("Y-m-d H:i:s.u") : null, PDO::PARAM_STR);

                        break;
                    case 'status':
                        $stmt->bindValue($identifier, $this->status, PDO::PARAM_INT);

                        break;
                    case 'resolvido_por':
                        $stmt->bindValue($identifier, $this->resolvido_por, PDO::PARAM_INT);

                        break;
                    case 'resolvido_em':
                        $stmt->bindValue($identifier, $this->resolvido_em ? $this->resolvido_em->format("Y-m-d H:i:s.u") : null, PDO::PARAM_STR);

                        break;
                    case 'resolucao':
                        $stmt->bindValue($identifier, $this->resolucao, PDO::PARAM_STR);

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
        $pos = CertificadoRequerimentoTableMap::translateFieldName($name, $type, TableMap::TYPE_NUM);
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
                return $this->getRequerimentoId();

            case 1:
                return $this->getCaso();

            case 2:
                return $this->getToken();

            case 3:
                return $this->getEventoId();

            case 4:
                return $this->getEventoTexto();

            case 5:
                return $this->getDocumento();

            case 6:
                return $this->getNome();

            case 7:
                return $this->getEmail();

            case 8:
                return $this->getTelefone();

            case 9:
                return $this->getDescricao();

            case 10:
                return $this->getCriadoEm();

            case 11:
                return $this->getAvisadoEm();

            case 12:
                return $this->getStatus();

            case 13:
                return $this->getResolvidoPor();

            case 14:
                return $this->getResolvidoEm();

            case 15:
                return $this->getResolucao();

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
        if (isset($alreadyDumpedObjects['CertificadoRequerimento'][$this->hashCode()])) {
            return ['*RECURSION*'];
        }
        $alreadyDumpedObjects['CertificadoRequerimento'][$this->hashCode()] = true;
        $keys = CertificadoRequerimentoTableMap::getFieldNames($keyType);
        $result = [
            $keys[0] => $this->getRequerimentoId(),
            $keys[1] => $this->getCaso(),
            $keys[2] => $this->getToken(),
            $keys[3] => $this->getEventoId(),
            $keys[4] => $this->getEventoTexto(),
            $keys[5] => $this->getDocumento(),
            $keys[6] => $this->getNome(),
            $keys[7] => $this->getEmail(),
            $keys[8] => $this->getTelefone(),
            $keys[9] => $this->getDescricao(),
            $keys[10] => $this->getCriadoEm(),
            $keys[11] => $this->getAvisadoEm(),
            $keys[12] => $this->getStatus(),
            $keys[13] => $this->getResolvidoPor(),
            $keys[14] => $this->getResolvidoEm(),
            $keys[15] => $this->getResolucao(),
        ];
        if ($result[$keys[10]] instanceof \DateTimeInterface) {
            $result[$keys[10]] = $result[$keys[10]]->format('Y-m-d H:i:s.u');
        }

        if ($result[$keys[11]] instanceof \DateTimeInterface) {
            $result[$keys[11]] = $result[$keys[11]]->format('Y-m-d H:i:s.u');
        }

        if ($result[$keys[14]] instanceof \DateTimeInterface) {
            $result[$keys[14]] = $result[$keys[14]]->format('Y-m-d H:i:s.u');
        }

        $virtualColumns = $this->virtualColumns;
        foreach ($virtualColumns as $key => $virtualColumn) {
            $result[$key] = $virtualColumn;
        }

        if ($includeForeignObjects) {
            if (null !== $this->aUser) {

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

                $result[$key] = $this->aUser->toArray($keyType, $includeLazyLoadColumns,  $alreadyDumpedObjects, true);
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
        $pos = CertificadoRequerimentoTableMap::translateFieldName($name, $type, TableMap::TYPE_NUM);

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
                $this->setRequerimentoId($value);
                break;
            case 1:
                $valueSet = CertificadoRequerimentoTableMap::getValueSet(CertificadoRequerimentoTableMap::COL_CASO);
                if (isset($valueSet[$value])) {
                    $value = $valueSet[$value];
                }
                $this->setCaso($value);
                break;
            case 2:
                $this->setToken($value);
                break;
            case 3:
                $this->setEventoId($value);
                break;
            case 4:
                $this->setEventoTexto($value);
                break;
            case 5:
                $this->setDocumento($value);
                break;
            case 6:
                $this->setNome($value);
                break;
            case 7:
                $this->setEmail($value);
                break;
            case 8:
                $this->setTelefone($value);
                break;
            case 9:
                $this->setDescricao($value);
                break;
            case 10:
                $this->setCriadoEm($value);
                break;
            case 11:
                $this->setAvisadoEm($value);
                break;
            case 12:
                $valueSet = CertificadoRequerimentoTableMap::getValueSet(CertificadoRequerimentoTableMap::COL_STATUS);
                if (isset($valueSet[$value])) {
                    $value = $valueSet[$value];
                }
                $this->setStatus($value);
                break;
            case 13:
                $this->setResolvidoPor($value);
                break;
            case 14:
                $this->setResolvidoEm($value);
                break;
            case 15:
                $this->setResolucao($value);
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
        $keys = CertificadoRequerimentoTableMap::getFieldNames($keyType);

        if (array_key_exists($keys[0], $arr)) {
            $this->setRequerimentoId($arr[$keys[0]]);
        }
        if (array_key_exists($keys[1], $arr)) {
            $this->setCaso($arr[$keys[1]]);
        }
        if (array_key_exists($keys[2], $arr)) {
            $this->setToken($arr[$keys[2]]);
        }
        if (array_key_exists($keys[3], $arr)) {
            $this->setEventoId($arr[$keys[3]]);
        }
        if (array_key_exists($keys[4], $arr)) {
            $this->setEventoTexto($arr[$keys[4]]);
        }
        if (array_key_exists($keys[5], $arr)) {
            $this->setDocumento($arr[$keys[5]]);
        }
        if (array_key_exists($keys[6], $arr)) {
            $this->setNome($arr[$keys[6]]);
        }
        if (array_key_exists($keys[7], $arr)) {
            $this->setEmail($arr[$keys[7]]);
        }
        if (array_key_exists($keys[8], $arr)) {
            $this->setTelefone($arr[$keys[8]]);
        }
        if (array_key_exists($keys[9], $arr)) {
            $this->setDescricao($arr[$keys[9]]);
        }
        if (array_key_exists($keys[10], $arr)) {
            $this->setCriadoEm($arr[$keys[10]]);
        }
        if (array_key_exists($keys[11], $arr)) {
            $this->setAvisadoEm($arr[$keys[11]]);
        }
        if (array_key_exists($keys[12], $arr)) {
            $this->setStatus($arr[$keys[12]]);
        }
        if (array_key_exists($keys[13], $arr)) {
            $this->setResolvidoPor($arr[$keys[13]]);
        }
        if (array_key_exists($keys[14], $arr)) {
            $this->setResolvidoEm($arr[$keys[14]]);
        }
        if (array_key_exists($keys[15], $arr)) {
            $this->setResolucao($arr[$keys[15]]);
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
        $criteria = new Criteria(CertificadoRequerimentoTableMap::DATABASE_NAME);

        if ($this->isColumnModified(CertificadoRequerimentoTableMap::COL_REQUERIMENTO_ID)) {
            $criteria->add(CertificadoRequerimentoTableMap::COL_REQUERIMENTO_ID, $this->requerimento_id);
        }
        if ($this->isColumnModified(CertificadoRequerimentoTableMap::COL_CASO)) {
            $criteria->add(CertificadoRequerimentoTableMap::COL_CASO, $this->caso);
        }
        if ($this->isColumnModified(CertificadoRequerimentoTableMap::COL_TOKEN)) {
            $criteria->add(CertificadoRequerimentoTableMap::COL_TOKEN, $this->token);
        }
        if ($this->isColumnModified(CertificadoRequerimentoTableMap::COL_EVENTO)) {
            $criteria->add(CertificadoRequerimentoTableMap::COL_EVENTO, $this->evento);
        }
        if ($this->isColumnModified(CertificadoRequerimentoTableMap::COL_EVENTO_TEXTO)) {
            $criteria->add(CertificadoRequerimentoTableMap::COL_EVENTO_TEXTO, $this->evento_texto);
        }
        if ($this->isColumnModified(CertificadoRequerimentoTableMap::COL_DOCUMENTO)) {
            $criteria->add(CertificadoRequerimentoTableMap::COL_DOCUMENTO, $this->documento);
        }
        if ($this->isColumnModified(CertificadoRequerimentoTableMap::COL_NOME)) {
            $criteria->add(CertificadoRequerimentoTableMap::COL_NOME, $this->nome);
        }
        if ($this->isColumnModified(CertificadoRequerimentoTableMap::COL_EMAIL)) {
            $criteria->add(CertificadoRequerimentoTableMap::COL_EMAIL, $this->email);
        }
        if ($this->isColumnModified(CertificadoRequerimentoTableMap::COL_TELEFONE)) {
            $criteria->add(CertificadoRequerimentoTableMap::COL_TELEFONE, $this->telefone);
        }
        if ($this->isColumnModified(CertificadoRequerimentoTableMap::COL_DESCRICAO)) {
            $criteria->add(CertificadoRequerimentoTableMap::COL_DESCRICAO, $this->descricao);
        }
        if ($this->isColumnModified(CertificadoRequerimentoTableMap::COL_CRIADO_EM)) {
            $criteria->add(CertificadoRequerimentoTableMap::COL_CRIADO_EM, $this->criado_em);
        }
        if ($this->isColumnModified(CertificadoRequerimentoTableMap::COL_AVISADO_EM)) {
            $criteria->add(CertificadoRequerimentoTableMap::COL_AVISADO_EM, $this->avisado_em);
        }
        if ($this->isColumnModified(CertificadoRequerimentoTableMap::COL_STATUS)) {
            $criteria->add(CertificadoRequerimentoTableMap::COL_STATUS, $this->status);
        }
        if ($this->isColumnModified(CertificadoRequerimentoTableMap::COL_RESOLVIDO_POR)) {
            $criteria->add(CertificadoRequerimentoTableMap::COL_RESOLVIDO_POR, $this->resolvido_por);
        }
        if ($this->isColumnModified(CertificadoRequerimentoTableMap::COL_RESOLVIDO_EM)) {
            $criteria->add(CertificadoRequerimentoTableMap::COL_RESOLVIDO_EM, $this->resolvido_em);
        }
        if ($this->isColumnModified(CertificadoRequerimentoTableMap::COL_RESOLUCAO)) {
            $criteria->add(CertificadoRequerimentoTableMap::COL_RESOLUCAO, $this->resolucao);
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
        $criteria = ChildCertificadoRequerimentoQuery::create();
        $criteria->add(CertificadoRequerimentoTableMap::COL_REQUERIMENTO_ID, $this->requerimento_id);

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
        $validPk = null !== $this->getRequerimentoId();

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
        return $this->getRequerimentoId();
    }

    /**
     * Generic method to set the primary key (requerimento_id column).
     *
     * @param string|null $key Primary key.
     * @return void
     */
    public function setPrimaryKey(?string $key = null): void
    {
        $this->setRequerimentoId($key);
    }

    /**
     * Returns true if the primary key for this object is null.
     *
     * @return bool
     */
    public function isPrimaryKeyNull(): bool
    {
        return null === $this->getRequerimentoId();
    }

    /**
     * Sets contents of passed object to values from current object.
     *
     * If desired, this method can also make copies of all associated (fkey referrers)
     * objects.
     *
     * @param object $copyObj An object of \Baja\Model\CertificadoRequerimento (or compatible) type.
     * @param bool $deepCopy Whether to also copy all rows that refer (by fkey) to the current row.
     * @param bool $makeNew Whether to reset autoincrement PKs and make the object new.
     * @throws \Propel\Runtime\Exception\PropelException
     * @return void
     */
    public function copyInto(object $copyObj, bool $deepCopy = false, bool $makeNew = true): void
    {
        $copyObj->setRequerimentoId($this->getRequerimentoId());
        $copyObj->setCaso($this->getCaso());
        $copyObj->setToken($this->getToken());
        $copyObj->setEventoId($this->getEventoId());
        $copyObj->setEventoTexto($this->getEventoTexto());
        $copyObj->setDocumento($this->getDocumento());
        $copyObj->setNome($this->getNome());
        $copyObj->setEmail($this->getEmail());
        $copyObj->setTelefone($this->getTelefone());
        $copyObj->setDescricao($this->getDescricao());
        $copyObj->setCriadoEm($this->getCriadoEm());
        $copyObj->setAvisadoEm($this->getAvisadoEm());
        $copyObj->setStatus($this->getStatus());
        $copyObj->setResolvidoPor($this->getResolvidoPor());
        $copyObj->setResolvidoEm($this->getResolvidoEm());
        $copyObj->setResolucao($this->getResolucao());
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
     * @return \Baja\Model\CertificadoRequerimento Clone of current object.
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
     * Declares an association between this object and a ChildUser object.
     *
     * @param ChildUser|null $v
     * @return $this The current object (for fluent API support)
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function setUser(ChildUser $v = null)
    {
        if ($v === null) {
            $this->setResolvidoPor(NULL);
        } else {
            $this->setResolvidoPor($v->getUserId());
        }

        $this->aUser = $v;

        // Add binding for other direction of this n:n relationship.
        // If this object has already been added to the ChildUser object, it will not be re-added.
        if ($v !== null) {
            $v->addCertificadoRequerimento($this);
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
    public function getUser(?ConnectionInterface $con = null)
    {
        if ($this->aUser === null && ($this->resolvido_por != 0)) {
            $this->aUser = ChildUserQuery::create()->findPk($this->resolvido_por, $con);
            /* The following can be used additionally to
                guarantee the related object contains a reference
                to this object.  This level of coupling may, however, be
                undesirable since it could result in an only partially populated collection
                in the referenced object.
                $this->aUser->addCertificadoRequerimentos($this);
             */
        }

        return $this->aUser;
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
        if (null !== $this->aUser) {
            $this->aUser->removeCertificadoRequerimento($this);
        }
        $this->requerimento_id = null;
        $this->caso = null;
        $this->token = null;
        $this->evento = null;
        $this->evento_texto = null;
        $this->documento = null;
        $this->nome = null;
        $this->email = null;
        $this->telefone = null;
        $this->descricao = null;
        $this->criado_em = null;
        $this->avisado_em = null;
        $this->status = null;
        $this->resolvido_por = null;
        $this->resolvido_em = null;
        $this->resolucao = null;
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

        $this->aUser = null;
        return $this;
    }

    /**
     * Return the string representation of this object
     *
     * @return string
     */
    public function __toString()
    {
        return (string) $this->exportTo(CertificadoRequerimentoTableMap::DEFAULT_STRING_FORMAT);
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
