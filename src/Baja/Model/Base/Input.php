<?php

namespace Baja\Model\Base;

use \Exception;
use \PDO;
use Baja\Model\Equipe as ChildEquipe;
use Baja\Model\EquipeQuery as ChildEquipeQuery;
use Baja\Model\InputQuery as ChildInputQuery;
use Baja\Model\Prova as ChildProva;
use Baja\Model\ProvaQuery as ChildProvaQuery;
use Baja\Model\Map\InputTableMap;
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
 * Base class that represents a row from the 'input' table.
 *
 *
 *
 * @package    propel.generator.Baja.Model.Base
 */
abstract class Input implements ActiveRecordInterface
{
    /**
     * TableMap class name
     */
    const TABLE_MAP = '\\Baja\\Model\\Map\\InputTableMap';


    /**
     * attribute to determine if this object has previously been saved.
     * @var boolean
     */
    protected $new = true;

    /**
     * attribute to determine whether this object has been deleted.
     * @var boolean
     */
    protected $deleted = false;

    /**
     * The columns that have been modified in current object.
     * Tracking modified columns allows us to only update modified columns.
     * @var array
     */
    protected $modifiedColumns = array();

    /**
     * The (virtual) columns that are added at runtime
     * The formatters can add supplementary columns based on a resultset
     * @var array
     */
    protected $virtualColumns = array();

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
     * The value for the equipe_id field.
     *
     * @var        int
     */
    protected $equipe_id;

    /**
     * The value for the dados field.
     *
     * @var        string
     */
    protected $dados;

    /**
     * The value for the vars field.
     *
     * @var        string
     */
    protected $vars;

    /**
     * The value for the pontos field.
     *
     * @var        string
     */
    protected $pontos;

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
     * @var boolean
     */
    protected $alreadyInSave = false;

    /**
     * Initializes internal state of Baja\Model\Base\Input object.
     */
    public function __construct()
    {
    }

    /**
     * Returns whether the object has been modified.
     *
     * @return boolean True if the object has been modified.
     */
    public function isModified()
    {
        return !!$this->modifiedColumns;
    }

    /**
     * Has specified column been modified?
     *
     * @param  string  $col column fully qualified name (TableMap::TYPE_COLNAME), e.g. Book::AUTHOR_ID
     * @return boolean True if $col has been modified.
     */
    public function isColumnModified($col)
    {
        return $this->modifiedColumns && isset($this->modifiedColumns[$col]);
    }

    /**
     * Get the columns that have been modified in this object.
     * @return array A unique list of the modified column names for this object.
     */
    public function getModifiedColumns()
    {
        return $this->modifiedColumns ? array_keys($this->modifiedColumns) : [];
    }

    /**
     * Returns whether the object has ever been saved.  This will
     * be false, if the object was retrieved from storage or was created
     * and then saved.
     *
     * @return boolean true, if the object has never been persisted.
     */
    public function isNew()
    {
        return $this->new;
    }

    /**
     * Setter for the isNew attribute.  This method will be called
     * by Propel-generated children and objects.
     *
     * @param boolean $b the state of the object.
     */
    public function setNew($b)
    {
        $this->new = (boolean) $b;
    }

    /**
     * Whether this object has been deleted.
     * @return boolean The deleted state of this object.
     */
    public function isDeleted()
    {
        return $this->deleted;
    }

    /**
     * Specify whether this object has been deleted.
     * @param  boolean $b The deleted state of this object.
     * @return void
     */
    public function setDeleted($b)
    {
        $this->deleted = (boolean) $b;
    }

    /**
     * Sets the modified state for the object to be false.
     * @param  string $col If supplied, only the specified column is reset.
     * @return void
     */
    public function resetModified($col = null)
    {
        if (null !== $col) {
            if (isset($this->modifiedColumns[$col])) {
                unset($this->modifiedColumns[$col]);
            }
        } else {
            $this->modifiedColumns = array();
        }
    }

    /**
     * Compares this with another <code>Input</code> instance.  If
     * <code>obj</code> is an instance of <code>Input</code>, delegates to
     * <code>equals(Input)</code>.  Otherwise, returns <code>false</code>.
     *
     * @param  mixed   $obj The object to compare to.
     * @return boolean Whether equal to the object specified.
     */
    public function equals($obj)
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
    public function getVirtualColumns()
    {
        return $this->virtualColumns;
    }

