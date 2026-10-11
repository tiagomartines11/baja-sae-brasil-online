<?php

namespace Baja\Model\Base;

use \Exception;
use \PDO;
use Baja\Model\ContagemDispositivo as ChildContagemDispositivo;
use Baja\Model\ContagemDispositivoQuery as ChildContagemDispositivoQuery;
use Baja\Model\ContagemFonte as ChildContagemFonte;
use Baja\Model\ContagemFonteQuery as ChildContagemFonteQuery;
use Baja\Model\ContagemPassagem as ChildContagemPassagem;
use Baja\Model\ContagemPassagemQuery as ChildContagemPassagemQuery;
use Baja\Model\Map\ContagemDispositivoTableMap;
use Baja\Model\Map\ContagemPassagemTableMap;
use Propel\Runtime\Propel;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\ModelCriteria;
use Propel\Runtime\ActiveRecord\ActiveRecordInterface;
use Propel\Runtime\Collection\Collection;
use Propel\Runtime\Collection\ObjectCollection;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Exception\BadMethodCallException;
use Propel\Runtime\Exception\LogicException;
use Propel\Runtime\Exception\PropelException;
use Propel\Runtime\Map\TableMap;
use Propel\Runtime\Parser\AbstractParser;

/**
 * Base class that represents a row from the 'contagem_dispositivo' table.
 *
 *
 *
 * @package    propel.generator.Baja.Model.Base
 */
abstract class ContagemDispositivo implements ActiveRecordInterface
{
    /**
     * TableMap class name
     *
     * @var string
     */
    public const TABLE_MAP = '\\Baja\\Model\\Map\\ContagemDispositivoTableMap';


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
     * The value for the dispositivo_id field.
     *
     * @var        int
     */
    protected $dispositivo_id;

    /**
     * The value for the fonte_id field.
     *
     * @var        int
     */
    protected $fonte_id;

    /**
     * The value for the token_hash field.
     *
     * @var        string
     */
    protected $token_hash;

    /**
     * The value for the usuario field.
     *
     * @var        string|null
     */
    protected $usuario;

    /**
     * The value for the rotulo field.
     *
     * @var        string|null
     */
    protected $rotulo;

    /**
     * The value for the criado_em_ms field.
     *
     * @var        string
     */
    protected $criado_em_ms;

    /**
     * The value for the revogado_em_ms field.
     *
     * @var        string|null
     */
    protected $revogado_em_ms;

    /**
     * The value for the fechado_em_ms field.
     *
     * @var        string|null
     */
    protected $fechado_em_ms;

    /**
     * The value for the visto_em_ms field.
     *
     * @var        string|null
     */
    protected $visto_em_ms;

    /**
     * The value for the sincronizado_ate_ms field.
     *
     * @var        string|null
     */
    protected $sincronizado_ate_ms;

    /**
     * The value for the primeiro_desvio_ms field.
     *
     * @var        string|null
     */
    protected $primeiro_desvio_ms;

    /**
     * @var        ChildContagemFonte
     */
    protected $aContagemFonte;

    /**
     * @var        ObjectCollection|ChildContagemPassagem[] Collection to store aggregation of ChildContagemPassagem objects.
     * @phpstan-var ObjectCollection&\Traversable<ChildContagemPassagem> Collection to store aggregation of ChildContagemPassagem objects.
     */
    protected $collContagemPassagems;
    protected $collContagemPassagemsPartial;

    /**
     * Flag to prevent endless save loop, if this object is referenced
     * by another object which falls in this transaction.
     *
     * @var bool
     */
    protected $alreadyInSave = false;

    /**
     * An array of objects scheduled for deletion.
     * @var ObjectCollection|ChildContagemPassagem[]
     * @phpstan-var ObjectCollection&\Traversable<ChildContagemPassagem>
     */
    protected $contagemPassagemsScheduledForDeletion = null;

