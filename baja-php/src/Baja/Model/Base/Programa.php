<?php

namespace Baja\Model\Base;

use \Exception;
use \PDO;
use Baja\Model\Evento as ChildEvento;
use Baja\Model\EventoQuery as ChildEventoQuery;
use Baja\Model\Programa as ChildPrograma;
use Baja\Model\ProgramaQuery as ChildProgramaQuery;
use Baja\Model\Map\EventoTableMap;
use Baja\Model\Map\ProgramaTableMap;
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
 * Base class that represents a row from the 'programa' table.
 *
 *
 *
 * @package    propel.generator.Baja.Model.Base
 */
abstract class Programa implements ActiveRecordInterface
{
    /**
     * TableMap class name
     *
     * @var string
     */
    public const TABLE_MAP = '\\Baja\\Model\\Map\\ProgramaTableMap';


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
     * The value for the programa_id field.
     *
     * @var        string
     */
    protected $programa_id;

    /**
     * The value for the parent_id field.
     *
     * @var        string|null
     */
    protected $parent_id;

    /**
     * The value for the nome field.
     *
     * @var        string
     */
    protected $nome;

    /**
     * The value for the ordem field.
     *
     * Note: this column has a database default value of: 0
     * @var        int
     */
    protected $ordem;

    /**
     * The value for the ativo field.
     *
     * Note: this column has a database default value of: true
     * @var        boolean
     */
    protected $ativo;

    /**
     * @var        ChildPrograma
     */
    protected $aParent;

    /**
     * @var        ObjectCollection|ChildEvento[] Collection to store aggregation of ChildEvento objects.
     * @phpstan-var ObjectCollection&\Traversable<ChildEvento> Collection to store aggregation of ChildEvento objects.
     */
    protected $collEventos;
    protected $collEventosPartial;

    /**
     * @var        ObjectCollection|ChildPrograma[] Collection to store aggregation of ChildPrograma objects.
     * @phpstan-var ObjectCollection&\Traversable<ChildPrograma> Collection to store aggregation of ChildPrograma objects.
     */
    protected $collChildren;
    protected $collChildrenPartial;

    /**
     * Flag to prevent endless save loop, if this object is referenced
     * by another object which falls in this transaction.
     *
     * @var bool
     */
    protected $alreadyInSave = false;

    /**
     * An array of objects scheduled for deletion.
     * @var ObjectCollection|ChildEvento[]
     * @phpstan-var ObjectCollection&\Traversable<ChildEvento>
     */
    protected $eventosScheduledForDeletion = null;

    /**
     * An array of objects scheduled for deletion.
     * @var ObjectCollection|ChildPrograma[]
     * @phpstan-var ObjectCollection&\Traversable<ChildPrograma>
     */
    protected $childrenScheduledForDeletion = null;

    /**
     * Applies default values to this object.
     * This method should be called from the object's constructor (or
     * equivalent initialization method).
     * @see __construct()
     */
    public function applyDefaultValues(): void
    {
        $this->ordem = 0;
        $this->ativo = true;
    }

    /**
     * Initializes internal state of Baja\Model\Base\Programa object.
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
     * Compares this with another <code>Programa</code> instance.  If
     * <code>obj</code> is an instance of <code>Programa</code>, delegates to
     * <code>equals(Programa)</code>.  Otherwise, returns <code>false</code>.
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
     * Get the [programa_id] column value.
     *
     * @return string
     */
    public function getProgramaId()
    {
        return $this->programa_id;
    }

