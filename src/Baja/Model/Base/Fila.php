<?php

namespace Baja\Model\Base;

use \DateTime;
use \Exception;
use \PDO;
use Baja\Model\Evento as ChildEvento;
use Baja\Model\EventoQuery as ChildEventoQuery;
use Baja\Model\FilaQuery as ChildFilaQuery;
use Baja\Model\Map\FilaTableMap;
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
 * Base class that represents a row from the 'fila' table.
 *
 *
 *
 * @package    propel.generator.Baja.Model.Base
 */
abstract class Fila implements ActiveRecordInterface
{
    /**
     * TableMap class name
     */
    const TABLE_MAP = '\\Baja\\Model\\Map\\FilaTableMap';


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
     * The value for the fila_id field.
     *
     * @var        int
     */
    protected $fila_id;

    /**
     * The value for the nome field.
     *
     * @var        string
     */
    protected $nome;

    /**
     * The value for the status field.
     *
     * Note: this column has a database default value of: 0
     * @var        int
     */
    protected $status;

    /**
     * The value for the permite_troca field.
     *
     * Note: this column has a database default value of: false
     * @var        boolean
     */
    protected $permite_troca;

    /**
     * The value for the permite_multiplas field.
     *
     * Note: this column has a database default value of: false
     * @var        boolean
     */
    protected $permite_multiplas;

    /**
     * The value for the permite_chamada_espera field.
     *
     * Note: this column has a database default value of: true
     * @var        boolean
     */
    protected $permite_chamada_espera;

    /**
     * The value for the tempo_espera field.
     *
     * @var        int
     */
    protected $tempo_espera;

    /**
     * The value for the abertura_programada field.
     *
     * @var        DateTime
     */
    protected $abertura_programada;

    /**
     * The value for the fechamento_programado field.
     *
     * @var        DateTime
     */
    protected $fechamento_programado;

    /**
     * @var        ChildEvento
     */
    protected $aEvento;

    /**
     * Flag to prevent endless save loop, if this object is referenced
     * by another object which falls in this transaction.
     *
     * @var boolean
     */
    protected $alreadyInSave = false;

    /**
     * Applies default values to this object.
     * This method should be called from the object's constructor (or
     * equivalent initialization method).
     * @see __construct()
     */
    public function applyDefaultValues()
    {
        $this->status = 0;
        $this->permite_troca = false;
        $this->permite_multiplas = false;
        $this->permite_chamada_espera = true;
    }