    /**
     * Checks the existence of a virtual column in this object
     *
     * @param  string  $name The virtual column name
     * @return boolean
     */
    public function hasVirtualColumn($name)
    {
        return array_key_exists($name, $this->virtualColumns);
    }

    /**
     * Get the value of a virtual column in this object
     *
     * @param  string $name The virtual column name
     * @return mixed
     *
     * @throws PropelException
     */
    public function getVirtualColumn($name)
    {
        if (!$this->hasVirtualColumn($name)) {
            throw new PropelException(sprintf('Cannot get value of inexistent virtual column %s.', $name));
        }

        return $this->virtualColumns[$name];
    }

    /**
     * Set the value of a virtual column in this object
     *
     * @param string $name  The virtual column name
     * @param mixed  $value The value to give to the virtual column
     *
     * @return $this|Input The current object, for fluid interface
     */
    public function setVirtualColumn($name, $value)
    {
        $this->virtualColumns[$name] = $value;

        return $this;
    }

    /**
     * Logs a message using Propel::log().
     *
     * @param  string  $msg
     * @param  int     $priority One of the Propel::LOG_* logging levels
     * @return boolean
     */
    protected function log($msg, $priority = Propel::LOG_INFO)
    {
        return Propel::log(get_class($this) . ': ' . $msg, $priority);
    }

    /**
     * Export the current object properties to a string, using a given parser format
     * <code>
     * $book = BookQuery::create()->findPk(9012);
     * echo $book->exportTo('JSON');
     *  => {"Id":9012,"Title":"Don Juan","ISBN":"0140422161","Price":12.99,"PublisherId":1234,"AuthorId":5678}');
     * </code>
     *
     * @param  mixed   $parser                 A AbstractParser instance, or a format name ('XML', 'YAML', 'JSON', 'CSV')
     * @param  boolean $includeLazyLoadColumns (optional) Whether to include lazy load(ed) columns. Defaults to TRUE.
     * @return string  The exported data
     */
    public function exportTo($parser, $includeLazyLoadColumns = true)
    {
        if (!$parser instanceof AbstractParser) {
            $parser = AbstractParser::getParser($parser);
        }

        return $parser->fromArray($this->toArray(TableMap::TYPE_PHPNAME, $includeLazyLoadColumns, array(), true));
    }

    /**
     * Clean up internal collections prior to serializing
     * Avoids recursive loops that turn into segmentation faults when serializing
     */
    public function __sleep()
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
     * Get the [equipe_id] column value.
     *
     * @return int
     */
    public function getEquipeId()
    {
        return $this->equipe_id;
    }

    /**
     * Get the [dados] column value.
     *
     * @return string
     */
    public function getDados()
    {
        return $this->dados;
    }

    /**
     * Get the [vars] column value.
     *
     * @return string
     */
    public function getVars()
    {
        return $this->vars;
    }

    /**
     * Get the [pontos] column value.
     *
     * @return string
     */
    public function getPontos()
    {
        return $this->pontos;
    }

    /**
     * Set the value of [evento_id] column.
     *
     * @param string $v new value
     * @return $this|\Baja\Model\Input The current object (for fluent API support)
     */
    public function setEventoId($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->evento_id !== $v) {
            $this->evento_id = $v;
            $this->modifiedColumns[InputTableMap::COL_EVENTO_ID] = true;
        }

        if ($this->aProva !== null && $this->aProva->getEventoId() !== $v) {
            $this->aProva = null;
        }

        if ($this->aEquipe !== null && $this->aEquipe->getEventoId() !== $v) {
            $this->aEquipe = null;
        }