    /**
     * Get the [parent_id] column value.
     *
     * @return string|null
     */
    public function getParentId()
    {
        return $this->parent_id;
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
     * Get the [ordem] column value.
     *
     * @return int
     */
    public function getOrdem()
    {
        return $this->ordem;
    }

    /**
     * Get the [ativo] column value.
     *
     * @return boolean
     */
    public function getAtivo()
    {
        return $this->ativo;
    }

    /**
     * Get the [ativo] column value.
     *
     * @return boolean
     */
    public function isAtivo()
    {
        return $this->getAtivo();
    }

    /**
     * Set the value of [programa_id] column.
     *
     * @param string $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setProgramaId($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->programa_id !== $v) {
            $this->programa_id = $v;
            $this->modifiedColumns[ProgramaTableMap::COL_PROGRAMA_ID] = true;
        }

        return $this;
    }

    /**
     * Set the value of [parent_id] column.
     *
     * @param string|null $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setParentId($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->parent_id !== $v) {
            $this->parent_id = $v;
            $this->modifiedColumns[ProgramaTableMap::COL_PARENT_ID] = true;
        }

        if ($this->aParent !== null && $this->aParent->getProgramaId() !== $v) {
            $this->aParent = null;
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
            $this->modifiedColumns[ProgramaTableMap::COL_NOME] = true;
        }

        return $this;
    }

    /**
     * Set the value of [ordem] column.
     *
     * @param int $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setOrdem($v)
    {
        if ($v !== null) {
            $v = (int) $v;
        }

        if ($this->ordem !== $v) {
            $this->ordem = $v;
            $this->modifiedColumns[ProgramaTableMap::COL_ORDEM] = true;
        }

        return $this;
    }

    /**
     * Sets the value of the [ativo] column.
     * Non-boolean arguments are converted using the following rules:
     *   * 1, '1', 'true',  'on',  and 'yes' are converted to boolean true
     *   * 0, '0', 'false', 'off', and 'no'  are converted to boolean false
     * Check on string values is case insensitive (so 'FaLsE' is seen as 'false').
     *
     * @param bool|integer|string $v The new value
     * @return $this The current object (for fluent API support)
     */
    public function setAtivo($v)
    {
        if ($v !== null) {
            if (is_string($v)) {
                $v = in_array(strtolower($v), array('false', 'off', '-', 'no', 'n', '0', '')) ? false : true;
            } else {
                $v = (boolean) $v;
            }
        }

        if ($this->ativo !== $v) {
            $this->ativo = $v;
            $this->modifiedColumns[ProgramaTableMap::COL_ATIVO] = true;
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
            if ($this->ordem !== 0) {
                return false;
            }

            if ($this->ativo !== true) {
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

            $col = $row[TableMap::TYPE_NUM == $indexType ? 0 + $startcol : ProgramaTableMap::translateFieldName('ProgramaId', TableMap::TYPE_PHPNAME, $indexType)];
            $this->programa_id = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 1 + $startcol : ProgramaTableMap::translateFieldName('ParentId', TableMap::TYPE_PHPNAME, $indexType)];
            $this->parent_id = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 2 + $startcol : ProgramaTableMap::translateFieldName('Nome', TableMap::TYPE_PHPNAME, $indexType)];
            $this->nome = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 3 + $startcol : ProgramaTableMap::translateFieldName('Ordem', TableMap::TYPE_PHPNAME, $indexType)];
            $this->ordem = (null !== $col) ? (int) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 4 + $startcol : ProgramaTableMap::translateFieldName('Ativo', TableMap::TYPE_PHPNAME, $indexType)];
            $this->ativo = (null !== $col) ? (boolean) $col : null;

            $this->resetModified();
            $this->setNew(false);

            if ($rehydrate) {
                $this->ensureConsistency();
            }

