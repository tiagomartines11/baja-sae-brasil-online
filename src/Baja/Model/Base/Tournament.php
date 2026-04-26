<?php

namespace Baja\Model\Base;

use \Exception;
use \PDO;
use Baja\Model\Equipe as ChildEquipe;
use Baja\Model\EquipeQuery as ChildEquipeQuery;
use Baja\Model\Prova as ChildProva;
use Baja\Model\ProvaQuery as ChildProvaQuery;
use Baja\Model\TournamentQuery as ChildTournamentQuery;
use Baja\Model\Map\TournamentTableMap;
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
 * Base class that represents a row from the 'tournament' table.
 *
 *
 *
 * @package    propel.generator.Baja.Model.Base
 */
abstract class Tournament implements ActiveRecordInterface
{
    /**
     * TableMap class name
     *
     * @var string
     */
    public const TABLE_MAP = '\\Baja\\Model\\Map\\TournamentTableMap';


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
     * The value for the evento_id field.
     *
     * @var        string
     */
    protected $evento_id;

    /**
     * The value for the prova_id field.
     *
     * @var        string
     */
    protected $prova_id;

    /**
     * The value for the match_id field.
     *
     * @var        int
     */
    protected $match_id;

    /**
     * The value for the round field.
     *
     * @var        string
     */
    protected $round;

    /**
     * The value for the equipe1_id field.
     *
     * @var        string|null
     */
    protected $equipe1_id;

    /**
     * The value for the equipe2_id field.
     *
     * @var        string|null
     */
    protected $equipe2_id;

    /**
     * The value for the winner field.
     *
     * @var        int|null
     */
    protected $winner;

    /**
     * The value for the dados field.
     *
     * @var        string|null
     */
    protected $dados;

    /**
     * @var        ChildProva
     */
    protected $aProva;

    /**
     * @var        ChildEquipe
     */
    protected $aEquipe;

    /**
     * Flag to prevent endless save loop, if this object is referenced
     * by another object which falls in this transaction.
     *
     * @var bool
     */
    protected $alreadyInSave = false;

    /**
     * Initializes internal state of Baja\Model\Base\Tournament object.
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
     * Compares this with another <code>Tournament</code> instance.  If
     * <code>obj</code> is an instance of <code>Tournament</code>, delegates to
     * <code>equals(Tournament)</code>.  Otherwise, returns <code>false</code>.
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
     * Get the [evento_id] column value.
     *
     * @return string
     */
    public function getEventoId()
    {
        return $this->evento_id;
    }

    /**
     * Get the [prova_id] column value.
     *
     * @return string
     */
    public function getProvaId()
    {
        return $this->prova_id;
    }

    /**
     * Get the [match_id] column value.
     *
     * @return int
     */
    public function getMatchId()
    {
        return $this->match_id;
    }

    /**
     * Get the [round] column value.
     *
     * @return string
     */
    public function getround()
    {
        return $this->round;
    }

    /**
     * Get the [equipe1_id] column value.
     *
     * @return string|null
     */
    public function getEquipe1Id()
    {
        return $this->equipe1_id;
    }

    /**
     * Get the [equipe2_id] column value.
     *
     * @return string|null
     */
    public function getEquipe2Id()
    {
        return $this->equipe2_id;
    }

    /**
     * Get the [winner] column value.
     *
     * @return int|null
     */
    public function getWinner()
    {
        return $this->winner;
    }

    /**
     * Get the [dados] column value.
     *
     * @return string|null
     */
    public function getDados()
    {
        return $this->dados;
    }