        return $this;
    } // setEventoId()

    /**
     * Set the value of [prova_id] column.
     *
     * @param string $v new value
     * @return $this|\Baja\Model\Input The current object (for fluent API support)
     */
    public function setProvaId($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->prova_id !== $v) {
            $this->prova_id = $v;
            $this->modifiedColumns[InputTableMap::COL_PROVA_ID] = true;
        }

        if ($this->aProva !== null && $this->aProva->getProvaId() !== $v) {
            $this->aProva = null;
        }

        return $this;
    } // setProvaId()

    /**
     * Set the value of [equipe_id] column.
     *
     * @param int $v new value
     * @return $this|\Baja\Model\Input The current object (for fluent API support)
     */
    public function setEquipeId($v)
    {
        if ($v !== null) {
            $v = (int) $v;
        }

        if ($this->equipe_id !== $v) {
            $this->equipe_id = $v;
            $this->modifiedColumns[InputTableMap::COL_EQUIPE_ID] = true;
        }

        if ($this->aEquipe !== null && $this->aEquipe->getEquipeId() !== $v) {
            $this->aEquipe = null;
        }

        return $this;
    } // setEquipeId()

    /**
     * Set the value of [dados] column.
     *
     * @param string $v new value
     * @return $this|\Baja\Model\Input The current object (for fluent API support)
     */
    public function setDados($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->dados !== $v) {
            $this->dados = $v;
            $this->modifiedColumns[InputTableMap::COL_DADOS] = true;
        }

        return $this;
    } // setDados()

    /**
     * Set the value of [vars] column.
     *
     * @param string $v new value
     * @return $this|\Baja\Model\Input The current object (for fluent API support)
     */
    public function setVars($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->vars !== $v) {
            $this->vars = $v;
            $this->modifiedColumns[InputTableMap::COL_VARS] = true;
        }

        return $this;
    } // setVars()

    /**
     * Set the value of [pontos] column.
     *
     * @param string $v new value
     * @return $this|\Baja\Model\Input The current object (for fluent API support)
     */
    public function setPontos($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->pontos !== $v) {
            $this->pontos = $v;
            $this->modifiedColumns[InputTableMap::COL_PONTOS] = true;
        }

        return $this;
    } // setPontos()

    /**
     * Indicates whether the columns in this object are only set to default values.
     *
     * This method can be used in conjunction with isModified() to indicate whether an object is both
     * modified _and_ has some values set which are non-default.
     *
     * @return boolean Whether the columns in this object are only been set with default values.
     */
    public function hasOnlyDefaultValues()
    {
        // otherwise, everything was equal, so return TRUE
        return true;
    } // hasOnlyDefaultValues()

    /**
     * Hydrates (populates) the object variables with values from the database resultset.
     *
     * An offset (0-based "start column") is specified so that objects can be hydrated
     * with a subset of the columns in the resultset rows.  This is needed, for example,
     * for results of JOIN queries where the resultset row includes columns from two or
     * more tables.
     *
     * @param array   $row       The row returned by DataFetcher->fetch().
     * @param int     $startcol  0-based offset column which indicates which restultset column to start with.
     * @param boolean $rehydrate Whether this object is being re-hydrated from the database.
     * @param string  $indexType The index type of $row. Mostly DataFetcher->getIndexType().
                                  One of the class type constants TableMap::TYPE_PHPNAME, TableMap::TYPE_CAMELNAME
     *                            TableMap::TYPE_COLNAME, TableMap::TYPE_FIELDNAME, TableMap::TYPE_NUM.
     *
     * @return int             next starting column
     * @throws PropelException - Any caught Exception will be rewrapped as a PropelException.
     */
    public function hydrate($row, $startcol = 0, $rehydrate = false, $indexType = TableMap::TYPE_NUM)
    {
        try {

            $col = $row[TableMap::TYPE_NUM == $indexType ? 0 + $startcol : InputTableMap::translateFieldName('EventoId', TableMap::TYPE_PHPNAME, $indexType)];
            $this->evento_id = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 1 + $startcol : InputTableMap::translateFieldName('ProvaId', TableMap::TYPE_PHPNAME, $indexType)];
            $this->prova_id = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 2 + $startcol : InputTableMap::translateFieldName('EquipeId', TableMap::TYPE_PHPNAME, $indexType)];
            $this->equipe_id = (null !== $col) ? (int) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 3 + $startcol : InputTableMap::translateFieldName('Dados', TableMap::TYPE_PHPNAME, $indexType)];
            $this->dados = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 4 + $startcol : InputTableMap::translateFieldName('Vars', TableMap::TYPE_PHPNAME, $indexType)];
            $this->vars = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 5 + $startcol : InputTableMap::translateFieldName('Pontos', TableMap::TYPE_PHPNAME, $indexType)];
            $this->pontos = (null !== $col) ? (string) $col : null;
            $this->resetModified();

            $this->setNew(false);

            if ($rehydrate) {
                $this->ensureConsistency();
            }

            return $startcol + 6; // 6 = InputTableMap::NUM_HYDRATE_COLUMNS.

        } catch (Exception $e) {
            throw new PropelException(sprintf('Error populating %s object', '\\Baja\\Model\\Input'), 0, $e);
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
     * @throws PropelException
     */
    public function ensureConsistency()
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
        if ($this->aEquipe !== null && $this->equipe_id !== $this->aEquipe->getEquipeId()) {
            $this->aEquipe = null;
        }
    } // ensureConsistency

    /**
     * Reloads this object from datastore based on primary key and (optionally) resets all associated objects.
     *
     * This will only work if the object has been saved and has a valid primary key set.
     *
     * @param      boolean $deep (optional) Whether to also de-associated any related objects.
     * @param      ConnectionInterface $con (optional) The ConnectionInterface connection to use.
     * @return void
     * @throws PropelException - if this object is deleted, unsaved or doesn't have pk match in db
     */
    public function reload($deep = false, ConnectionInterface $con = null)
    {
        if ($this->isDeleted()) {
            throw new PropelException("Cannot reload a deleted object.");
        }

        if ($this->isNew()) {
            throw new PropelException("Cannot reload an unsaved object.");
        }

        if ($con === null) {
            $con = Propel::getServiceContainer()->getReadConnection(InputTableMap::DATABASE_NAME);
        }

        // We don't need to alter the object instance pool; we're just modifying this instance
        // already in the pool.

        $dataFetcher = ChildInputQuery::create(null, $this->buildPkeyCriteria())->setFormatter(ModelCriteria::FORMAT_STATEMENT)->find($con);
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
     * @param      ConnectionInterface $con
     * @return void
     * @throws PropelException
     * @see Input::setDeleted()
     * @see Input::isDeleted()
     */
    public function delete(ConnectionInterface $con = null)
    {
        if ($this->isDeleted()) {
            throw new PropelException("This object has already been deleted.");
        }

        if ($con === null) {
            $con = Propel::getServiceContainer()->getWriteConnection(InputTableMap::DATABASE_NAME);
        }

        $con->transaction(function () use ($con) {
            $deleteQuery = ChildInputQuery::create()
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
     * @param      ConnectionInterface $con
     * @return int             The number of rows affected by this insert/update and any referring fk objects' save() operations.
     * @throws PropelException
     * @see doSave()
     */
    public function save(ConnectionInterface $con = null)
    {
        if ($this->isDeleted()) {
            throw new PropelException("You cannot save an object that has been deleted.");
        }

        if ($this->alreadyInSave) {
            return 0;
        }

        if ($con === null) {
            $con = Propel::getServiceContainer()->getWriteConnection(InputTableMap::DATABASE_NAME);
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
                InputTableMap::addInstanceToPool($this);
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
     * @param      ConnectionInterface $con
     * @return int             The number of rows affected by this insert/update and any referring fk objects' save() operations.
     * @throws PropelException
     * @see save()
     */
    protected function doSave(ConnectionInterface $con)
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
    } // doSave()

    /**
     * Insert the row in the database.
     *
     * @param      ConnectionInterface $con
     *
     * @throws PropelException
     * @see doSave()
     */
    protected function doInsert(ConnectionInterface $con)
    {
        $modifiedColumns = array();
        $index = 0;


         // check the columns in natural order for more readable SQL queries
        if ($this->isColumnModified(InputTableMap::COL_EVENTO_ID)) {
            $modifiedColumns[':p' . $index++]  = 'evento_id';
        }
        if ($this->isColumnModified(InputTableMap::COL_PROVA_ID)) {
            $modifiedColumns[':p' . $index++]  = 'prova_id';
        }
        if ($this->isColumnModified(InputTableMap::COL_EQUIPE_ID)) {
            $modifiedColumns[':p' . $index++]  = 'equipe_id';
        }
        if ($this->isColumnModified(InputTableMap::COL_DADOS)) {
            $modifiedColumns[':p' . $index++]  = 'dados';
        }
        if ($this->isColumnModified(InputTableMap::COL_VARS)) {
            $modifiedColumns[':p' . $index++]  = 'vars';
        }
        if ($this->isColumnModified(InputTableMap::COL_PONTOS)) {
            $modifiedColumns[':p' . $index++]  = 'pontos';
        }

        $sql = sprintf(
            'INSERT INTO input (%s) VALUES (%s)',
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
                    case 'equipe_id':
                        $stmt->bindValue($identifier, $this->equipe_id, PDO::PARAM_INT);
                        break;
                    case 'dados':
                        $stmt->bindValue($identifier, $this->dados, PDO::PARAM_STR);
                        break;
                    case 'vars':
                        $stmt->bindValue($identifier, $this->vars, PDO::PARAM_STR);
                        break;
                    case 'pontos':
                        $stmt->bindValue($identifier, $this->pontos, PDO::PARAM_STR);
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
     * @param      ConnectionInterface $con
     *
     * @return Integer Number of updated rows
     * @see doSave()
     */
    protected function doUpdate(ConnectionInterface $con)
    {
        $selectCriteria = $this->buildPkeyCriteria();
        $valuesCriteria = $this->buildCriteria();

        return $selectCriteria->doUpdate($valuesCriteria, $con);
    }

    /**
     * Retrieves a field from the object by name passed in as a string.
     *
     * @param      string $name name
     * @param      string $type The type of fieldname the $name is of:
     *                     one of the class type constants TableMap::TYPE_PHPNAME, TableMap::TYPE_CAMELNAME
     *                     TableMap::TYPE_COLNAME, TableMap::TYPE_FIELDNAME, TableMap::TYPE_NUM.
     *                     Defaults to TableMap::TYPE_PHPNAME.
     * @return mixed Value of field.
     */
    public function getByName($name, $type = TableMap::TYPE_PHPNAME)
    {
        $pos = InputTableMap::translateFieldName($name, $type, TableMap::TYPE_NUM);
        $field = $this->getByPosition($pos);

        return $field;
    }

    /**
     * Retrieves a field from the object by Position as specified in the xml schema.
     * Zero-based.
     *
     * @param      int $pos position in xml schema
     * @return mixed Value of field at $pos
     */
    public function getByPosition($pos)
    {
        switch ($pos) {
            case 0:
                return $this->getEventoId();
                break;
            case 1:
                return $this->getProvaId();
                break;
            case 2:
                return $this->getEquipeId();
                break;
            case 3:
                return $this->getDados();
                break;
            case 4:
                return $this->getVars();
                break;
            case 5:
                return $this->getPontos();
                break;
            default:
                return null;
                break;
        } // switch()
    }

    /**
     * Exports the object as an array.
     *
     * You can specify the key type of the array by passing one of the class
     * type constants.
     *
     * @param     string  $keyType (optional) One of the class type constants TableMap::TYPE_PHPNAME, TableMap::TYPE_CAMELNAME,
     *                    TableMap::TYPE_COLNAME, TableMap::TYPE_FIELDNAME, TableMap::TYPE_NUM.
     *                    Defaults to TableMap::TYPE_PHPNAME.
     * @param     boolean $includeLazyLoadColumns (optional) Whether to include lazy loaded columns. Defaults to TRUE.
     * @param     array $alreadyDumpedObjects List of objects to skip to avoid recursion
     * @param     boolean $includeForeignObjects (optional) Whether to include hydrated related objects. Default to FALSE.
     *
     * @return array an associative array containing the field names (as keys) and field values
     */
    public function toArray($keyType = TableMap::TYPE_PHPNAME, $includeLazyLoadColumns = true, $alreadyDumpedObjects = array(), $includeForeignObjects = false)
    {

        if (isset($alreadyDumpedObjects['Input'][$this->hashCode()])) {
            return '*RECURSION*';
        }
        $alreadyDumpedObjects['Input'][$this->hashCode()] = true;
        $keys = InputTableMap::getFieldNames($keyType);
        $result = array(
            $keys[0] => $this->getEventoId(),
            $keys[1] => $this->getProvaId(),
            $keys[2] => $this->getEquipeId(),
            $keys[3] => $this->getDados(),
            $keys[4] => $this->getVars(),
            $keys[5] => $this->getPontos(),
        );
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
     * @param  string $name
     * @param  mixed  $value field value
     * @param  string $type The type of fieldname the $name is of:
     *                one of the class type constants TableMap::TYPE_PHPNAME, TableMap::TYPE_CAMELNAME
     *                TableMap::TYPE_COLNAME, TableMap::TYPE_FIELDNAME, TableMap::TYPE_NUM.
     *                Defaults to TableMap::TYPE_PHPNAME.
     * @return $this|\Baja\Model\Input
     */
    public function setByName($name, $value, $type = TableMap::TYPE_PHPNAME)
    {
        $pos = InputTableMap::translateFieldName($name, $type, TableMap::TYPE_NUM);

        return $this->setByPosition($pos, $value);
    }

    /**
     * Sets a field from the object by Position as specified in the xml schema.
     * Zero-based.
     *
     * @param  int $pos position in xml schema
     * @param  mixed $value field value
     * @return $this|\Baja\Model\Input
     */
    public function setByPosition($pos, $value)
    {
        switch ($pos) {
            case 0:
                $this->setEventoId($value);
                break;
            case 1:
                $this->setProvaId($value);
                break;
            case 2:
                $this->setEquipeId($value);
                break;
            case 3:
                $this->setDados($value);
                break;
            case 4:
                $this->setVars($value);
                break;
            case 5:
                $this->setPontos($value);
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
     * @param      array  $arr     An array to populate the object from.
     * @param      string $keyType The type of keys the array uses.
     * @return void
     */
    public function fromArray($arr, $keyType = TableMap::TYPE_PHPNAME)
    {
        $keys = InputTableMap::getFieldNames($keyType);

        if (array_key_exists($keys[0], $arr)) {
            $this->setEventoId($arr[$keys[0]]);
        }
        if (array_key_exists($keys[1], $arr)) {
            $this->setProvaId($arr[$keys[1]]);
        }
        if (array_key_exists($keys[2], $arr)) {
            $this->setEquipeId($arr[$keys[2]]);
        }
        if (array_key_exists($keys[3], $arr)) {
            $this->setDados($arr[$keys[3]]);
        }
        if (array_key_exists($keys[4], $arr)) {
            $this->setVars($arr[$keys[4]]);
        }
        if (array_key_exists($keys[5], $arr)) {
            $this->setPontos($arr[$keys[5]]);
        }
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
     * @return $this|\Baja\Model\Input The current object, for fluid interface
     */
    public function importFrom($parser, $data, $keyType = TableMap::TYPE_PHPNAME)
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
     * @return Criteria The Criteria object containing all modified values.
     */
    public function buildCriteria()
    {
        $criteria = new Criteria(InputTableMap::DATABASE_NAME);

        if ($this->isColumnModified(InputTableMap::COL_EVENTO_ID)) {
            $criteria->add(InputTableMap::COL_EVENTO_ID, $this->evento_id);
        }
        if ($this->isColumnModified(InputTableMap::COL_PROVA_ID)) {
            $criteria->add(InputTableMap::COL_PROVA_ID, $this->prova_id);
        }
        if ($this->isColumnModified(InputTableMap::COL_EQUIPE_ID)) {
            $criteria->add(InputTableMap::COL_EQUIPE_ID, $this->equipe_id);
        }
        if ($this->isColumnModified(InputTableMap::COL_DADOS)) {
            $criteria->add(InputTableMap::COL_DADOS, $this->dados);
        }
        if ($this->isColumnModified(InputTableMap::COL_VARS)) {
            $criteria->add(InputTableMap::COL_VARS, $this->vars);
        }
        if ($this->isColumnModified(InputTableMap::COL_PONTOS)) {
            $criteria->add(InputTableMap::COL_PONTOS, $this->pontos);
        }

        return $criteria;
    }

    /**
     * Builds a Criteria object containing the primary key for this object.
     *
     * Unlike buildCriteria() this method includes the primary key values regardless
     * of whether or not they have been modified.
     *
     * @throws LogicException if no primary key is defined
     *
     * @return Criteria The Criteria object containing value(s) for primary key(s).
     */
    public function buildPkeyCriteria()
    {
        $criteria = ChildInputQuery::create();
        $criteria->add(InputTableMap::COL_EVENTO_ID, $this->evento_id);
        $criteria->add(InputTableMap::COL_PROVA_ID, $this->prova_id);
        $criteria->add(InputTableMap::COL_EQUIPE_ID, $this->equipe_id);

        return $criteria;
    }

    /**
     * If the primary key is not null, return the hashcode of the
     * primary key. Otherwise, return the hash code of the object.
     *
     * @return int Hashcode
     */
    public function hashCode()
    {
        $validPk = null !== $this->getEventoId() &&
            null !== $this->getProvaId() &&
            null !== $this->getEquipeId();

        $validPrimaryKeyFKs = 4;
        $primaryKeyFKs = [];

        //relation input_evento_id_prova_id to table prova
        if ($this->aProva && $hash = spl_object_hash($this->aProva)) {
            $primaryKeyFKs[] = $hash;
        } else {
            $validPrimaryKeyFKs = false;
        }

        //relation input_evento_id_equipe_id to table equipe
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
        $pks = array();
        $pks[0] = $this->getEventoId();
        $pks[1] = $this->getProvaId();
        $pks[2] = $this->getEquipeId();

        return $pks;
    }

    /**
     * Set the [composite] primary key.
     *
     * @param      array $keys The elements of the composite key (order must match the order in XML file).
     * @return void
     */
    public function setPrimaryKey($keys)
    {
        $this->setEventoId($keys[0]);
        $this->setProvaId($keys[1]);
        $this->setEquipeId($keys[2]);
    }

    /**
     * Returns true if the primary key for this object is null.
     * @return boolean
     */
    public function isPrimaryKeyNull()
    {
        return (null === $this->getEventoId()) && (null === $this->getProvaId()) && (null === $this->getEquipeId());
    }

    /**
     * Sets contents of passed object to values from current object.
     *
     * If desired, this method can also make copies of all associated (fkey referrers)
     * objects.
     *
     * @param      object $copyObj An object of \Baja\Model\Input (or compatible) type.
     * @param      boolean $deepCopy Whether to also copy all rows that refer (by fkey) to the current row.
     * @param      boolean $makeNew Whether to reset autoincrement PKs and make the object new.
     * @throws PropelException
     */
    public function copyInto($copyObj, $deepCopy = false, $makeNew = true)
    {
        $copyObj->setEventoId($this->getEventoId());
        $copyObj->setProvaId($this->getProvaId());
        $copyObj->setEquipeId($this->getEquipeId());
        $copyObj->setDados($this->getDados());
        $copyObj->setVars($this->getVars());
        $copyObj->setPontos($this->getPontos());
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
     * @param  boolean $deepCopy Whether to also copy all rows that refer (by fkey) to the current row.
     * @return \Baja\Model\Input Clone of current object.
     * @throws PropelException
     */
    public function copy($deepCopy = false)
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
     * @param  ChildProva $v
     * @return $this|\Baja\Model\Input The current object (for fluent API support)
     * @throws PropelException
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
            $v->addInput($this);
        }


        return $this;
    }


    /**
     * Get the associated ChildProva object
     *
     * @param  ConnectionInterface $con Optional Connection object.
     * @return ChildProva The associated ChildProva object.
     * @throws PropelException
     */
    public function getProva(ConnectionInterface $con = null)
    {
        if ($this->aProva === null && (($this->evento_id !== "" && $this->evento_id !== null) && ($this->prova_id !== "" && $this->prova_id !== null))) {
            $this->aProva = ChildProvaQuery::create()->findPk(array($this->evento_id, $this->prova_id), $con);
            /* The following can be used additionally to
                guarantee the related object contains a reference
                to this object.  This level of coupling may, however, be
                undesirable since it could result in an only partially populated collection
                in the referenced object.
                $this->aProva->addInputs($this);
             */
        }

        return $this->aProva;
    }

    /**
     * Declares an association between this object and a ChildEquipe object.
     *
     * @param  ChildEquipe $v
     * @return $this|\Baja\Model\Input The current object (for fluent API support)
     * @throws PropelException
     */
    public function setEquipe(ChildEquipe $v = null)
    {
        if ($v === null) {
            $this->setEventoId(NULL);
        } else {
            $this->setEventoId($v->getEventoId());
        }

        if ($v === null) {
            $this->setEquipeId(NULL);
        } else {
            $this->setEquipeId($v->getEquipeId());
        }

        $this->aEquipe = $v;

        // Add binding for other direction of this n:n relationship.
        // If this object has already been added to the ChildEquipe object, it will not be re-added.
        if ($v !== null) {
            $v->addInput($this);
        }


        return $this;
    }


    /**
     * Get the associated ChildEquipe object
     *
     * @param  ConnectionInterface $con Optional Connection object.
     * @return ChildEquipe The associated ChildEquipe object.
     * @throws PropelException
     */
    public function getEquipe(ConnectionInterface $con = null)
    {
        if ($this->aEquipe === null && (($this->evento_id !== "" && $this->evento_id !== null) && $this->equipe_id != 0)) {
            $this->aEquipe = ChildEquipeQuery::create()->findPk(array($this->evento_id, $this->equipe_id), $con);
            /* The following can be used additionally to
                guarantee the related object contains a reference
                to this object.  This level of coupling may, however, be
                undesirable since it could result in an only partially populated collection
                in the referenced object.
                $this->aEquipe->addInputs($this);
             */
        }

        return $this->aEquipe;
    }

    /**
     * Clears the current object, sets all attributes to their default values and removes
     * outgoing references as well as back-references (from other objects to this one. Results probably in a database
     * change of those foreign objects when you call `save` there).
     */
    public function clear()
    {
        if (null !== $this->aProva) {
            $this->aProva->removeInput($this);
        }
        if (null !== $this->aEquipe) {
            $this->aEquipe->removeInput($this);
        }
        $this->evento_id = null;
        $this->prova_id = null;
        $this->equipe_id = null;
        $this->dados = null;
        $this->vars = null;
        $this->pontos = null;
        $this->alreadyInSave = false;
        $this->clearAllReferences();
        $this->resetModified();
        $this->setNew(true);
        $this->setDeleted(false);
    }

    /**
     * Resets all references and back-references to other model objects or collections of model objects.
     *
     * This method is used to reset all php object references (not the actual reference in the database).
     * Necessary for object serialisation.
     *
     * @param      boolean $deep Whether to also clear the references on all referrer objects.
     */
    public function clearAllReferences($deep = false)
    {
        if ($deep) {
        } // if ($deep)

        $this->aProva = null;
        $this->aEquipe = null;
    }

    /**
     * Return the string representation of this object
     *
     * @return string
     */
    public function __toString()
    {
        return (string) $this->exportTo(InputTableMap::DEFAULT_STRING_FORMAT);
    }

    /**
     * Code to be run before persisting the object
     * @param  ConnectionInterface $con
     * @return boolean
     */
    public function preSave(ConnectionInterface $con = null)
    {
        if (is_callable('parent::preSave')) {
            return parent::preSave($con);
        }
        return true;
    }

    /**
     * Code to be run after persisting the object
     * @param ConnectionInterface $con
     */
    public function postSave(ConnectionInterface $con = null)
    {
        if (is_callable('parent::postSave')) {
            parent::postSave($con);
        }
    }

    /**
     * Code to be run before inserting to database
     * @param  ConnectionInterface $con
     * @return boolean
     */
    public function preInsert(ConnectionInterface $con = null)
    {
        if (is_callable('parent::preInsert')) {
            return parent::preInsert($con);
        }
        return true;
    }

    /**
     * Code to be run after inserting to database
     * @param ConnectionInterface $con
     */
    public function postInsert(ConnectionInterface $con = null)
    {
        if (is_callable('parent::postInsert')) {
            parent::postInsert($con);
        }
    }

    /**
     * Code to be run before updating the object in database
     * @param  ConnectionInterface $con
     * @return boolean
     */
    public function preUpdate(ConnectionInterface $con = null)
    {
        if (is_callable('parent::preUpdate')) {
            return parent::preUpdate($con);
        }
        return true;
    }

    /**
     * Code to be run after updating the object in database
     * @param ConnectionInterface $con
     */
    public function postUpdate(ConnectionInterface $con = null)
    {
        if (is_callable('parent::postUpdate')) {
            parent::postUpdate($con);
        }
    }

    /**
     * Code to be run before deleting the object in database
     * @param  ConnectionInterface $con
     * @return boolean
     */
    public function preDelete(ConnectionInterface $con = null)
    {
        if (is_callable('parent::preDelete')) {
            return parent::preDelete($con);
        }
        return true;
    }

    /**
     * Code to be run after deleting the object in database
     * @param ConnectionInterface $con
     */
    public function postDelete(ConnectionInterface $con = null)
    {
        if (is_callable('parent::postDelete')) {
            parent::postDelete($con);
        }
    }


    /**
     * Derived method to catches calls to undefined methods.
     *
     * Provides magic import/export method support (fromXML()/toXML(), fromYAML()/toYAML(), etc.).
     * Allows to define default __call() behavior if you overwrite __call()
     *
     * @param string $name
     * @param mixed  $params
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

            return $this->importFrom($format, reset($params));
        }

        if (0 === strpos($name, 'to')) {
            $format = substr($name, 2);
            $includeLazyLoadColumns = isset($params[0]) ? $params[0] : true;

            return $this->exportTo($format, $includeLazyLoadColumns);
        }

        throw new BadMethodCallException(sprintf('Call to undefined method: %s.', $name));
    }

}