    /**
     * Initializes internal state of Baja\Model\Base\ContagemDispositivo object.
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
     * Compares this with another <code>ContagemDispositivo</code> instance.  If
     * <code>obj</code> is an instance of <code>ContagemDispositivo</code>, delegates to
     * <code>equals(ContagemDispositivo)</code>.  Otherwise, returns <code>false</code>.
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
     * Get the [dispositivo_id] column value.
     *
     * @return int
     */
    public function getDispositivoId()
    {
        return $this->dispositivo_id;
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
     * Get the [token_hash] column value.
     *
     * @return string
     */
    public function getTokenHash()
    {
        return $this->token_hash;
    }

    /**
     * Get the [usuario] column value.
     *
     * @return string|null
     */
    public function getUsuario()
    {
        return $this->usuario;
    }

    /**
     * Get the [rotulo] column value.
     *
     * @return string|null
     */
    public function getRotulo()
    {
        return $this->rotulo;
    }

    /**
     * Get the [criado_em_ms] column value.
     *
     * @return string
     */
    public function getCriadoEmMs()
    {
        return $this->criado_em_ms;
    }

    /**
     * Get the [revogado_em_ms] column value.
     *
     * @return string|null
     */
    public function getRevogadoEmMs()
    {
        return $this->revogado_em_ms;
    }

    /**
     * Get the [fechado_em_ms] column value.
     *
     * @return string|null
     */
    public function getFechadoEmMs()
    {
        return $this->fechado_em_ms;
    }

    /**
     * Get the [visto_em_ms] column value.
     *
     * @return string|null
     */
    public function getVistoEmMs()
    {
        return $this->visto_em_ms;
    }

    /**
     * Get the [sincronizado_ate_ms] column value.
     *
     * @return string|null
     */
    public function getSincronizadoAteMs()
    {
        return $this->sincronizado_ate_ms;
    }

    /**
     * Get the [primeiro_desvio_ms] column value.
     *
     * @return string|null
     */
    public function getPrimeiroDesvioMs()
    {
        return $this->primeiro_desvio_ms;
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
            $this->modifiedColumns[ContagemDispositivoTableMap::COL_DISPOSITIVO_ID] = true;
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
            $this->modifiedColumns[ContagemDispositivoTableMap::COL_FONTE_ID] = true;
        }

        if ($this->aContagemFonte !== null && $this->aContagemFonte->getFonteId() !== $v) {
            $this->aContagemFonte = null;
        }

        return $this;
    }