    /**
     * Initializes internal state of Baja\Model\Base\Fila object.
     * @see applyDefaults()
     */
    public function __construct()
    {
        $this->applyDefaultValues();
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
     * Compares this with another <code>Fila</code> instance.  If
     * <code>obj</code> is an instance of <code>Fila</code>, delegates to
     * <code>equals(Fila)</code>.  Otherwise, returns <code>false</code>.
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
     * @return $this The current object, for fluid interface
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
     * @return void
     */
    protected function log($msg, $priority = Propel::LOG_INFO)
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
     * Get the [fila_id] column value.
     *
     * @return int
     */
    public function getFilaId()
    {
        return $this->fila_id;
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
     * Get the [status] column value.
     *
     * @return int
     */
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * Get the [permite_troca] column value.
     *
     * @return boolean
     */
    public function getPermiteTroca()
    {
        return $this->permite_troca;
    }

    /**
     * Get the [permite_troca] column value.
     *
     * @return boolean
     */
    public function isPermiteTroca()
    {
        return $this->getPermiteTroca();
    }

    /**
     * Get the [permite_multiplas] column value.
     *
     * @return boolean
     */
    public function getPermiteMultiplas()
    {
        return $this->permite_multiplas;
    }

    /**
     * Get the [permite_multiplas] column value.
     *
     * @return boolean
     */
    public function isPermiteMultiplas()
    {
        return $this->getPermiteMultiplas();
    }

    /**
     * Get the [permite_chamada_espera] column value.
     *
     * @return boolean
     */
    public function getPermiteChamadaEspera()
    {
        return $this->permite_chamada_espera;
    }

    /**
     * Get the [permite_chamada_espera] column value.
     *
     * @return boolean
     */
    public function isPermiteChamadaEspera()
    {
        return $this->getPermiteChamadaEspera();
    }

    /**
     * Get the [tempo_espera] column value.
     *
     * @return int
     */
    public function getTempoEspera()
    {
        return $this->tempo_espera;
    }

    /**
     * Get the [optionally formatted] temporal [abertura_programada] column value.
     *
     *
     * @param      string|null $format The date/time format string (either date()-style or strftime()-style).
     *                            If format is NULL, then the raw DateTime object will be returned.
     *
     * @return string|DateTime Formatted date/time value as string or DateTime object (if format is NULL), NULL if column is NULL, and 0 if column value is 0000-00-00 00:00:00
     *
     * @throws PropelException - if unable to parse/validate the date/time value.
     */
    public function getAberturaProgramada($format = NULL)
    {
        if ($format === null) {
            return $this->abertura_programada;
        } else {
            return $this->abertura_programada instanceof \DateTimeInterface ? $this->abertura_programada->format($format) : null;
        }
    }

    /**
     * Get the [optionally formatted] temporal [fechamento_programado] column value.
     *
     *
     * @param      string|null $format The date/time format string (either date()-style or strftime()-style).
     *                            If format is NULL, then the raw DateTime object will be returned.
     *
     * @return string|DateTime Formatted date/time value as string or DateTime object (if format is NULL), NULL if column is NULL, and 0 if column value is 0000-00-00 00:00:00
     *
     * @throws PropelException - if unable to parse/validate the date/time value.
     */
    public function getFechamentoProgramado($format = NULL)
    {
        if ($format === null) {
            return $this->fechamento_programado;
        } else {
            return $this->fechamento_programado instanceof \DateTimeInterface ? $this->fechamento_programado->format($format) : null;
        }
    }

    /**
     * Set the value of [evento_id] column.
     *
     * @param string $v New value
     * @return $this|\Baja\Model\Fila The current object (for fluent API support)
     */
    public function setEventoId($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->evento_id !== $v) {
            $this->evento_id = $v;
            $this->modifiedColumns[FilaTableMap::COL_EVENTO_ID] = true;
        }

        if ($this->aEvento !== null && $this->aEvento->getEventoId() !== $v) {
            $this->aEvento = null;
        }

        return $this;
    } // setEventoId()

    /**
     * Set the value of [fila_id] column.
     *
     * @param int $v New value
     * @return $this|\Baja\Model\Fila The current object (for fluent API support)
     */
    public function setFilaId($v)
    {
        if ($v !== null) {
            $v = (int) $v;
        }

        if ($this->fila_id !== $v) {
            $this->fila_id = $v;
            $this->modifiedColumns[FilaTableMap::COL_FILA_ID] = true;
        }

        return $this;
    } // setFilaId()

    /**
     * Set the value of [nome] column.
     *
     * @param string $v New value
     * @return $this|\Baja\Model\Fila The current object (for fluent API support)
     */
    public function setNome($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->nome !== $v) {
            $this->nome = $v;
            $this->modifiedColumns[FilaTableMap::COL_NOME] = true;
        }

        return $this;
    } // setNome()

    /**
     * Set the value of [status] column.
     *
     * @param int $v New value
     * @return $this|\Baja\Model\Fila The current object (for fluent API support)
     */
    public function setStatus($v)
    {
        if ($v !== null) {
            $v = (int) $v;
        }

        if ($this->status !== $v) {
            $this->status = $v;
            $this->modifiedColumns[FilaTableMap::COL_STATUS] = true;
        }

        return $this;
    } // setStatus()

    /**
     * Sets the value of the [permite_troca] column.
     * Non-boolean arguments are converted using the following rules:
     *   * 1, '1', 'true',  'on',  and 'yes' are converted to boolean true
     *   * 0, '0', 'false', 'off', and 'no'  are converted to boolean false
     * Check on string values is case insensitive (so 'FaLsE' is seen as 'false').
     *
     * @param  boolean|integer|string $v The new value
     * @return $this|\Baja\Model\Fila The current object (for fluent API support)
     */
    public function setPermiteTroca($v)
    {
        if ($v !== null) {
            if (is_string($v)) {
                $v = in_array(strtolower($v), array('false', 'off', '-', 'no', 'n', '0', '')) ? false : true;
            } else {
                $v = (boolean) $v;
            }
        }

        if ($this->permite_troca !== $v) {
            $this->permite_troca = $v;
            $this->modifiedColumns[FilaTableMap::COL_PERMITE_TROCA] = true;
        }

        return $this;
    } // setPermiteTroca()