    /**
     * Set the value of [evento_id] column.
     *
     * @param string $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setEventoId($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->evento_id !== $v) {
            $this->evento_id = $v;
            $this->modifiedColumns[TournamentTableMap::COL_EVENTO_ID] = true;
        }

        if ($this->aProva !== null && $this->aProva->getEventoId() !== $v) {
            $this->aProva = null;
        }

        if ($this->aEquipe !== null && $this->aEquipe->getEventoId() !== $v) {
            $this->aEquipe = null;
        }

        return $this;
    }

    /**
     * Set the value of [prova_id] column.
     *
     * @param string $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setProvaId($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->prova_id !== $v) {
            $this->prova_id = $v;
            $this->modifiedColumns[TournamentTableMap::COL_PROVA_ID] = true;
        }

        if ($this->aProva !== null && $this->aProva->getProvaId() !== $v) {
            $this->aProva = null;
        }

        return $this;
    }

    /**
     * Set the value of [match_id] column.
     *
     * @param int $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setMatchId($v)
    {
        if ($v !== null) {
            $v = (int) $v;
        }

        if ($this->match_id !== $v) {
            $this->match_id = $v;
            $this->modifiedColumns[TournamentTableMap::COL_MATCH_ID] = true;
        }

        return $this;
    }

    /**
     * Set the value of [round] column.
     *
     * @param string $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setround($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->round !== $v) {
            $this->round = $v;
            $this->modifiedColumns[TournamentTableMap::COL_ROUND] = true;
        }

        return $this;
    }

    /**
     * Set the value of [equipe1_id] column.
     *
     * @param string|null $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setEquipe1Id($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->equipe1_id !== $v) {
            $this->equipe1_id = $v;
            $this->modifiedColumns[TournamentTableMap::COL_EQUIPE1_ID] = true;
        }

        return $this;
    }

    /**
     * Set the value of [equipe2_id] column.
     *
     * @param string|null $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setEquipe2Id($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->equipe2_id !== $v) {
            $this->equipe2_id = $v;
            $this->modifiedColumns[TournamentTableMap::COL_EQUIPE2_ID] = true;
        }

        return $this;
    }

    /**
     * Set the value of [winner] column.
     *
     * @param int|null $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setWinner($v)
    {
        if ($v !== null) {
            $v = (int) $v;
        }

        if ($this->winner !== $v) {
            $this->winner = $v;
            $this->modifiedColumns[TournamentTableMap::COL_WINNER] = true;
        }

        if ($this->aEquipe !== null && $this->aEquipe->getEquipeId() !== $v) {
            $this->aEquipe = null;
        }

        return $this;
    }

    /**
     * Set the value of [dados] column.
     *
     * @param string|null $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setDados($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->dados !== $v) {
            $this->dados = $v;
            $this->modifiedColumns[TournamentTableMap::COL_DADOS] = true;
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

            $col = $row[TableMap::TYPE_NUM == $indexType ? 0 + $startcol : TournamentTableMap::translateFieldName('EventoId', TableMap::TYPE_PHPNAME, $indexType)];
            $this->evento_id = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 1 + $startcol : TournamentTableMap::translateFieldName('ProvaId', TableMap::TYPE_PHPNAME, $indexType)];
            $this->prova_id = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 2 + $startcol : TournamentTableMap::translateFieldName('MatchId', TableMap::TYPE_PHPNAME, $indexType)];
            $this->match_id = (null !== $col) ? (int) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 3 + $startcol : TournamentTableMap::translateFieldName('round', TableMap::TYPE_PHPNAME, $indexType)];
            $this->round = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 4 + $startcol : TournamentTableMap::translateFieldName('Equipe1Id', TableMap::TYPE_PHPNAME, $indexType)];
            $this->equipe1_id = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 5 + $startcol : TournamentTableMap::translateFieldName('Equipe2Id', TableMap::TYPE_PHPNAME, $indexType)];
            $this->equipe2_id = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 6 + $startcol : TournamentTableMap::translateFieldName('Winner', TableMap::TYPE_PHPNAME, $indexType)];
            $this->winner = (null !== $col) ? (int) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 7 + $startcol : TournamentTableMap::translateFieldName('Dados', TableMap::TYPE_PHPNAME, $indexType)];
            $this->dados = (null !== $col) ? (string) $col : null;

            $this->resetModified();
            $this->setNew(false);

            if ($rehydrate) {
                $this->ensureConsistency();
            }

            return $startcol + 8; // 8 = TournamentTableMap::NUM_HYDRATE_COLUMNS.

        } catch (Exception $e) {
            throw new PropelException(sprintf('Error populating %s object', '\\Baja\\Model\\Tournament'), 0, $e);
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
        if ($this->aProva !== null && $this->evento_id !== $this->aProva->getEventoId()) {
            $this->aProva = null;
        }
        if ($this->aEquipe !== null && $this->evento_id !== $this->aEquipe->getEventoId()) {
            $this->aEquipe = null;
        }
        if ($this->aProva !== null && $this->prova_id !== $this->aProva->getProvaId()) {
            $this->aProva = null;
        }
        if ($this->aEquipe !== null && $this->winner !== $this->aEquipe->getEquipeId()) {
            $this->aEquipe = null;
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
            $con = Propel::getServiceContainer()->getReadConnection(TournamentTableMap::DATABASE_NAME);
        }

        // We don't need to alter the object instance pool; we're just modifying this instance
        // already in the pool.

        $dataFetcher = ChildTournamentQuery::create(null, $this->buildPkeyCriteria())->setFormatter(ModelCriteria::FORMAT_STATEMENT)->find($con);
        $row = $dataFetcher->fetch();
        $dataFetcher->close();
        if (!$row) {
            throw new PropelException('Cannot find matching row in the database to reload object values.');
        }
        $this->hydrate($row, 0, true, $dataFetcher->getIndexType()); // rehydrate

        if ($deep) {  // also de-associate any related objects?

            $this->aProva = null;
            $this->aEquipe = null;
        } // if (deep)
    }

    /**
     * Removes this object from datastore and sets delete attribute.
     *
     * @param ConnectionInterface $con
     * @return void
     * @throws \Propel\Runtime\Exception\PropelException
     * @see Tournament::setDeleted()
     * @see Tournament::isDeleted()
     */
    public function delete(?ConnectionInterface $con = null): void
    {
        if ($this->isDeleted()) {
            throw new PropelException("This object has already been deleted.");
        }

        if ($con === null) {
            $con = Propel::getServiceContainer()->getWriteConnection(TournamentTableMap::DATABASE_NAME);
        }

        $con->transaction(function () use ($con) {
            $deleteQuery = ChildTournamentQuery::create()
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
            $con = Propel::getServiceContainer()->getWriteConnection(TournamentTableMap::DATABASE_NAME);
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
                TournamentTableMap::addInstanceToPool($this);
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

            if ($this->aProva !== null) {
                if ($this->aProva->isModified() || $this->aProva->isNew()) {
                    $affectedRows += $this->aProva->save($con);
                }
                $this->setProva($this->aProva);
            }

            if ($this->aEquipe !== null) {
                if ($this->aEquipe->isModified() || $this->aEquipe->isNew()) {
                    $affectedRows += $this->aEquipe->save($con);
                }
                $this->setEquipe($this->aEquipe);
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
        if ($this->isColumnModified(TournamentTableMap::COL_EVENTO_ID)) {
            $modifiedColumns[':p' . $index++]  = 'evento_id';
        }
        if ($this->isColumnModified(TournamentTableMap::COL_PROVA_ID)) {
            $modifiedColumns[':p' . $index++]  = 'prova_id';
        }
        if ($this->isColumnModified(TournamentTableMap::COL_MATCH_ID)) {
            $modifiedColumns[':p' . $index++]  = 'match_id';
        }
        if ($this->isColumnModified(TournamentTableMap::COL_ROUND)) {
            $modifiedColumns[':p' . $index++]  = 'round';
        }
        if ($this->isColumnModified(TournamentTableMap::COL_EQUIPE1_ID)) {
            $modifiedColumns[':p' . $index++]  = 'equipe1_id';
        }
        if ($this->isColumnModified(TournamentTableMap::COL_EQUIPE2_ID)) {
            $modifiedColumns[':p' . $index++]  = 'equipe2_id';
        }
        if ($this->isColumnModified(TournamentTableMap::COL_WINNER)) {
            $modifiedColumns[':p' . $index++]  = 'winner';
        }
        if ($this->isColumnModified(TournamentTableMap::COL_DADOS)) {
            $modifiedColumns[':p' . $index++]  = 'dados';
        }

        $sql = sprintf(
            'INSERT INTO tournament (%s) VALUES (%s)',
            implode(', ', $modifiedColumns),
            implode(', ', array_keys($modifiedColumns))
        );

        try {
            $stmt = $con->prepare($sql);
            foreach ($modifiedColumns as $identifier => $columnName) {
                switch ($columnName) {
                    case 'evento_id':
                        $stmt->bindValue($identifier, $this->evento_id, PDO::PARAM_STR);

                        break;
                    case 'prova_id':
                        $stmt->bindValue($identifier, $this->prova_id, PDO::PARAM_STR);

                        break;
                    case 'match_id':
                        $stmt->bindValue($identifier, $this->match_id, PDO::PARAM_INT);

                        break;
                    case 'round':
                        $stmt->bindValue($identifier, $this->round, PDO::PARAM_STR);

                        break;
                    case 'equipe1_id':
                        $stmt->bindValue($identifier, $this->equipe1_id, PDO::PARAM_STR);

                        break;
                    case 'equipe2_id':
                        $stmt->bindValue($identifier, $this->equipe2_id, PDO::PARAM_STR);

                        break;
                    case 'winner':
                        $stmt->bindValue($identifier, $this->winner, PDO::PARAM_INT);

                        break;
                    case 'dados':
                        $stmt->bindValue($identifier, $this->dados, PDO::PARAM_STR);

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
        $pos = TournamentTableMap::translateFieldName($name, $type, TableMap::TYPE_NUM);
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
                return $this->getEventoId();

            case 1:
                return $this->getProvaId();

            case 2:
                return $this->getMatchId();

            case 3:
                return $this->getround();

            case 4:
                return $this->getEquipe1Id();

            case 5:
                return $this->getEquipe2Id();

            case 6:
                return $this->getWinner();

            case 7:
                return $this->getDados();

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
        if (isset($alreadyDumpedObjects['Tournament'][$this->hashCode()])) {
            return ['*RECURSION*'];
        }
        $alreadyDumpedObjects['Tournament'][$this->hashCode()] = true;
        $keys = TournamentTableMap::getFieldNames($keyType);
        $result = [
            $keys[0] => $this->getEventoId(),
            $keys[1] => $this->getProvaId(),
            $keys[2] => $this->getMatchId(),
            $keys[3] => $this->getround(),
            $keys[4] => $this->getEquipe1Id(),
            $keys[5] => $this->getEquipe2Id(),
            $keys[6] => $this->getWinner(),
            $keys[7] => $this->getDados(),
        ];
        $virtualColumns = $this->virtualColumns;
        foreach ($virtualColumns as $key => $virtualColumn) {
            $result[$key] = $virtualColumn;
        }

        if ($includeForeignObjects) {
            if (null !== $this->aProva) {

                switch ($keyType) {
                    case TableMap::TYPE_CAMELNAME:
                        $key = 'prova';
                        break;
                    case TableMap::TYPE_FIELDNAME:
                        $key = 'prova';
                        break;
                    default:
                        $key = 'Prova';
                }

                $result[$key] = $this->aProva->toArray($keyType, $includeLazyLoadColumns,  $alreadyDumpedObjects, true);
            }
            if (null !== $this->aEquipe) {

                switch ($keyType) {
                    case TableMap::TYPE_CAMELNAME:
                        $key = 'equipe';
                        break;
                    case TableMap::TYPE_FIELDNAME:
                        $key = 'equipe';
                        break;
                    default:
                        $key = 'Equipe';
                }

                $result[$key] = $this->aEquipe->toArray($keyType, $includeLazyLoadColumns,  $alreadyDumpedObjects, true);
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
        $pos = TournamentTableMap::translateFieldName($name, $type, TableMap::TYPE_NUM);

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
                $this->setEventoId($value);
                break;
            case 1:
                $this->setProvaId($value);
                break;
            case 2:
                $this->setMatchId($value);
                break;
            case 3:
                $this->setround($value);
                break;
            case 4:
                $this->setEquipe1Id($value);
                break;
            case 5:
                $this->setEquipe2Id($value);
                break;
            case 6:
                $this->setWinner($value);
                break;
            case 7:
                $this->setDados($value);
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
        $keys = TournamentTableMap::getFieldNames($keyType);

        if (array_key_exists($keys[0], $arr)) {
            $this->setEventoId($arr[$keys[0]]);
        }
        if (array_key_exists($keys[1], $arr)) {
            $this->setProvaId($arr[$keys[1]]);
        }
        if (array_key_exists($keys[2], $arr)) {
            $this->setMatchId($arr[$keys[2]]);
        }
        if (array_key_exists($keys[3], $arr)) {
            $this->setround($arr[$keys[3]]);
        }
        if (array_key_exists($keys[4], $arr)) {
            $this->setEquipe1Id($arr[$keys[4]]);
        }
        if (array_key_exists($keys[5], $arr)) {
            $this->setEquipe2Id($arr[$keys[5]]);
        }
        if (array_key_exists($keys[6], $arr)) {
            $this->setWinner($arr[$keys[6]]);
        }
        if (array_key_exists($keys[7], $arr)) {
            $this->setDados($arr[$keys[7]]);
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
        $criteria = new Criteria(TournamentTableMap::DATABASE_NAME);

        if ($this->isColumnModified(TournamentTableMap::COL_EVENTO_ID)) {
            $criteria->add(TournamentTableMap::COL_EVENTO_ID, $this->evento_id);
        }
        if ($this->isColumnModified(TournamentTableMap::COL_PROVA_ID)) {
            $criteria->add(TournamentTableMap::COL_PROVA_ID, $this->prova_id);
        }
        if ($this->isColumnModified(TournamentTableMap::COL_MATCH_ID)) {
            $criteria->add(TournamentTableMap::COL_MATCH_ID, $this->match_id);
        }
        if ($this->isColumnModified(TournamentTableMap::COL_ROUND)) {
            $criteria->add(TournamentTableMap::COL_ROUND, $this->round);
        }
        if ($this->isColumnModified(TournamentTableMap::COL_EQUIPE1_ID)) {
            $criteria->add(TournamentTableMap::COL_EQUIPE1_ID, $this->equipe1_id);
        }
        if ($this->isColumnModified(TournamentTableMap::COL_EQUIPE2_ID)) {
            $criteria->add(TournamentTableMap::COL_EQUIPE2_ID, $this->equipe2_id);
        }
        if ($this->isColumnModified(TournamentTableMap::COL_WINNER)) {
            $criteria->add(TournamentTableMap::COL_WINNER, $this->winner);
        }
        if ($this->isColumnModified(TournamentTableMap::COL_DADOS)) {
            $criteria->add(TournamentTableMap::COL_DADOS, $this->dados);
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
        $criteria = ChildTournamentQuery::create();
        $criteria->add(TournamentTableMap::COL_EVENTO_ID, $this->evento_id);
        $criteria->add(TournamentTableMap::COL_PROVA_ID, $this->prova_id);
        $criteria->add(TournamentTableMap::COL_MATCH_ID, $this->match_id);

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
        $validPk = null !== $this->getEventoId() &&
            null !== $this->getProvaId() &&
            null !== $this->getMatchId();

        $validPrimaryKeyFKs = 3;
        $primaryKeyFKs = [];

        //relation tournament_evento_id_prova_id to table prova
        if ($this->aProva && $hash = spl_object_hash($this->aProva)) {
            $primaryKeyFKs[] = $hash;
        } else {
            $validPrimaryKeyFKs = false;
        }

        //relation tournament_evento_id_equipe_idwin to table equipe
        if ($this->aEquipe && $hash = spl_object_hash($this->aEquipe)) {
            $primaryKeyFKs[] = $hash;
        } else {
            $validPrimaryKeyFKs = false;
        }

        if ($validPk) {
            return crc32(json_encode($this->getPrimaryKey(), JSON_UNESCAPED_UNICODE));
        } elseif ($validPrimaryKeyFKs) {
            return crc32(json_encode($primaryKeyFKs, JSON_UNESCAPED_UNICODE));
        }

        return spl_object_hash($this);
    }

    /**
     * Returns the composite primary key for this object.
     * The array elements will be in same order as specified in XML.
     * @return array
     */
    public function getPrimaryKey()
    {
        $pks = [];
        $pks[0] = $this->getEventoId();
        $pks[1] = $this->getProvaId();
        $pks[2] = $this->getMatchId();

        return $pks;
    }

    /**
     * Set the [composite] primary key.
     *
     * @param array $keys The elements of the composite key (order must match the order in XML file).
     * @return void
     */
    public function setPrimaryKey(array $keys): void
    {
        $this->setEventoId($keys[0]);
        $this->setProvaId($keys[1]);
        $this->setMatchId($keys[2]);
    }

    /**
     * Returns true if the primary key for this object is null.
     *
     * @return bool
     */
    public function isPrimaryKeyNull(): bool
    {
        return (null === $this->getEventoId()) && (null === $this->getProvaId()) && (null === $this->getMatchId());
    }

    /**
     * Sets contents of passed object to values from current object.
     *
     * If desired, this method can also make copies of all associated (fkey referrers)
     * objects.
     *
     * @param object $copyObj An object of \Baja\Model\Tournament (or compatible) type.
     * @param bool $deepCopy Whether to also copy all rows that refer (by fkey) to the current row.
     * @param bool $makeNew Whether to reset autoincrement PKs and make the object new.
     * @throws \Propel\Runtime\Exception\PropelException
     * @return void
     */
    public function copyInto(object $copyObj, bool $deepCopy = false, bool $makeNew = true): void
    {
        $copyObj->setEventoId($this->getEventoId());
        $copyObj->setProvaId($this->getProvaId());
        $copyObj->setMatchId($this->getMatchId());
        $copyObj->setround($this->getround());
        $copyObj->setEquipe1Id($this->getEquipe1Id());
        $copyObj->setEquipe2Id($this->getEquipe2Id());
        $copyObj->setWinner($this->getWinner());
        $copyObj->setDados($this->getDados());
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
     * @return \Baja\Model\Tournament Clone of current object.
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
     * Declares an association between this object and a ChildProva object.
     *
     * @param ChildProva $v
     * @return $this The current object (for fluent API support)
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function setProva(ChildProva $v = null)
    {
        if ($v === null) {
            $this->setEventoId(NULL);
        } else {
            $this->setEventoId($v->getEventoId());
        }

        if ($v === null) {
            $this->setProvaId(NULL);
        } else {
            $this->setProvaId($v->getProvaId());
        }

        $this->aProva = $v;

        // Add binding for other direction of this n:n relationship.
        // If this object has already been added to the ChildProva object, it will not be re-added.
        if ($v !== null) {
            $v->addTournament($this);
        }


        return $this;
    }


    /**
     * Get the associated ChildProva object
     *
     * @param ConnectionInterface $con Optional Connection object.
     * @return ChildProva The associated ChildProva object.
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function getProva(?ConnectionInterface $con = null)
    {
        if ($this->aProva === null && (($this->evento_id !== "" && $this->evento_id !== null) && ($this->prova_id !== "" && $this->prova_id !== null))) {
            $this->aProva = ChildProvaQuery::create()->findPk(array($this->evento_id, $this->prova_id), $con);
            /* The following can be used additionally to
                guarantee the related object contains a reference
                to this object.  This level of coupling may, however, be
                undesirable since it could result in an only partially populated collection
                in the referenced object.
                $this->aProva->addTournaments($this);
             */
        }

        return $this->aProva;
    }

    /**
     * Declares an association between this object and a ChildEquipe object.
     *
     * @param ChildEquipe $v
     * @return $this The current object (for fluent API support)
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function setEquipe(ChildEquipe $v = null)
    {
        if ($v === null) {
            $this->setEventoId(NULL);
        } else {
            $this->setEventoId($v->getEventoId());
        }

        if ($v === null) {
            $this->setWinner(NULL);
        } else {
            $this->setWinner($v->getEquipeId());
        }

        $this->aEquipe = $v;

        // Add binding for other direction of this n:n relationship.
        // If this object has already been added to the ChildEquipe object, it will not be re-added.
        if ($v !== null) {
            $v->addTournament($this);
        }


        return $this;
    }


    /**
     * Get the associated ChildEquipe object
     *
     * @param ConnectionInterface $con Optional Connection object.
     * @return ChildEquipe The associated ChildEquipe object.
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function getEquipe(?ConnectionInterface $con = null)
    {
        if ($this->aEquipe === null && (($this->evento_id !== "" && $this->evento_id !== null) && $this->winner != 0)) {
            $this->aEquipe = ChildEquipeQuery::create()->findPk(array($this->evento_id, $this->winner), $con);
            /* The following can be used additionally to
                guarantee the related object contains a reference
                to this object.  This level of coupling may, however, be
                undesirable since it could result in an only partially populated collection
                in the referenced object.
                $this->aEquipe->addTournaments($this);
             */
        }

        return $this->aEquipe;
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
        if (null !== $this->aProva) {
            $this->aProva->removeTournament($this);
        }
        if (null !== $this->aEquipe) {
            $this->aEquipe->removeTournament($this);
        }
        $this->evento_id = null;
        $this->prova_id = null;
        $this->match_id = null;
        $this->round = null;
        $this->equipe1_id = null;
        $this->equipe2_id = null;
        $this->winner = null;
        $this->dados = null;
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

        $this->aProva = null;
        $this->aEquipe = null;
        return $this;
    }

    /**
     * Return the string representation of this object
     *
     * @return string
     */
    public function __toString()
    {
        return (string) $this->exportTo(TournamentTableMap::DEFAULT_STRING_FORMAT);
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