            return $startcol + 5; // 5 = ProgramaTableMap::NUM_HYDRATE_COLUMNS.

        } catch (Exception $e) {
            throw new PropelException(sprintf('Error populating %s object', '\\Baja\\Model\\Programa'), 0, $e);
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
        if ($this->aParent !== null && $this->parent_id !== $this->aParent->getProgramaId()) {
            $this->aParent = null;
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
            $con = Propel::getServiceContainer()->getReadConnection(ProgramaTableMap::DATABASE_NAME);
        }

        // We don't need to alter the object instance pool; we're just modifying this instance
        // already in the pool.

        $dataFetcher = ChildProgramaQuery::create(null, $this->buildPkeyCriteria())->setFormatter(ModelCriteria::FORMAT_STATEMENT)->find($con);
        $row = $dataFetcher->fetch();
        $dataFetcher->close();
        if (!$row) {
            throw new PropelException('Cannot find matching row in the database to reload object values.');
        }
        $this->hydrate($row, 0, true, $dataFetcher->getIndexType()); // rehydrate

        if ($deep) {  // also de-associate any related objects?

            $this->aParent = null;
            $this->collEventos = null;

            $this->collChildren = null;

        } // if (deep)
    }

    /**
     * Removes this object from datastore and sets delete attribute.
     *
     * @param ConnectionInterface $con
     * @return void
     * @throws \Propel\Runtime\Exception\PropelException
     * @see Programa::setDeleted()
     * @see Programa::isDeleted()
     */
    public function delete(?ConnectionInterface $con = null): void
    {
        if ($this->isDeleted()) {
            throw new PropelException("This object has already been deleted.");
        }

        if ($con === null) {
            $con = Propel::getServiceContainer()->getWriteConnection(ProgramaTableMap::DATABASE_NAME);
        }

        $con->transaction(function () use ($con) {
            $deleteQuery = ChildProgramaQuery::create()
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
            $con = Propel::getServiceContainer()->getWriteConnection(ProgramaTableMap::DATABASE_NAME);
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
                ProgramaTableMap::addInstanceToPool($this);
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

            if ($this->aParent !== null) {
                if ($this->aParent->isModified() || $this->aParent->isNew()) {
                    $affectedRows += $this->aParent->save($con);
                }
                $this->setParent($this->aParent);
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

            if ($this->eventosScheduledForDeletion !== null) {
                if (!$this->eventosScheduledForDeletion->isEmpty()) {
                    \Baja\Model\EventoQuery::create()
                        ->filterByPrimaryKeys($this->eventosScheduledForDeletion->getPrimaryKeys(false))
                        ->delete($con);
                    $this->eventosScheduledForDeletion = null;
                }
            }

            if ($this->collEventos !== null) {
                foreach ($this->collEventos as $referrerFK) {
                    if (!$referrerFK->isDeleted() && ($referrerFK->isNew() || $referrerFK->isModified())) {
                        $affectedRows += $referrerFK->save($con);
                    }
                }
            }

            if ($this->childrenScheduledForDeletion !== null) {
                if (!$this->childrenScheduledForDeletion->isEmpty()) {
                    foreach ($this->childrenScheduledForDeletion as $child) {
                        // need to save related object because we set the relation to null
                        $child->save($con);
                    }
                    $this->childrenScheduledForDeletion = null;
                }
            }

            if ($this->collChildren !== null) {
                foreach ($this->collChildren as $referrerFK) {
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


         // check the columns in natural order for more readable SQL queries
        if ($this->isColumnModified(ProgramaTableMap::COL_PROGRAMA_ID)) {
            $modifiedColumns[':p' . $index++]  = 'programa_id';
        }
        if ($this->isColumnModified(ProgramaTableMap::COL_PARENT_ID)) {
            $modifiedColumns[':p' . $index++]  = 'parent_id';
        }
        if ($this->isColumnModified(ProgramaTableMap::COL_NOME)) {
            $modifiedColumns[':p' . $index++]  = 'nome';
        }
        if ($this->isColumnModified(ProgramaTableMap::COL_ORDEM)) {
            $modifiedColumns[':p' . $index++]  = 'ordem';
        }
        if ($this->isColumnModified(ProgramaTableMap::COL_ATIVO)) {
            $modifiedColumns[':p' . $index++]  = 'ativo';
        }

        $sql = sprintf(
            'INSERT INTO programa (%s) VALUES (%s)',
            implode(', ', $modifiedColumns),
            implode(', ', array_keys($modifiedColumns))
        );

        try {
            $stmt = $con->prepare($sql);
            foreach ($modifiedColumns as $identifier => $columnName) {
                switch ($columnName) {
                    case 'programa_id':
                        $stmt->bindValue($identifier, $this->programa_id, PDO::PARAM_STR);

                        break;
                    case 'parent_id':
                        $stmt->bindValue($identifier, $this->parent_id, PDO::PARAM_STR);

                        break;
                    case 'nome':
                        $stmt->bindValue($identifier, $this->nome, PDO::PARAM_STR);

                        break;
                    case 'ordem':
                        $stmt->bindValue($identifier, $this->ordem, PDO::PARAM_INT);

                        break;
                    case 'ativo':
                        $stmt->bindValue($identifier, (int) $this->ativo, PDO::PARAM_INT);

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
        $pos = ProgramaTableMap::translateFieldName($name, $type, TableMap::TYPE_NUM);
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
                return $this->getProgramaId();

            case 1:
                return $this->getParentId();

            case 2:
                return $this->getNome();

            case 3:
                return $this->getOrdem();

            case 4:
                return $this->getAtivo();

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
        if (isset($alreadyDumpedObjects['Programa'][$this->hashCode()])) {
            return ['*RECURSION*'];
        }
        $alreadyDumpedObjects['Programa'][$this->hashCode()] = true;
        $keys = ProgramaTableMap::getFieldNames($keyType);
        $result = [
            $keys[0] => $this->getProgramaId(),
            $keys[1] => $this->getParentId(),
            $keys[2] => $this->getNome(),
            $keys[3] => $this->getOrdem(),
            $keys[4] => $this->getAtivo(),
        ];
        $virtualColumns = $this->virtualColumns;
        foreach ($virtualColumns as $key => $virtualColumn) {
            $result[$key] = $virtualColumn;
        }

        if ($includeForeignObjects) {
            if (null !== $this->aParent) {

                switch ($keyType) {
                    case TableMap::TYPE_CAMELNAME:
                        $key = 'programa';
                        break;
                    case TableMap::TYPE_FIELDNAME:
                        $key = 'programa';
                        break;
                    default:
                        $key = 'Parent';
                }

                $result[$key] = $this->aParent->toArray($keyType, $includeLazyLoadColumns,  $alreadyDumpedObjects, true);
            }
            if (null !== $this->collEventos) {

                switch ($keyType) {
                    case TableMap::TYPE_CAMELNAME:
                        $key = 'eventos';
                        break;
                    case TableMap::TYPE_FIELDNAME:
                        $key = 'eventos';
                        break;
                    default:
                        $key = 'Eventos';
                }

                $result[$key] = $this->collEventos->toArray(null, false, $keyType, $includeLazyLoadColumns, $alreadyDumpedObjects);
            }
            if (null !== $this->collChildren) {

                switch ($keyType) {
                    case TableMap::TYPE_CAMELNAME:
                        $key = 'programas';
                        break;
                    case TableMap::TYPE_FIELDNAME:
                        $key = 'programas';
                        break;
                    default:
                        $key = 'Children';
                }

                $result[$key] = $this->collChildren->toArray(null, false, $keyType, $includeLazyLoadColumns, $alreadyDumpedObjects);
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
        $pos = ProgramaTableMap::translateFieldName($name, $type, TableMap::TYPE_NUM);

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
                $this->setProgramaId($value);
                break;
            case 1:
                $this->setParentId($value);
                break;
            case 2:
                $this->setNome($value);
                break;
            case 3:
                $this->setOrdem($value);
                break;
            case 4:
                $this->setAtivo($value);
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
        $keys = ProgramaTableMap::getFieldNames($keyType);

        if (array_key_exists($keys[0], $arr)) {
            $this->setProgramaId($arr[$keys[0]]);
        }
        if (array_key_exists($keys[1], $arr)) {
            $this->setParentId($arr[$keys[1]]);
        }
        if (array_key_exists($keys[2], $arr)) {
            $this->setNome($arr[$keys[2]]);
        }
        if (array_key_exists($keys[3], $arr)) {
            $this->setOrdem($arr[$keys[3]]);
        }
        if (array_key_exists($keys[4], $arr)) {
            $this->setAtivo($arr[$keys[4]]);
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
        $criteria = new Criteria(ProgramaTableMap::DATABASE_NAME);

        if ($this->isColumnModified(ProgramaTableMap::COL_PROGRAMA_ID)) {
            $criteria->add(ProgramaTableMap::COL_PROGRAMA_ID, $this->programa_id);
        }
        if ($this->isColumnModified(ProgramaTableMap::COL_PARENT_ID)) {
            $criteria->add(ProgramaTableMap::COL_PARENT_ID, $this->parent_id);
        }
        if ($this->isColumnModified(ProgramaTableMap::COL_NOME)) {
            $criteria->add(ProgramaTableMap::COL_NOME, $this->nome);
        }
        if ($this->isColumnModified(ProgramaTableMap::COL_ORDEM)) {
            $criteria->add(ProgramaTableMap::COL_ORDEM, $this->ordem);
        }
        if ($this->isColumnModified(ProgramaTableMap::COL_ATIVO)) {
            $criteria->add(ProgramaTableMap::COL_ATIVO, $this->ativo);
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
        $criteria = ChildProgramaQuery::create();
        $criteria->add(ProgramaTableMap::COL_PROGRAMA_ID, $this->programa_id);

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
        $validPk = null !== $this->getProgramaId();

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
        return $this->getProgramaId();
    }

    /**
     * Generic method to set the primary key (programa_id column).
     *
     * @param string|null $key Primary key.
     * @return void
     */
    public function setPrimaryKey(?string $key = null): void
    {
        $this->setProgramaId($key);
    }

    /**
     * Returns true if the primary key for this object is null.
     *
     * @return bool
     */
    public function isPrimaryKeyNull(): bool
    {
        return null === $this->getProgramaId();
    }

    /**
     * Sets contents of passed object to values from current object.
     *
     * If desired, this method can also make copies of all associated (fkey referrers)
     * objects.
     *
     * @param object $copyObj An object of \Baja\Model\Programa (or compatible) type.
     * @param bool $deepCopy Whether to also copy all rows that refer (by fkey) to the current row.
     * @param bool $makeNew Whether to reset autoincrement PKs and make the object new.
     * @throws \Propel\Runtime\Exception\PropelException
     * @return void
     */
    public function copyInto(object $copyObj, bool $deepCopy = false, bool $makeNew = true): void
    {
        $copyObj->setProgramaId($this->getProgramaId());
        $copyObj->setParentId($this->getParentId());
        $copyObj->setNome($this->getNome());
        $copyObj->setOrdem($this->getOrdem());
        $copyObj->setAtivo($this->getAtivo());

        if ($deepCopy) {
            // important: temporarily setNew(false) because this affects the behavior of
            // the getter/setter methods for fkey referrer objects.
            $copyObj->setNew(false);

            foreach ($this->getEventos() as $relObj) {
                if ($relObj !== $this) {  // ensure that we don't try to copy a reference to ourselves
                    $copyObj->addEvento($relObj->copy($deepCopy));
                }
            }

            foreach ($this->getChildren() as $relObj) {
                if ($relObj !== $this) {  // ensure that we don't try to copy a reference to ourselves
                    $copyObj->addChild($relObj->copy($deepCopy));
                }
            }

        } // if ($deepCopy)

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
     * @return \Baja\Model\Programa Clone of current object.
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
     * Declares an association between this object and a ChildPrograma object.
     *
     * @param ChildPrograma|null $v
     * @return $this The current object (for fluent API support)
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function setParent(ChildPrograma $v = null)
    {
        if ($v === null) {
            $this->setParentId(NULL);
        } else {
            $this->setParentId($v->getProgramaId());
        }

        $this->aParent = $v;

        // Add binding for other direction of this n:n relationship.
        // If this object has already been added to the ChildPrograma object, it will not be re-added.
        if ($v !== null) {
            $v->addChild($this);
        }


        return $this;
    }


    /**
     * Get the associated ChildPrograma object
     *
     * @param ConnectionInterface $con Optional Connection object.
     * @return ChildPrograma|null The associated ChildPrograma object.
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function getParent(?ConnectionInterface $con = null)
    {
        if ($this->aParent === null && (($this->parent_id !== "" && $this->parent_id !== null))) {
            $this->aParent = ChildProgramaQuery::create()->findPk($this->parent_id, $con);
            /* The following can be used additionally to
                guarantee the related object contains a reference
                to this object.  This level of coupling may, however, be
                undesirable since it could result in an only partially populated collection
                in the referenced object.
                $this->aParent->addChildren($this);
             */
        }

        return $this->aParent;
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
        if ('Evento' === $relationName) {
            $this->initEventos();
            return;
        }
        if ('Child' === $relationName) {
            $this->initChildren();
            return;
        }
    }

    /**
     * Clears out the collEventos collection
     *
     * This does not modify the database; however, it will remove any associated objects, causing
     * them to be refetched by subsequent calls to accessor method.
     *
     * @return $this
     * @see addEventos()
     */
    public function clearEventos()
    {
        $this->collEventos = null; // important to set this to NULL since that means it is uninitialized

        return $this;
    }

    /**
     * Reset is the collEventos collection loaded partially.
     *
     * @return void
     */
    public function resetPartialEventos($v = true): void
    {
        $this->collEventosPartial = $v;
    }

    /**
     * Initializes the collEventos collection.
     *
     * By default this just sets the collEventos collection to an empty array (like clearcollEventos());
     * however, you may wish to override this method in your stub class to provide setting appropriate
     * to your application -- for example, setting the initial array to the values stored in database.
     *
     * @param bool $overrideExisting If set to true, the method call initializes
     *                                        the collection even if it is not empty
     *
     * @return void
     */
    public function initEventos(bool $overrideExisting = true): void
    {
        if (null !== $this->collEventos && !$overrideExisting) {
            return;
        }

        $collectionClassName = EventoTableMap::getTableMap()->getCollectionClassName();

        $this->collEventos = new $collectionClassName;
        $this->collEventos->setModel('\Baja\Model\Evento');
    }

    /**
     * Gets an array of ChildEvento objects which contain a foreign key that references this object.
     *
     * If the $criteria is not null, it is used to always fetch the results from the database.
     * Otherwise the results are fetched from the database the first time, then cached.
     * Next time the same method is called without $criteria, the cached collection is returned.
     * If this ChildPrograma is new, it will return
     * an empty collection or the current collection; the criteria is ignored on a new object.
     *
     * @param Criteria $criteria optional Criteria object to narrow the query
     * @param ConnectionInterface $con optional connection object
     * @return ObjectCollection|ChildEvento[] List of ChildEvento objects
     * @phpstan-return ObjectCollection&\Traversable<ChildEvento> List of ChildEvento objects
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function getEventos(?Criteria $criteria = null, ?ConnectionInterface $con = null)
    {
        $partial = $this->collEventosPartial && !$this->isNew();
        if (null === $this->collEventos || null !== $criteria || $partial) {
            if ($this->isNew()) {
                // return empty collection
                if (null === $this->collEventos) {
                    $this->initEventos();
                } else {
                    $collectionClassName = EventoTableMap::getTableMap()->getCollectionClassName();

                    $collEventos = new $collectionClassName;
                    $collEventos->setModel('\Baja\Model\Evento');

                    return $collEventos;
                }
            } else {
                $collEventos = ChildEventoQuery::create(null, $criteria)
                    ->filterByPrograma($this)
                    ->find($con);

                if (null !== $criteria) {
                    if (false !== $this->collEventosPartial && count($collEventos)) {
                        $this->initEventos(false);

                        foreach ($collEventos as $obj) {
                            if (false == $this->collEventos->contains($obj)) {
                                $this->collEventos->append($obj);
                            }
                        }

                        $this->collEventosPartial = true;
                    }

                    return $collEventos;
                }

                if ($partial && $this->collEventos) {
                    foreach ($this->collEventos as $obj) {
                        if ($obj->isNew()) {
                            $collEventos[] = $obj;
                        }
                    }
                }

                $this->collEventos = $collEventos;
                $this->collEventosPartial = false;
            }
        }

        return $this->collEventos;
    }

    /**
     * Sets a collection of ChildEvento objects related by a one-to-many relationship
     * to the current object.
     * It will also schedule objects for deletion based on a diff between old objects (aka persisted)
     * and new objects from the given Propel collection.
     *
     * @param Collection $eventos A Propel collection.
     * @param ConnectionInterface $con Optional connection object
     * @return $this The current object (for fluent API support)
     */
    public function setEventos(Collection $eventos, ?ConnectionInterface $con = null)
    {
        /** @var ChildEvento[] $eventosToDelete */
        $eventosToDelete = $this->getEventos(new Criteria(), $con)->diff($eventos);


        $this->eventosScheduledForDeletion = $eventosToDelete;

        foreach ($eventosToDelete as $eventoRemoved) {
            $eventoRemoved->setPrograma(null);
        }

        $this->collEventos = null;
        foreach ($eventos as $evento) {
            $this->addEvento($evento);
        }

        $this->collEventos = $eventos;
        $this->collEventosPartial = false;

        return $this;
    }

    /**
     * Returns the number of related Evento objects.
     *
     * @param Criteria $criteria
     * @param bool $distinct
     * @param ConnectionInterface $con
     * @return int Count of related Evento objects.
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function countEventos(?Criteria $criteria = null, bool $distinct = false, ?ConnectionInterface $con = null): int
    {
        $partial = $this->collEventosPartial && !$this->isNew();
        if (null === $this->collEventos || null !== $criteria || $partial) {
            if ($this->isNew() && null === $this->collEventos) {
                return 0;
            }

            if ($partial && !$criteria) {
                return count($this->getEventos());
            }

            $query = ChildEventoQuery::create(null, $criteria);
            if ($distinct) {
                $query->distinct();
            }

            return $query
                ->filterByPrograma($this)
                ->count($con);
        }

        return count($this->collEventos);
    }

    /**
     * Method called to associate a ChildEvento object to this object
     * through the ChildEvento foreign key attribute.
     *
     * @param ChildEvento $l ChildEvento
     * @return $this The current object (for fluent API support)
     */
    public function addEvento(ChildEvento $l)
    {
        if ($this->collEventos === null) {
            $this->initEventos();
            $this->collEventosPartial = true;
        }

        if (!$this->collEventos->contains($l)) {
            $this->doAddEvento($l);

            if ($this->eventosScheduledForDeletion and $this->eventosScheduledForDeletion->contains($l)) {
                $this->eventosScheduledForDeletion->remove($this->eventosScheduledForDeletion->search($l));
            }
        }

        return $this;
    }

    /**
     * @param ChildEvento $evento The ChildEvento object to add.
     */
    protected function doAddEvento(ChildEvento $evento): void
    {
        $this->collEventos[]= $evento;
        $evento->setPrograma($this);
    }

    /**
     * @param ChildEvento $evento The ChildEvento object to remove.
     * @return $this The current object (for fluent API support)
     */
    public function removeEvento(ChildEvento $evento)
    {
        if ($this->getEventos()->contains($evento)) {
            $pos = $this->collEventos->search($evento);
            $this->collEventos->remove($pos);
            if (null === $this->eventosScheduledForDeletion) {
                $this->eventosScheduledForDeletion = clone $this->collEventos;
                $this->eventosScheduledForDeletion->clear();
            }
            $this->eventosScheduledForDeletion[]= clone $evento;
            $evento->setPrograma(null);
        }

        return $this;
    }

    /**
     * Clears out the collChildren collection
     *
     * This does not modify the database; however, it will remove any associated objects, causing
     * them to be refetched by subsequent calls to accessor method.
     *
     * @return $this
     * @see addChildren()
     */
    public function clearChildren()
    {
        $this->collChildren = null; // important to set this to NULL since that means it is uninitialized

        return $this;
    }

    /**
     * Reset is the collChildren collection loaded partially.
     *
     * @return void
     */
    public function resetPartialChildren($v = true): void
    {
        $this->collChildrenPartial = $v;
    }

    /**
     * Initializes the collChildren collection.
     *
     * By default this just sets the collChildren collection to an empty array (like clearcollChildren());
     * however, you may wish to override this method in your stub class to provide setting appropriate
     * to your application -- for example, setting the initial array to the values stored in database.
     *
     * @param bool $overrideExisting If set to true, the method call initializes
     *                                        the collection even if it is not empty
     *
     * @return void
     */
    public function initChildren(bool $overrideExisting = true): void
    {
        if (null !== $this->collChildren && !$overrideExisting) {
            return;
        }

        $collectionClassName = ProgramaTableMap::getTableMap()->getCollectionClassName();

        $this->collChildren = new $collectionClassName;
        $this->collChildren->setModel('\Baja\Model\Programa');
    }

    /**
     * Gets an array of ChildPrograma objects which contain a foreign key that references this object.
     *
     * If the $criteria is not null, it is used to always fetch the results from the database.
     * Otherwise the results are fetched from the database the first time, then cached.
     * Next time the same method is called without $criteria, the cached collection is returned.
     * If this ChildPrograma is new, it will return
     * an empty collection or the current collection; the criteria is ignored on a new object.
     *
     * @param Criteria $criteria optional Criteria object to narrow the query
     * @param ConnectionInterface $con optional connection object
     * @return ObjectCollection|ChildPrograma[] List of ChildPrograma objects
     * @phpstan-return ObjectCollection&\Traversable<ChildPrograma> List of ChildPrograma objects
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function getChildren(?Criteria $criteria = null, ?ConnectionInterface $con = null)
    {
        $partial = $this->collChildrenPartial && !$this->isNew();
        if (null === $this->collChildren || null !== $criteria || $partial) {
            if ($this->isNew()) {
                // return empty collection
                if (null === $this->collChildren) {
                    $this->initChildren();
                } else {
                    $collectionClassName = ProgramaTableMap::getTableMap()->getCollectionClassName();

                    $collChildren = new $collectionClassName;
                    $collChildren->setModel('\Baja\Model\Programa');

                    return $collChildren;
                }
            } else {
                $collChildren = ChildProgramaQuery::create(null, $criteria)
                    ->filterByParent($this)
                    ->find($con);

                if (null !== $criteria) {
                    if (false !== $this->collChildrenPartial && count($collChildren)) {
                        $this->initChildren(false);

                        foreach ($collChildren as $obj) {
                            if (false == $this->collChildren->contains($obj)) {
                                $this->collChildren->append($obj);
                            }
                        }

                        $this->collChildrenPartial = true;
                    }

                    return $collChildren;
                }

                if ($partial && $this->collChildren) {
                    foreach ($this->collChildren as $obj) {
                        if ($obj->isNew()) {
                            $collChildren[] = $obj;
                        }
                    }
                }

                $this->collChildren = $collChildren;
                $this->collChildrenPartial = false;
            }
        }

        return $this->collChildren;
    }

    /**
     * Sets a collection of ChildPrograma objects related by a one-to-many relationship
     * to the current object.
     * It will also schedule objects for deletion based on a diff between old objects (aka persisted)
     * and new objects from the given Propel collection.
     *
     * @param Collection $children A Propel collection.
     * @param ConnectionInterface $con Optional connection object
     * @return $this The current object (for fluent API support)
     */
    public function setChildren(Collection $children, ?ConnectionInterface $con = null)
    {
        /** @var ChildPrograma[] $childrenToDelete */
        $childrenToDelete = $this->getChildren(new Criteria(), $con)->diff($children);


        $this->childrenScheduledForDeletion = $childrenToDelete;

        foreach ($childrenToDelete as $childRemoved) {
            $childRemoved->setParent(null);
        }

        $this->collChildren = null;
        foreach ($children as $child) {
            $this->addChild($child);
        }

        $this->collChildren = $children;
        $this->collChildrenPartial = false;

        return $this;
    }

    /**
     * Returns the number of related Programa objects.
     *
     * @param Criteria $criteria
     * @param bool $distinct
     * @param ConnectionInterface $con
     * @return int Count of related Programa objects.
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function countChildren(?Criteria $criteria = null, bool $distinct = false, ?ConnectionInterface $con = null): int
    {
        $partial = $this->collChildrenPartial && !$this->isNew();
        if (null === $this->collChildren || null !== $criteria || $partial) {
            if ($this->isNew() && null === $this->collChildren) {
                return 0;
            }

            if ($partial && !$criteria) {
                return count($this->getChildren());
            }

            $query = ChildProgramaQuery::create(null, $criteria);
            if ($distinct) {
                $query->distinct();
            }

            return $query
                ->filterByParent($this)
                ->count($con);
        }

        return count($this->collChildren);
    }

    /**
     * Method called to associate a ChildPrograma object to this object
     * through the ChildPrograma foreign key attribute.
     *
     * @param ChildPrograma $l ChildPrograma
     * @return $this The current object (for fluent API support)
     */
    public function addChild(ChildPrograma $l)
    {
        if ($this->collChildren === null) {
            $this->initChildren();
            $this->collChildrenPartial = true;
        }

        if (!$this->collChildren->contains($l)) {
            $this->doAddChild($l);

            if ($this->childrenScheduledForDeletion and $this->childrenScheduledForDeletion->contains($l)) {
                $this->childrenScheduledForDeletion->remove($this->childrenScheduledForDeletion->search($l));
            }
        }

        return $this;
    }

    /**
     * @param ChildPrograma $child The ChildPrograma object to add.
     */
    protected function doAddChild(ChildPrograma $child): void
    {
        $this->collChildren[]= $child;
        $child->setParent($this);
    }

    /**
     * @param ChildPrograma $child The ChildPrograma object to remove.
     * @return $this The current object (for fluent API support)
     */
    public function removeChild(ChildPrograma $child)
    {
        if ($this->getChildren()->contains($child)) {
            $pos = $this->collChildren->search($child);
            $this->collChildren->remove($pos);
            if (null === $this->childrenScheduledForDeletion) {
                $this->childrenScheduledForDeletion = clone $this->collChildren;
                $this->childrenScheduledForDeletion->clear();
            }
            $this->childrenScheduledForDeletion[]= $child;
            $child->setParent(null);
        }

        return $this;
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
        if (null !== $this->aParent) {
            $this->aParent->removeChild($this);
        }
        $this->programa_id = null;
        $this->parent_id = null;
        $this->nome = null;
        $this->ordem = null;
        $this->ativo = null;
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
            if ($this->collEventos) {
                foreach ($this->collEventos as $o) {
                    $o->clearAllReferences($deep);
                }
            }
            if ($this->collChildren) {
                foreach ($this->collChildren as $o) {
                    $o->clearAllReferences($deep);
                }
            }
        } // if ($deep)

        $this->collEventos = null;
        $this->collChildren = null;
        $this->aParent = null;
        return $this;
    }

    /**
     * Return the string representation of this object
     *
     * @return string
     */
    public function __toString()
    {
        return (string) $this->exportTo(ProgramaTableMap::DEFAULT_STRING_FORMAT);
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