    /**
     * Sets the value of the [permite_multiplas] column.
     * Non-boolean arguments are converted using the following rules:
     *   * 1, '1', 'true',  'on',  and 'yes' are converted to boolean true
     *   * 0, '0', 'false', 'off', and 'no'  are converted to boolean false
     * Check on string values is case insensitive (so 'FaLsE' is seen as 'false').
     *
     * @param  boolean|integer|string $v The new value
     * @return $this|\Baja\Model\Fila The current object (for fluent API support)
     */
    public function setPermiteMultiplas($v)
    {
        if ($v !== null) {
            if (is_string($v)) {
                $v = in_array(strtolower($v), array('false', 'off', '-', 'no', 'n', '0', '')) ? false : true;
            } else {
                $v = (boolean) $v;
            }
        }

        if ($this->permite_multiplas !== $v) {
            $this->permite_multiplas = $v;
            $this->modifiedColumns[FilaTableMap::COL_PERMITE_MULTIPLAS] = true;
        }

        return $this;
    } // setPermiteMultiplas()

    /**
     * Sets the value of the [permite_chamada_espera] column.
     * Non-boolean arguments are converted using the following rules:
     *   * 1, '1', 'true',  'on',  and 'yes' are converted to boolean true
     *   * 0, '0', 'false', 'off', and 'no'  are converted to boolean false
     * Check on string values is case insensitive (so 'FaLsE' is seen as 'false').
     *
     * @param  boolean|integer|string $v The new value
     * @return $this|\Baja\Model\Fila The current object (for fluent API support)
     */
    public function setPermiteChamadaEspera($v)
    {
        if ($v !== null) {
            if (is_string($v)) {
                $v = in_array(strtolower($v), array('false', 'off', '-', 'no', 'n', '0', '')) ? false : true;
            } else {
                $v = (boolean) $v;
            }
        }

        if ($this->permite_chamada_espera !== $v) {
            $this->permite_chamada_espera = $v;
            $this->modifiedColumns[FilaTableMap::COL_PERMITE_CHAMADA_ESPERA] = true;
        }

        return $this;
    } // setPermiteChamadaEspera()

    /**
     * Set the value of [tempo_espera] column.
     *
     * @param int|null $v New value
     * @return $this|\Baja\Model\Fila The current object (for fluent API support)
     */
    public function setTempoEspera($v)
    {
        if ($v !== null) {
            $v = (int) $v;
        }

        if ($this->tempo_espera !== $v) {
            $this->tempo_espera = $v;
            $this->modifiedColumns[FilaTableMap::COL_TEMPO_ESPERA] = true;
        }

        return $this;
    } // setTempoEspera()

    /**
     * Sets the value of [abertura_programada] column to a normalized version of the date/time value specified.
     *
     * @param  mixed $v string, integer (timestamp), or \DateTimeInterface value.
     *               Empty strings are treated as NULL.
     * @return $this|\Baja\Model\Fila The current object (for fluent API support)
     */
    public function setAberturaProgramada($v)
    {
        $dt = PropelDateTime::newInstance($v, null, 'DateTime');
        if ($this->abertura_programada !== null || $dt !== null) {
            if ($this->abertura_programada === null || $dt === null || $dt->format("Y-m-d H:i:s.u") !== $this->abertura_programada->format("Y-m-d H:i:s.u")) {
                $this->abertura_programada = $dt === null ? null : clone $dt;
                $this->modifiedColumns[FilaTableMap::COL_ABERTURA_PROGRAMADA] = true;
            }
        } // if either are not null

        return $this;
    } // setAberturaProgramada()