    /**
     * Set the value of [token_hash] column.
     *
     * @param string $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setTokenHash($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->token_hash !== $v) {
            $this->token_hash = $v;
            $this->modifiedColumns[ContagemDispositivoTableMap::COL_TOKEN_HASH] = true;
        }

        return $this;
    }

    /**
     * Set the value of [usuario] column.
     *
     * @param string|null $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setUsuario($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->usuario !== $v) {
            $this->usuario = $v;
            $this->modifiedColumns[ContagemDispositivoTableMap::COL_USUARIO] = true;
        }

        return $this;
    }

    /**
     * Set the value of [rotulo] column.
     *
     * @param string|null $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setRotulo($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->rotulo !== $v) {
            $this->rotulo = $v;
            $this->modifiedColumns[ContagemDispositivoTableMap::COL_ROTULO] = true;
        }

        return $this;
    }

    /**
     * Set the value of [criado_em_ms] column.
     *
     * @param string $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setCriadoEmMs($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->criado_em_ms !== $v) {
            $this->criado_em_ms = $v;
            $this->modifiedColumns[ContagemDispositivoTableMap::COL_CRIADO_EM_MS] = true;
        }

        return $this;
    }

    /**
     * Set the value of [revogado_em_ms] column.
     *
     * @param string|null $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setRevogadoEmMs($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->revogado_em_ms !== $v) {
            $this->revogado_em_ms = $v;
            $this->modifiedColumns[ContagemDispositivoTableMap::COL_REVOGADO_EM_MS] = true;
        }

        return $this;
    }

    /**
     * Set the value of [fechado_em_ms] column.
     *
     * @param string|null $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setFechadoEmMs($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->fechado_em_ms !== $v) {
            $this->fechado_em_ms = $v;
            $this->modifiedColumns[ContagemDispositivoTableMap::COL_FECHADO_EM_MS] = true;
        }

        return $this;
    }

    /**
     * Set the value of [visto_em_ms] column.
     *
     * @param string|null $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setVistoEmMs($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->visto_em_ms !== $v) {
            $this->visto_em_ms = $v;
            $this->modifiedColumns[ContagemDispositivoTableMap::COL_VISTO_EM_MS] = true;
        }

        return $this;
    }

    /**
     * Set the value of [sincronizado_ate_ms] column.
     *
     * @param string|null $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setSincronizadoAteMs($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->sincronizado_ate_ms !== $v) {
            $this->sincronizado_ate_ms = $v;
            $this->modifiedColumns[ContagemDispositivoTableMap::COL_SINCRONIZADO_ATE_MS] = true;
        }

        return $this;
    }

    /**
     * Set the value of [primeiro_desvio_ms] column.
     *
     * @param string|null $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setPrimeiroDesvioMs($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->primeiro_desvio_ms !== $v) {
            $this->primeiro_desvio_ms = $v;
            $this->modifiedColumns[ContagemDispositivoTableMap::COL_PRIMEIRO_DESVIO_MS] = true;
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

            $col = $row[TableMap::TYPE_NUM == $indexType ? 0 + $startcol : ContagemDispositivoTableMap::translateFieldName('DispositivoId', TableMap::TYPE_PHPNAME, $indexType)];
            $this->dispositivo_id = (null !== $col) ? (int) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 1 + $startcol : ContagemDispositivoTableMap::translateFieldName('FonteId', TableMap::TYPE_PHPNAME, $indexType)];
            $this->fonte_id = (null !== $col) ? (int) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 2 + $startcol : ContagemDispositivoTableMap::translateFieldName('TokenHash', TableMap::TYPE_PHPNAME, $indexType)];
            $this->token_hash = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 3 + $startcol : ContagemDispositivoTableMap::translateFieldName('Usuario', TableMap::TYPE_PHPNAME, $indexType)];
            $this->usuario = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 4 + $startcol : ContagemDispositivoTableMap::translateFieldName('Rotulo', TableMap::TYPE_PHPNAME, $indexType)];
            $this->rotulo = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 5 + $startcol : ContagemDispositivoTableMap::translateFieldName('CriadoEmMs', TableMap::TYPE_PHPNAME, $indexType)];
            $this->criado_em_ms = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 6 + $startcol : ContagemDispositivoTableMap::translateFieldName('RevogadoEmMs', TableMap::TYPE_PHPNAME, $indexType)];
            $this->revogado_em_ms = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 7 + $startcol : ContagemDispositivoTableMap::translateFieldName('FechadoEmMs', TableMap::TYPE_PHPNAME, $indexType)];
            $this->fechado_em_ms = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 8 + $startcol : ContagemDispositivoTableMap::translateFieldName('VistoEmMs', TableMap::TYPE_PHPNAME, $indexType)];
            $this->visto_em_ms = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 9 + $startcol : ContagemDispositivoTableMap::translateFieldName('SincronizadoAteMs', TableMap::TYPE_PHPNAME, $indexType)];
            $this->sincronizado_ate_ms = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 10 + $startcol : ContagemDispositivoTableMap::translateFieldName('PrimeiroDesvioMs', TableMap::TYPE_PHPNAME, $indexType)];
            $this->primeiro_desvio_ms = (null !== $col) ? (string) $col : null;

            $this->resetModified();
            $this->setNew(false);

            if ($rehydrate) {
                $this->ensureConsistency();
            }

            return $startcol + 11; // 11 = ContagemDispositivoTableMap::NUM_HYDRATE_COLUMNS.

        } catch (Exception $e) {
            throw new PropelException(sprintf('Error populating %s object', '\\Baja\\Model\\ContagemDispositivo'), 0, $e);
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
            $con = Propel::getServiceContainer()->getReadConnection(ContagemDispositivoTableMap::DATABASE_NAME);
        }

        // We don't need to alter the object instance pool; we're just modifying this instance
        // already in the pool.

        $dataFetcher = ChildContagemDispositivoQuery::create(null, $this->buildPkeyCriteria())->setFormatter(ModelCriteria::FORMAT_STATEMENT)->find($con);
        $row = $dataFetcher->fetch();
        $dataFetcher->close();
        if (!$row) {
            throw new PropelException('Cannot find matching row in the database to reload object values.');
        }
        $this->hydrate($row, 0, true, $dataFetcher->getIndexType()); // rehydrate

        if ($deep) {  // also de-associate any related objects?

            $this->aContagemFonte = null;
            $this->collContagemPassagems = null;

        } // if (deep)
    }

    /**
     * Removes this object from datastore and sets delete attribute.
     *
     * @param ConnectionInterface $con
     * @return void
     * @throws \Propel\Runtime\Exception\PropelException
     * @see ContagemDispositivo::setDeleted()
     * @see ContagemDispositivo::isDeleted()
     */
    public function delete(?ConnectionInterface $con = null): void
    {
        if ($this->isDeleted()) {
            throw new PropelException("This object has already been deleted.");
        }

        if ($con === null) {
            $con = Propel::getServiceContainer()->getWriteConnection(ContagemDispositivoTableMap::DATABASE_NAME);
        }

        $con->transaction(function () use ($con) {
            $deleteQuery = ChildContagemDispositivoQuery::create()
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
            $con = Propel::getServiceContainer()->getWriteConnection(ContagemDispositivoTableMap::DATABASE_NAME);
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
                ContagemDispositivoTableMap::addInstanceToPool($this);
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

            if ($this->contagemPassagemsScheduledForDeletion !== null) {
                if (!$this->contagemPassagemsScheduledForDeletion->isEmpty()) {
                    \Baja\Model\ContagemPassagemQuery::create()
                        ->filterByPrimaryKeys($this->contagemPassagemsScheduledForDeletion->getPrimaryKeys(false))
                        ->delete($con);
                    $this->contagemPassagemsScheduledForDeletion = null;
                }
            }

            if ($this->collContagemPassagems !== null) {
                foreach ($this->collContagemPassagems as $referrerFK) {
                    if (!$referrerFK->isDeleted() && ($referrerFK->isNew() || $referrerFK->isModified())) {
                        $affectedRows += $referrerFK->save($con);
                    }
                }
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

        $this->modifiedColumns[ContagemDispositivoTableMap::COL_DISPOSITIVO_ID] = true;
        if (null !== $this->dispositivo_id) {
            throw new PropelException('Cannot insert a value for auto-increment primary key (' . ContagemDispositivoTableMap::COL_DISPOSITIVO_ID . ')');
        }

         // check the columns in natural order for more readable SQL queries
        if ($this->isColumnModified(ContagemDispositivoTableMap::COL_DISPOSITIVO_ID)) {
            $modifiedColumns[':p' . $index++]  = 'dispositivo_id';
        }
        if ($this->isColumnModified(ContagemDispositivoTableMap::COL_FONTE_ID)) {
            $modifiedColumns[':p' . $index++]  = 'fonte_id';
        }
        if ($this->isColumnModified(ContagemDispositivoTableMap::COL_TOKEN_HASH)) {
            $modifiedColumns[':p' . $index++]  = 'token_hash';
        }
        if ($this->isColumnModified(ContagemDispositivoTableMap::COL_USUARIO)) {
            $modifiedColumns[':p' . $index++]  = 'usuario';
        }
        if ($this->isColumnModified(ContagemDispositivoTableMap::COL_ROTULO)) {
            $modifiedColumns[':p' . $index++]  = 'rotulo';
        }
        if ($this->isColumnModified(ContagemDispositivoTableMap::COL_CRIADO_EM_MS)) {
            $modifiedColumns[':p' . $index++]  = 'criado_em_ms';
        }
        if ($this->isColumnModified(ContagemDispositivoTableMap::COL_REVOGADO_EM_MS)) {
            $modifiedColumns[':p' . $index++]  = 'revogado_em_ms';
        }
        if ($this->isColumnModified(ContagemDispositivoTableMap::COL_FECHADO_EM_MS)) {
            $modifiedColumns[':p' . $index++]  = 'fechado_em_ms';
        }
        if ($this->isColumnModified(ContagemDispositivoTableMap::COL_VISTO_EM_MS)) {
            $modifiedColumns[':p' . $index++]  = 'visto_em_ms';
        }
        if ($this->isColumnModified(ContagemDispositivoTableMap::COL_SINCRONIZADO_ATE_MS)) {
            $modifiedColumns[':p' . $index++]  = 'sincronizado_ate_ms';
        }
        if ($this->isColumnModified(ContagemDispositivoTableMap::COL_PRIMEIRO_DESVIO_MS)) {
            $modifiedColumns[':p' . $index++]  = 'primeiro_desvio_ms';
        }

        $sql = sprintf(
            'INSERT INTO contagem_dispositivo (%s) VALUES (%s)',
            implode(', ', $modifiedColumns),
            implode(', ', array_keys($modifiedColumns))
        );

        try {
            $stmt = $con->prepare($sql);
            foreach ($modifiedColumns as $identifier => $columnName) {
                switch ($columnName) {
                    case 'dispositivo_id':
                        $stmt->bindValue($identifier, $this->dispositivo_id, PDO::PARAM_INT);

                        break;
                    case 'fonte_id':
                        $stmt->bindValue($identifier, $this->fonte_id, PDO::PARAM_INT);

                        break;
                    case 'token_hash':
                        $stmt->bindValue($identifier, $this->token_hash, PDO::PARAM_STR);

                        break;
                    case 'usuario':
                        $stmt->bindValue($identifier, $this->usuario, PDO::PARAM_STR);

                        break;
                    case 'rotulo':
                        $stmt->bindValue($identifier, $this->rotulo, PDO::PARAM_STR);

                        break;
                    case 'criado_em_ms':
                        $stmt->bindValue($identifier, $this->criado_em_ms, PDO::PARAM_INT);

                        break;
                    case 'revogado_em_ms':
                        $stmt->bindValue($identifier, $this->revogado_em_ms, PDO::PARAM_INT);

                        break;
                    case 'fechado_em_ms':
                        $stmt->bindValue($identifier, $this->fechado_em_ms, PDO::PARAM_INT);

                        break;
                    case 'visto_em_ms':
                        $stmt->bindValue($identifier, $this->visto_em_ms, PDO::PARAM_INT);

                        break;
                    case 'sincronizado_ate_ms':
                        $stmt->bindValue($identifier, $this->sincronizado_ate_ms, PDO::PARAM_INT);

                        break;
                    case 'primeiro_desvio_ms':
                        $stmt->bindValue($identifier, $this->primeiro_desvio_ms, PDO::PARAM_INT);

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
        $this->setDispositivoId($pk);

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
        $pos = ContagemDispositivoTableMap::translateFieldName($name, $type, TableMap::TYPE_NUM);
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
                return $this->getDispositivoId();

            case 1:
                return $this->getFonteId();

            case 2:
                return $this->getTokenHash();

            case 3:
                return $this->getUsuario();

            case 4:
                return $this->getRotulo();

            case 5:
                return $this->getCriadoEmMs();

            case 6:
                return $this->getRevogadoEmMs();

            case 7:
                return $this->getFechadoEmMs();

            case 8:
                return $this->getVistoEmMs();

            case 9:
                return $this->getSincronizadoAteMs();

            case 10:
                return $this->getPrimeiroDesvioMs();

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
        if (isset($alreadyDumpedObjects['ContagemDispositivo'][$this->hashCode()])) {
            return ['*RECURSION*'];
        }
        $alreadyDumpedObjects['ContagemDispositivo'][$this->hashCode()] = true;
        $keys = ContagemDispositivoTableMap::getFieldNames($keyType);
        $result = [
            $keys[0] => $this->getDispositivoId(),
            $keys[1] => $this->getFonteId(),
            $keys[2] => $this->getTokenHash(),
            $keys[3] => $this->getUsuario(),
            $keys[4] => $this->getRotulo(),
            $keys[5] => $this->getCriadoEmMs(),
            $keys[6] => $this->getRevogadoEmMs(),
            $keys[7] => $this->getFechadoEmMs(),
            $keys[8] => $this->getVistoEmMs(),
            $keys[9] => $this->getSincronizadoAteMs(),
            $keys[10] => $this->getPrimeiroDesvioMs(),
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
            if (null !== $this->collContagemPassagems) {

                switch ($keyType) {
                    case TableMap::TYPE_CAMELNAME:
                        $key = 'contagemPassagems';
                        break;
                    case TableMap::TYPE_FIELDNAME:
                        $key = 'contagem_passagems';
                        break;
                    default:
                        $key = 'ContagemPassagems';
                }

                $result[$key] = $this->collContagemPassagems->toArray(null, false, $keyType, $includeLazyLoadColumns, $alreadyDumpedObjects);
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
        $pos = ContagemDispositivoTableMap::translateFieldName($name, $type, TableMap::TYPE_NUM);

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
                $this->setDispositivoId($value);
                break;
            case 1:
                $this->setFonteId($value);
                break;
            case 2:
                $this->setTokenHash($value);
                break;
            case 3:
                $this->setUsuario($value);
                break;
            case 4:
                $this->setRotulo($value);
                break;
            case 5:
                $this->setCriadoEmMs($value);
                break;
            case 6:
                $this->setRevogadoEmMs($value);
                break;
            case 7:
                $this->setFechadoEmMs($value);
                break;
            case 8:
                $this->setVistoEmMs($value);
                break;
            case 9:
                $this->setSincronizadoAteMs($value);
                break;
            case 10:
                $this->setPrimeiroDesvioMs($value);
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
        $keys = ContagemDispositivoTableMap::getFieldNames($keyType);

        if (array_key_exists($keys[0], $arr)) {
            $this->setDispositivoId($arr[$keys[0]]);
        }
        if (array_key_exists($keys[1], $arr)) {
            $this->setFonteId($arr[$keys[1]]);
        }
        if (array_key_exists($keys[2], $arr)) {
            $this->setTokenHash($arr[$keys[2]]);
        }
        if (array_key_exists($keys[3], $arr)) {
            $this->setUsuario($arr[$keys[3]]);
        }
        if (array_key_exists($keys[4], $arr)) {
            $this->setRotulo($arr[$keys[4]]);
        }
        if (array_key_exists($keys[5], $arr)) {
            $this->setCriadoEmMs($arr[$keys[5]]);
        }
        if (array_key_exists($keys[6], $arr)) {
            $this->setRevogadoEmMs($arr[$keys[6]]);
        }
        if (array_key_exists($keys[7], $arr)) {
            $this->setFechadoEmMs($arr[$keys[7]]);
        }
        if (array_key_exists($keys[8], $arr)) {
            $this->setVistoEmMs($arr[$keys[8]]);
        }
        if (array_key_exists($keys[9], $arr)) {
            $this->setSincronizadoAteMs($arr[$keys[9]]);
        }
        if (array_key_exists($keys[10], $arr)) {
            $this->setPrimeiroDesvioMs($arr[$keys[10]]);
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
        $criteria = new Criteria(ContagemDispositivoTableMap::DATABASE_NAME);

        if ($this->isColumnModified(ContagemDispositivoTableMap::COL_DISPOSITIVO_ID)) {
            $criteria->add(ContagemDispositivoTableMap::COL_DISPOSITIVO_ID, $this->dispositivo_id);
        }
        if ($this->isColumnModified(ContagemDispositivoTableMap::COL_FONTE_ID)) {
            $criteria->add(ContagemDispositivoTableMap::COL_FONTE_ID, $this->fonte_id);
        }
        if ($this->isColumnModified(ContagemDispositivoTableMap::COL_TOKEN_HASH)) {
            $criteria->add(ContagemDispositivoTableMap::COL_TOKEN_HASH, $this->token_hash);
        }
        if ($this->isColumnModified(ContagemDispositivoTableMap::COL_USUARIO)) {
            $criteria->add(ContagemDispositivoTableMap::COL_USUARIO, $this->usuario);
        }
        if ($this->isColumnModified(ContagemDispositivoTableMap::COL_ROTULO)) {
            $criteria->add(ContagemDispositivoTableMap::COL_ROTULO, $this->rotulo);
        }
        if ($this->isColumnModified(ContagemDispositivoTableMap::COL_CRIADO_EM_MS)) {
            $criteria->add(ContagemDispositivoTableMap::COL_CRIADO_EM_MS, $this->criado_em_ms);
        }
        if ($this->isColumnModified(ContagemDispositivoTableMap::COL_REVOGADO_EM_MS)) {
            $criteria->add(ContagemDispositivoTableMap::COL_REVOGADO_EM_MS, $this->revogado_em_ms);
        }
        if ($this->isColumnModified(ContagemDispositivoTableMap::COL_FECHADO_EM_MS)) {
            $criteria->add(ContagemDispositivoTableMap::COL_FECHADO_EM_MS, $this->fechado_em_ms);
        }
        if ($this->isColumnModified(ContagemDispositivoTableMap::COL_VISTO_EM_MS)) {
            $criteria->add(ContagemDispositivoTableMap::COL_VISTO_EM_MS, $this->visto_em_ms);
        }
        if ($this->isColumnModified(ContagemDispositivoTableMap::COL_SINCRONIZADO_ATE_MS)) {
            $criteria->add(ContagemDispositivoTableMap::COL_SINCRONIZADO_ATE_MS, $this->sincronizado_ate_ms);
        }
        if ($this->isColumnModified(ContagemDispositivoTableMap::COL_PRIMEIRO_DESVIO_MS)) {
            $criteria->add(ContagemDispositivoTableMap::COL_PRIMEIRO_DESVIO_MS, $this->primeiro_desvio_ms);
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
        $criteria = ChildContagemDispositivoQuery::create();
        $criteria->add(ContagemDispositivoTableMap::COL_DISPOSITIVO_ID, $this->dispositivo_id);

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
        $validPk = null !== $this->getDispositivoId();

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
     * @return int
     */
    public function getPrimaryKey()
    {
        return $this->getDispositivoId();
    }

    /**
     * Generic method to set the primary key (dispositivo_id column).
     *
     * @param int|null $key Primary key.
     * @return void
     */
    public function setPrimaryKey(?int $key = null): void
    {
        $this->setDispositivoId($key);
    }

    /**
     * Returns true if the primary key for this object is null.
     *
     * @return bool
     */
    public function isPrimaryKeyNull(): bool
    {
        return null === $this->getDispositivoId();
    }

    /**
     * Sets contents of passed object to values from current object.
     *
     * If desired, this method can also make copies of all associated (fkey referrers)
     * objects.
     *
     * @param object $copyObj An object of \Baja\Model\ContagemDispositivo (or compatible) type.
     * @param bool $deepCopy Whether to also copy all rows that refer (by fkey) to the current row.
     * @param bool $makeNew Whether to reset autoincrement PKs and make the object new.
     * @throws \Propel\Runtime\Exception\PropelException
     * @return void
     */
    public function copyInto(object $copyObj, bool $deepCopy = false, bool $makeNew = true): void
    {
        $copyObj->setFonteId($this->getFonteId());
        $copyObj->setTokenHash($this->getTokenHash());
        $copyObj->setUsuario($this->getUsuario());
        $copyObj->setRotulo($this->getRotulo());
        $copyObj->setCriadoEmMs($this->getCriadoEmMs());
        $copyObj->setRevogadoEmMs($this->getRevogadoEmMs());
        $copyObj->setFechadoEmMs($this->getFechadoEmMs());
        $copyObj->setVistoEmMs($this->getVistoEmMs());
        $copyObj->setSincronizadoAteMs($this->getSincronizadoAteMs());
        $copyObj->setPrimeiroDesvioMs($this->getPrimeiroDesvioMs());

        if ($deepCopy) {
            // important: temporarily setNew(false) because this affects the behavior of
            // the getter/setter methods for fkey referrer objects.
            $copyObj->setNew(false);

            foreach ($this->getContagemPassagems() as $relObj) {
                if ($relObj !== $this) {  // ensure that we don't try to copy a reference to ourselves
                    $copyObj->addContagemPassagem($relObj->copy($deepCopy));
                }
            }

        } // if ($deepCopy)

        if ($makeNew) {
            $copyObj->setNew(true);
            $copyObj->setDispositivoId(NULL); // this is a auto-increment column, so set to default value
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
     * @return \Baja\Model\ContagemDispositivo Clone of current object.
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
            $v->addContagemDispositivo($this);
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
                $this->aContagemFonte->addContagemDispositivos($this);
             */
        }

        return $this->aContagemFonte;
    }


    /**
     * Initializes a collection based on the name of a relation.
     * Avoids crafting an 'init[$relationName]s' method name
     * that wouldn't work when StandardEnglishPluralizer is used.
     *
     * @param string $relationName The name of the relation to initialize
     * @return void
     */
    public function initRelation($relationName): void
    {
        if ('ContagemPassagem' === $relationName) {
            $this->initContagemPassagems();
            return;
        }
    }

    /**
     * Clears out the collContagemPassagems collection
     *
     * This does not modify the database; however, it will remove any associated objects, causing
     * them to be refetched by subsequent calls to accessor method.
     *
     * @return $this
     * @see addContagemPassagems()
     */
    public function clearContagemPassagems()
    {
        $this->collContagemPassagems = null; // important to set this to NULL since that means it is uninitialized

        return $this;
    }

    /**
     * Reset is the collContagemPassagems collection loaded partially.
     *
     * @return void
     */
    public function resetPartialContagemPassagems($v = true): void
    {
        $this->collContagemPassagemsPartial = $v;
    }

    /**
     * Initializes the collContagemPassagems collection.
     *
     * By default this just sets the collContagemPassagems collection to an empty array (like clearcollContagemPassagems());
     * however, you may wish to override this method in your stub class to provide setting appropriate
     * to your application -- for example, setting the initial array to the values stored in database.
     *
     * @param bool $overrideExisting If set to true, the method call initializes
     *                                        the collection even if it is not empty
     *
     * @return void
     */
    public function initContagemPassagems(bool $overrideExisting = true): void
    {
        if (null !== $this->collContagemPassagems && !$overrideExisting) {
            return;
        }

        $collectionClassName = ContagemPassagemTableMap::getTableMap()->getCollectionClassName();

        $this->collContagemPassagems = new $collectionClassName;
        $this->collContagemPassagems->setModel('\Baja\Model\ContagemPassagem');
    }

    /**
     * Gets an array of ChildContagemPassagem objects which contain a foreign key that references this object.
     *
     * If the $criteria is not null, it is used to always fetch the results from the database.
     * Otherwise the results are fetched from the database the first time, then cached.
     * Next time the same method is called without $criteria, the cached collection is returned.
     * If this ChildContagemDispositivo is new, it will return
     * an empty collection or the current collection; the criteria is ignored on a new object.
     *
     * @param Criteria $criteria optional Criteria object to narrow the query
     * @param ConnectionInterface $con optional connection object
     * @return ObjectCollection|ChildContagemPassagem[] List of ChildContagemPassagem objects
     * @phpstan-return ObjectCollection&\Traversable<ChildContagemPassagem> List of ChildContagemPassagem objects
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function getContagemPassagems(?Criteria $criteria = null, ?ConnectionInterface $con = null)
    {
        $partial = $this->collContagemPassagemsPartial && !$this->isNew();
        if (null === $this->collContagemPassagems || null !== $criteria || $partial) {
            if ($this->isNew()) {
                // return empty collection
                if (null === $this->collContagemPassagems) {
                    $this->initContagemPassagems();
                } else {
                    $collectionClassName = ContagemPassagemTableMap::getTableMap()->getCollectionClassName();

                    $collContagemPassagems = new $collectionClassName;
                    $collContagemPassagems->setModel('\Baja\Model\ContagemPassagem');

                    return $collContagemPassagems;
                }
            } else {
                $collContagemPassagems = ChildContagemPassagemQuery::create(null, $criteria)
                    ->filterByContagemDispositivo($this)
                    ->find($con);

                if (null !== $criteria) {
                    if (false !== $this->collContagemPassagemsPartial && count($collContagemPassagems)) {
                        $this->initContagemPassagems(false);

                        foreach ($collContagemPassagems as $obj) {
                            if (false == $this->collContagemPassagems->contains($obj)) {
                                $this->collContagemPassagems->append($obj);
                            }
                        }

                        $this->collContagemPassagemsPartial = true;
                    }

                    return $collContagemPassagems;
                }

                if ($partial && $this->collContagemPassagems) {
                    foreach ($this->collContagemPassagems as $obj) {
                        if ($obj->isNew()) {
                            $collContagemPassagems[] = $obj;
                        }
                    }
                }

                $this->collContagemPassagems = $collContagemPassagems;
                $this->collContagemPassagemsPartial = false;
            }
        }

        return $this->collContagemPassagems;
    }

    /**
     * Sets a collection of ChildContagemPassagem objects related by a one-to-many relationship
     * to the current object.
     * It will also schedule objects for deletion based on a diff between old objects (aka persisted)
     * and new objects from the given Propel collection.
     *
     * @param Collection $contagemPassagems A Propel collection.
     * @param ConnectionInterface $con Optional connection object
     * @return $this The current object (for fluent API support)
     */
    public function setContagemPassagems(Collection $contagemPassagems, ?ConnectionInterface $con = null)
    {
        /** @var ChildContagemPassagem[] $contagemPassagemsToDelete */
        $contagemPassagemsToDelete = $this->getContagemPassagems(new Criteria(), $con)->diff($contagemPassagems);


        $this->contagemPassagemsScheduledForDeletion = $contagemPassagemsToDelete;

        foreach ($contagemPassagemsToDelete as $contagemPassagemRemoved) {
            $contagemPassagemRemoved->setContagemDispositivo(null);
        }

        $this->collContagemPassagems = null;
        foreach ($contagemPassagems as $contagemPassagem) {
            $this->addContagemPassagem($contagemPassagem);
        }

        $this->collContagemPassagems = $contagemPassagems;
        $this->collContagemPassagemsPartial = false;

        return $this;
    }

    /**
     * Returns the number of related ContagemPassagem objects.
     *
     * @param Criteria $criteria
     * @param bool $distinct
     * @param ConnectionInterface $con
     * @return int Count of related ContagemPassagem objects.
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function countContagemPassagems(?Criteria $criteria = null, bool $distinct = false, ?ConnectionInterface $con = null): int
    {
        $partial = $this->collContagemPassagemsPartial && !$this->isNew();
        if (null === $this->collContagemPassagems || null !== $criteria || $partial) {
            if ($this->isNew() && null === $this->collContagemPassagems) {
                return 0;
            }

            if ($partial && !$criteria) {
                return count($this->getContagemPassagems());
            }

            $query = ChildContagemPassagemQuery::create(null, $criteria);
            if ($distinct) {
                $query->distinct();
            }

            return $query
                ->filterByContagemDispositivo($this)
                ->count($con);
        }

        return count($this->collContagemPassagems);
    }

    /**
     * Method called to associate a ChildContagemPassagem object to this object
     * through the ChildContagemPassagem foreign key attribute.
     *
     * @param ChildContagemPassagem $l ChildContagemPassagem
     * @return $this The current object (for fluent API support)
     */
    public function addContagemPassagem(ChildContagemPassagem $l)
    {
        if ($this->collContagemPassagems === null) {
            $this->initContagemPassagems();
            $this->collContagemPassagemsPartial = true;
        }

        if (!$this->collContagemPassagems->contains($l)) {
            $this->doAddContagemPassagem($l);

            if ($this->contagemPassagemsScheduledForDeletion and $this->contagemPassagemsScheduledForDeletion->contains($l)) {
                $this->contagemPassagemsScheduledForDeletion->remove($this->contagemPassagemsScheduledForDeletion->search($l));
            }
        }

        return $this;
    }

    /**
     * @param ChildContagemPassagem $contagemPassagem The ChildContagemPassagem object to add.
     */
    protected function doAddContagemPassagem(ChildContagemPassagem $contagemPassagem): void
    {
        $this->collContagemPassagems[]= $contagemPassagem;
        $contagemPassagem->setContagemDispositivo($this);
    }

    /**
     * @param ChildContagemPassagem $contagemPassagem The ChildContagemPassagem object to remove.
     * @return $this The current object (for fluent API support)
     */
    public function removeContagemPassagem(ChildContagemPassagem $contagemPassagem)
    {
        if ($this->getContagemPassagems()->contains($contagemPassagem)) {
            $pos = $this->collContagemPassagems->search($contagemPassagem);
            $this->collContagemPassagems->remove($pos);
            if (null === $this->contagemPassagemsScheduledForDeletion) {
                $this->contagemPassagemsScheduledForDeletion = clone $this->collContagemPassagems;
                $this->contagemPassagemsScheduledForDeletion->clear();
            }
            $this->contagemPassagemsScheduledForDeletion[]= clone $contagemPassagem;
            $contagemPassagem->setContagemDispositivo(null);
        }

        return $this;
    }


    /**
     * If this collection has already been initialized with
     * an identical criteria, it returns the collection.
     * Otherwise if this ContagemDispositivo is new, it will return
     * an empty collection; or if this ContagemDispositivo has previously
     * been saved, it will retrieve related ContagemPassagems from storage.
     *
     * This method is protected by default in order to keep the public
     * api reasonable.  You can provide public methods for those you
     * actually need in ContagemDispositivo.
     *
     * @param Criteria $criteria optional Criteria object to narrow the query
     * @param ConnectionInterface $con optional connection object
     * @param string $joinBehavior optional join type to use (defaults to Criteria::LEFT_JOIN)
     * @return ObjectCollection|ChildContagemPassagem[] List of ChildContagemPassagem objects
     * @phpstan-return ObjectCollection&\Traversable<ChildContagemPassagem}> List of ChildContagemPassagem objects
     */
    public function getContagemPassagemsJoinContagemFonte(?Criteria $criteria = null, ?ConnectionInterface $con = null, $joinBehavior = Criteria::LEFT_JOIN)
    {
        $query = ChildContagemPassagemQuery::create(null, $criteria);
        $query->joinWith('ContagemFonte', $joinBehavior);

        return $this->getContagemPassagems($query, $con);
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
            $this->aContagemFonte->removeContagemDispositivo($this);
        }
        $this->dispositivo_id = null;
        $this->fonte_id = null;
        $this->token_hash = null;
        $this->usuario = null;
        $this->rotulo = null;
        $this->criado_em_ms = null;
        $this->revogado_em_ms = null;
        $this->fechado_em_ms = null;
        $this->visto_em_ms = null;
        $this->sincronizado_ate_ms = null;
        $this->primeiro_desvio_ms = null;
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
            if ($this->collContagemPassagems) {
                foreach ($this->collContagemPassagems as $o) {
                    $o->clearAllReferences($deep);
                }
            }
        } // if ($deep)

        $this->collContagemPassagems = null;
        $this->aContagemFonte = null;
        return $this;
    }

    /**
     * Return the string representation of this object
     *
     * @return string
     */
    public function __toString()
    {
        return (string) $this->exportTo(ContagemDispositivoTableMap::DEFAULT_STRING_FORMAT);
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