    /**
     * Sets the value of [fechamento_programado] column to a normalized version of the date/time value specified.
     *
     * @param  mixed $v string, integer (timestamp), or \DateTimeInterface value.
     *               Empty strings are treated as NULL.
     * @return $this|\Baja\Model\Fila The current object (for fluent API support)
     */
    public function setFechamentoProgramado($v)
    {
        $dt = PropelDateTime::newInstance($v, null, 'DateTime');
        if ($this->fechamento_programado !== null || $dt !== null) {
            if ($this->fechamento_programado === null || $dt === null || $dt->format("Y-m-d H:i:s.u") !== $this->fechamento_programado->format("Y-m-d H:i:s.u")) {
                $this->fechamento_programado = $dt === null ? null : clone $dt;
                $this->modifiedColumns[FilaTableMap::COL_FECHAMENTO_PROGRAMADO] = true;
            }
        } // if either are not null

        return $this;
    } // setFechamentoProgramado()

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
            if ($this->status !== 0) {
                return false;
            }

            if ($this->permite_troca !== false) {
                return false;
            }

            if ($this->permite_multiplas !== false) {
                return false;
            }

            if ($this->permite_chamada_espera !== true) {
                return false;
            }

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

            $col = $row[TableMap::TYPE_NUM == $indexType ? 0 + $startcol : FilaTableMap::translateFieldName('EventoId', TableMap::TYPE_PHPNAME, $indexType)];
            $this->evento_id = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 1 + $startcol : FilaTableMap::translateFieldName('FilaId', TableMap::TYPE_PHPNAME, $indexType)];
            $this->fila_id = (null !== $col) ? (int) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 2 + $startcol : FilaTableMap::translateFieldName('Nome', TableMap::TYPE_PHPNAME, $indexType)];
            $this->nome = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 3 + $startcol : FilaTableMap::translateFieldName('Status', TableMap::TYPE_PHPNAME, $indexType)];
            $this->status = (null !== $col) ? (int) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 4 + $startcol : FilaTableMap::translateFieldName('PermiteTroca', TableMap::TYPE_PHPNAME, $indexType)];
            $this->permite_troca = (null !== $col) ? (boolean) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 5 + $startcol : FilaTableMap::translateFieldName('PermiteMultiplas', TableMap::TYPE_PHPNAME, $indexType)];
            $this->permite_multiplas = (null !== $col) ? (boolean) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 6 + $startcol : FilaTableMap::translateFieldName('PermiteChamadaEspera', TableMap::TYPE_PHPNAME, $indexType)];
            $this->permite_chamada_espera = (null !== $col) ? (boolean) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 7 + $startcol : FilaTableMap::translateFieldName('TempoEspera', TableMap::TYPE_PHPNAME, $indexType)];
            $this->tempo_espera = (null !== $col) ? (int) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 8 + $startcol : FilaTableMap::translateFieldName('AberturaProgramada', TableMap::TYPE_PHPNAME, $indexType)];
            if ($col === '0000-00-00 00:00:00') {
                $col = null;
            }
            $this->abertura_programada = (null !== $col) ? PropelDateTime::newInstance($col, null, 'DateTime') : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 9 + $startcol : FilaTableMap::translateFieldName('FechamentoProgramado', TableMap::TYPE_PHPNAME, $indexType)];
            if ($col === '0000-00-00 00:00:00') {
                $col = null;
            }
            $this->fechamento_programado = (null !== $col) ? PropelDateTime::newInstance($col, null, 'DateTime') : null;
            $this->resetModified();

            $this->setNew(false);

            if ($rehydrate) {
                $this->ensureConsistency();
            }

            return $startcol + 10; // 10 = FilaTableMap::NUM_HYDRATE_COLUMNS.

        } catch (Exception $e) {
            throw new PropelException(sprintf('Error populating %s object', '\\Baja\\Model\\Fila'), 0, $e);
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
        if ($this->aEvento !== null && $this->evento_id !== $this->aEvento->getEventoId()) {
            $this->aEvento = null;
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
            $con = Propel::getServiceContainer()->getReadConnection(FilaTableMap::DATABASE_NAME);
        }

        // We don't need to alter the object instance pool; we're just modifying this instance
        // already in the pool.

        $dataFetcher = ChildFilaQuery::create(null, $this->buildPkeyCriteria())->setFormatter(ModelCriteria::FORMAT_STATEMENT)->find($con);
        $row = $dataFetcher->fetch();
        $dataFetcher->close();
        if (!$row) {
            throw new PropelException('Cannot find matching row in the database to reload object values.');
        }
        $this->hydrate($row, 0, true, $dataFetcher->getIndexType()); // rehydrate

        if ($deep) {  // also de-associate any related objects?

            $this->aEvento = null;
        } // if (deep)
    }

    /**
     * Removes this object from datastore and sets delete attribute.
     *
     * @param      ConnectionInterface $con
     * @return void
     * @throws PropelException
     * @see Fila::setDeleted()
     * @see Fila::isDeleted()
     */
    public function delete(ConnectionInterface $con = null)
    {
        if ($this->isDeleted()) {
            throw new PropelException("This object has already been deleted.");
        }

        if ($con === null) {
            $con = Propel::getServiceContainer()->getWriteConnection(FilaTableMap::DATABASE_NAME);
        }

        $con->transaction(function () use ($con) {
            $deleteQuery = ChildFilaQuery::create()
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
            $con = Propel::getServiceContainer()->getWriteConnection(FilaTableMap::DATABASE_NAME);
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
                FilaTableMap::addInstanceToPool($this);
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

            if ($this->aEvento !== null) {
                if ($this->aEvento->isModified() || $this->aEvento->isNew()) {
                    $affectedRows += $this->aEvento->save($con);
                }
                $this->setEvento($this->aEvento);
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
        if ($this->isColumnModified(FilaTableMap::COL_EVENTO_ID)) {
            $modifiedColumns[':p' . $index++]  = 'evento_id';
        }
        if ($this->isColumnModified(FilaTableMap::COL_FILA_ID)) {
            $modifiedColumns[':p' . $index++]  = 'fila_id';
        }
        if ($this->isColumnModified(FilaTableMap::COL_NOME)) {
            $modifiedColumns[':p' . $index++]  = 'nome';
        }
        if ($this->isColumnModified(FilaTableMap::COL_STATUS)) {
            $modifiedColumns[':p' . $index++]  = 'status';
        }
        if ($this->isColumnModified(FilaTableMap::COL_PERMITE_TROCA)) {
            $modifiedColumns[':p' . $index++]  = 'permite_troca';
        }
        if ($this->isColumnModified(FilaTableMap::COL_PERMITE_MULTIPLAS)) {
            $modifiedColumns[':p' . $index++]  = 'permite_multiplas';
        }
        if ($this->isColumnModified(FilaTableMap::COL_PERMITE_CHAMADA_ESPERA)) {
            $modifiedColumns[':p' . $index++]  = 'permite_chamada_espera';
        }
        if ($this->isColumnModified(FilaTableMap::COL_TEMPO_ESPERA)) {
            $modifiedColumns[':p' . $index++]  = 'tempo_espera';
        }
        if ($this->isColumnModified(FilaTableMap::COL_ABERTURA_PROGRAMADA)) {
            $modifiedColumns[':p' . $index++]  = 'abertura_programada';
        }
        if ($this->isColumnModified(FilaTableMap::COL_FECHAMENTO_PROGRAMADO)) {
            $modifiedColumns[':p' . $index++]  = 'fechamento_programado';
        }

        $sql = sprintf(
            'INSERT INTO fila (%s) VALUES (%s)',
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
                    case 'fila_id':
                        $stmt->bindValue($identifier, $this->fila_id, PDO::PARAM_INT);
                        break;
                    case 'nome':
                        $stmt->bindValue($identifier, $this->nome, PDO::PARAM_STR);
                        break;
                    case 'status':
                        $stmt->bindValue($identifier, $this->status, PDO::PARAM_INT);
                        break;
                    case 'permite_troca':
                        $stmt->bindValue($identifier, (int) $this->permite_troca, PDO::PARAM_INT);
                        break;
                    case 'permite_multiplas':
                        $stmt->bindValue($identifier, (int) $this->permite_multiplas, PDO::PARAM_INT);
                        break;
                    case 'permite_chamada_espera':
                        $stmt->bindValue($identifier, (int) $this->permite_chamada_espera, PDO::PARAM_INT);
                        break;
                    case 'tempo_espera':
                        $stmt->bindValue($identifier, $this->tempo_espera, PDO::PARAM_INT);
                        break;
                    case 'abertura_programada':
                        $stmt->bindValue($identifier, $this->abertura_programada ? $this->abertura_programada->format("Y-m-d H:i:s.u") : null, PDO::PARAM_STR);
                        break;
                    case 'fechamento_programado':
                        $stmt->bindValue($identifier, $this->fechamento_programado ? $this->fechamento_programado->format("Y-m-d H:i:s.u") : null, PDO::PARAM_STR);
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
        $pos = FilaTableMap::translateFieldName($name, $type, TableMap::TYPE_NUM);
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
                return $this->getFilaId();
                break;
            case 2:
                return $this->getNome();
                break;
            case 3:
                return $this->getStatus();
                break;
            case 4:
                return $this->getPermiteTroca();
                break;
            case 5:
                return $this->getPermiteMultiplas();
                break;
            case 6:
                return $this->getPermiteChamadaEspera();
                break;
            case 7:
                return $this->getTempoEspera();
                break;
            case 8:
                return $this->getAberturaProgramada();
                break;
            case 9:
                return $this->getFechamentoProgramado();
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

        if (isset($alreadyDumpedObjects['Fila'][$this->hashCode()])) {
            return '*RECURSION*';
        }
        $alreadyDumpedObjects['Fila'][$this->hashCode()] = true;
        $keys = FilaTableMap::getFieldNames($keyType);
        $result = array(
            $keys[0] => $this->getEventoId(),
            $keys[1] => $this->getFilaId(),
            $keys[2] => $this->getNome(),
            $keys[3] => $this->getStatus(),
            $keys[4] => $this->getPermiteTroca(),
            $keys[5] => $this->getPermiteMultiplas(),
            $keys[6] => $this->getPermiteChamadaEspera(),
            $keys[7] => $this->getTempoEspera(),
            $keys[8] => $this->getAberturaProgramada(),
            $keys[9] => $this->getFechamentoProgramado(),
        );
        if ($result[$keys[8]] instanceof \DateTimeInterface) {
            $result[$keys[8]] = $result[$keys[8]]->format('c');
        }

        if ($result[$keys[9]] instanceof \DateTimeInterface) {
            $result[$keys[9]] = $result[$keys[9]]->format('c');
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
     * @return $this|\Baja\Model\Fila
     */
    public function setByName($name, $value, $type = TableMap::TYPE_PHPNAME)
    {
        $pos = FilaTableMap::translateFieldName($name, $type, TableMap::TYPE_NUM);

        return $this->setByPosition($pos, $value);
    }

    /**
     * Sets a field from the object by Position as specified in the xml schema.
     * Zero-based.
     *
     * @param  int $pos position in xml schema
     * @param  mixed $value field value
     * @return $this|\Baja\Model\Fila
     */
    public function setByPosition($pos, $value)
    {
        switch ($pos) {
            case 0:
                $this->setEventoId($value);
                break;
            case 1:
                $this->setFilaId($value);
                break;
            case 2:
                $this->setNome($value);
                break;
            case 3:
                $this->setStatus($value);
                break;
            case 4:
                $this->setPermiteTroca($value);
                break;
            case 5:
                $this->setPermiteMultiplas($value);
                break;
            case 6:
                $this->setPermiteChamadaEspera($value);
                break;
            case 7:
                $this->setTempoEspera($value);
                break;
            case 8:
                $this->setAberturaProgramada($value);
                break;
            case 9:
                $this->setFechamentoProgramado($value);
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
        $keys = FilaTableMap::getFieldNames($keyType);

        if (array_key_exists($keys[0], $arr)) {
            $this->setEventoId($arr[$keys[0]]);
        }
        if (array_key_exists($keys[1], $arr)) {
            $this->setFilaId($arr[$keys[1]]);
        }
        if (array_key_exists($keys[2], $arr)) {
            $this->setNome($arr[$keys[2]]);
        }
        if (array_key_exists($keys[3], $arr)) {
            $this->setStatus($arr[$keys[3]]);
        }
        if (array_key_exists($keys[4], $arr)) {
            $this->setPermiteTroca($arr[$keys[4]]);
        }
        if (array_key_exists($keys[5], $arr)) {
            $this->setPermiteMultiplas($arr[$keys[5]]);
        }
        if (array_key_exists($keys[6], $arr)) {
            $this->setPermiteChamadaEspera($arr[$keys[6]]);
        }
        if (array_key_exists($keys[7], $arr)) {
            $this->setTempoEspera($arr[$keys[7]]);
        }
        if (array_key_exists($keys[8], $arr)) {
            $this->setAberturaProgramada($arr[$keys[8]]);
        }
        if (array_key_exists($keys[9], $arr)) {
            $this->setFechamentoProgramado($arr[$keys[9]]);
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
     * @return $this|\Baja\Model\Fila The current object, for fluid interface
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
        $criteria = new Criteria(FilaTableMap::DATABASE_NAME);

        if ($this->isColumnModified(FilaTableMap::COL_EVENTO_ID)) {
            $criteria->add(FilaTableMap::COL_EVENTO_ID, $this->evento_id);
        }
        if ($this->isColumnModified(FilaTableMap::COL_FILA_ID)) {
            $criteria->add(FilaTableMap::COL_FILA_ID, $this->fila_id);
        }
        if ($this->isColumnModified(FilaTableMap::COL_NOME)) {
            $criteria->add(FilaTableMap::COL_NOME, $this->nome);
        }
        if ($this->isColumnModified(FilaTableMap::COL_STATUS)) {
            $criteria->add(FilaTableMap::COL_STATUS, $this->status);
        }
        if ($this->isColumnModified(FilaTableMap::COL_PERMITE_TROCA)) {
            $criteria->add(FilaTableMap::COL_PERMITE_TROCA, $this->permite_troca);
        }
        if ($this->isColumnModified(FilaTableMap::COL_PERMITE_MULTIPLAS)) {
            $criteria->add(FilaTableMap::COL_PERMITE_MULTIPLAS, $this->permite_multiplas);
        }
        if ($this->isColumnModified(FilaTableMap::COL_PERMITE_CHAMADA_ESPERA)) {
            $criteria->add(FilaTableMap::COL_PERMITE_CHAMADA_ESPERA, $this->permite_chamada_espera);
        }
        if ($this->isColumnModified(FilaTableMap::COL_TEMPO_ESPERA)) {
            $criteria->add(FilaTableMap::COL_TEMPO_ESPERA, $this->tempo_espera);
        }
        if ($this->isColumnModified(FilaTableMap::COL_ABERTURA_PROGRAMADA)) {
            $criteria->add(FilaTableMap::COL_ABERTURA_PROGRAMADA, $this->abertura_programada);
        }
        if ($this->isColumnModified(FilaTableMap::COL_FECHAMENTO_PROGRAMADO)) {
            $criteria->add(FilaTableMap::COL_FECHAMENTO_PROGRAMADO, $this->fechamento_programado);
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
        $criteria = ChildFilaQuery::create();
        $criteria->add(FilaTableMap::COL_EVENTO_ID, $this->evento_id);
        $criteria->add(FilaTableMap::COL_FILA_ID, $this->fila_id);

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
            null !== $this->getFilaId();

        $validPrimaryKeyFKs = 1;
        $primaryKeyFKs = [];

        //relation fila_evento_id to table evento
        if ($this->aEvento && $hash = spl_object_hash($this->aEvento)) {
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
        $pks[1] = $this->getFilaId();

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
        $this->setFilaId($keys[1]);
    }

    /**
     * Returns true if the primary key for this object is null.
     * @return boolean
     */
    public function isPrimaryKeyNull()
    {
        return (null === $this->getEventoId()) && (null === $this->getFilaId());
    }

    /**
     * Sets contents of passed object to values from current object.
     *
     * If desired, this method can also make copies of all associated (fkey referrers)
     * objects.
     *
     * @param      object $copyObj An object of \Baja\Model\Fila (or compatible) type.
     * @param      boolean $deepCopy Whether to also copy all rows that refer (by fkey) to the current row.
     * @param      boolean $makeNew Whether to reset autoincrement PKs and make the object new.
     * @throws PropelException
     */
    public function copyInto($copyObj, $deepCopy = false, $makeNew = true)
    {
        $copyObj->setEventoId($this->getEventoId());
        $copyObj->setFilaId($this->getFilaId());
        $copyObj->setNome($this->getNome());
        $copyObj->setStatus($this->getStatus());
        $copyObj->setPermiteTroca($this->getPermiteTroca());
        $copyObj->setPermiteMultiplas($this->getPermiteMultiplas());
        $copyObj->setPermiteChamadaEspera($this->getPermiteChamadaEspera());
        $copyObj->setTempoEspera($this->getTempoEspera());
        $copyObj->setAberturaProgramada($this->getAberturaProgramada());
        $copyObj->setFechamentoProgramado($this->getFechamentoProgramado());
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
     * @return \Baja\Model\Fila Clone of current object.
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
     * Declares an association between this object and a ChildEvento object.
     *
     * @param  ChildEvento $v
     * @return $this|\Baja\Model\Fila The current object (for fluent API support)
     * @throws PropelException
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
            $v->addFila($this);
        }


        return $this;
    }


    /**
     * Get the associated ChildEvento object
     *
     * @param  ConnectionInterface $con Optional Connection object.
     * @return ChildEvento The associated ChildEvento object.
     * @throws PropelException
     */
    public function getEvento(ConnectionInterface $con = null)
    {
        if ($this->aEvento === null && (($this->evento_id !== "" && $this->evento_id !== null))) {
            $this->aEvento = ChildEventoQuery::create()->findPk($this->evento_id, $con);
            /* The following can be used additionally to
                guarantee the related object contains a reference
                to this object.  This level of coupling may, however, be
                undesirable since it could result in an only partially populated collection
                in the referenced object.
                $this->aEvento->addFilas($this);
             */
        }

        return $this->aEvento;
    }

    /**
     * Clears the current object, sets all attributes to their default values and removes
     * outgoing references as well as back-references (from other objects to this one. Results probably in a database
     * change of those foreign objects when you call `save` there).
     */
    public function clear()
    {
        if (null !== $this->aEvento) {
            $this->aEvento->removeFila($this);
        }
        $this->evento_id = null;
        $this->fila_id = null;
        $this->nome = null;
        $this->status = null;
        $this->permite_troca = null;
        $this->permite_multiplas = null;
        $this->permite_chamada_espera = null;
        $this->tempo_espera = null;
        $this->abertura_programada = null;
        $this->fechamento_programado = null;
        $this->alreadyInSave = false;
        $this->clearAllReferences();
        $this->applyDefaultValues();
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

        $this->aEvento = null;
    }

    /**
     * Return the string representation of this object
     *
     * @return string
     */
    public function __toString()
    {
        return (string) $this->exportTo(FilaTableMap::DEFAULT_STRING_FORMAT);
    }

    /**
     * Code to be run before persisting the object
     * @param  ConnectionInterface $con
     * @return boolean
     */
    public function preSave(ConnectionInterface $con = null)
    {
                return true;
    }

    /**
     * Code to be run after persisting the object
     * @param ConnectionInterface $con
     */
    public function postSave(ConnectionInterface $con = null)
    {
            }

    /**
     * Code to be run before inserting to database
     * @param  ConnectionInterface $con
     * @return boolean
     */
    public function preInsert(ConnectionInterface $con = null)
    {
                return true;
    }

    /**
     * Code to be run after inserting to database
     * @param ConnectionInterface $con
     */
    public function postInsert(ConnectionInterface $con = null)
    {
            }

    /**
     * Code to be run before updating the object in database
     * @param  ConnectionInterface $con
     * @return boolean
     */
    public function preUpdate(ConnectionInterface $con = null)
    {
                return true;
    }

    /**
     * Code to be run after updating the object in database
     * @param ConnectionInterface $con
     */
    public function postUpdate(ConnectionInterface $con = null)
    {
            }

    /**
     * Code to be run before deleting the object in database
     * @param  ConnectionInterface $con
     * @return boolean
     */
    public function preDelete(ConnectionInterface $con = null)
    {
                return true;
    }

    /**
     * Code to be run after deleting the object in database
     * @param ConnectionInterface $con
     */
    public function postDelete(ConnectionInterface $con = null)
    {
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
