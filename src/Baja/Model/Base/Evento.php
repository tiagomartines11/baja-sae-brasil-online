<?php

namespace Baja\Model\Base;

use \Exception;
use \PDO;
use Baja\Model\Equipe as ChildEquipe;
use Baja\Model\EquipeQuery as ChildEquipeQuery;
use Baja\Model\Evento as ChildEvento;
use Baja\Model\EventoQuery as ChildEventoQuery;
use Baja\Model\Participante as ChildParticipante;
use Baja\Model\ParticipanteQuery as ChildParticipanteQuery;
use Baja\Model\Prova as ChildProva;
use Baja\Model\ProvaQuery as ChildProvaQuery;
use Baja\Model\Resultado as ChildResultado;
use Baja\Model\ResultadoQuery as ChildResultadoQuery;
use Baja\Model\Map\EquipeTableMap;
use Baja\Model\Map\EventoTableMap;
use Baja\Model\Map\ParticipanteTableMap;
use Baja\Model\Map\ProvaTableMap;
use Baja\Model\Map\ResultadoTableMap;
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
 * Base class that represents a row from the 'evento' table.
 *
 *
 *
 * @package    propel.generator.Baja.Model.Base
 */
abstract class Evento implements ActiveRecordInterface
{
    /**
     * TableMap class name
     */
    const TABLE_MAP = '\\Baja\\Model\\Map\\EventoTableMap';


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
     * The value for the titulo field.
     *
     * @var        string
     */
    protected $titulo;

    /**
     * The value for the nome field.
     *
     * @var        string
     */
    protected $nome;

    /**
     * The value for the tipo field.
     *
     * @var        int
     */
    protected $tipo;

    /**
     * The value for the ano field.
     *
     * @var        int
     */
    protected $ano;

    /**
     * The value for the menu field.
     *
     * @var        string
     */
    protected $menu;

    /**
     * The value for the ativo field.
     *
     * Note: this column has a database default value of: true
     * @var        boolean
     */
    protected $ativo;

    /**
     * The value for the principal field.
     *
     * Note: this column has a database default value of: false
     * @var        boolean
     */
    protected $principal;

    /**
     * The value for the finalizado field.
     *
     * Note: this column has a database default value of: false
     * @var        boolean
     */
    protected $finalizado;

    /**
     * The value for the spoilers field.
     *
     * Note: this column has a database default value of: false
     * @var        boolean
     */
    protected $spoilers;

    /**
     * The value for the tem_certificado field.
     *
     * Note: this column has a database default value of: false
     * @var        boolean
     */
    protected $tem_certificado;

    /**
     * The value for the presidente field.
     *
     * @var        string
     */
    protected $presidente;

    /**
     * The value for the data field.
     *
     * @var        string
     */
    protected $data;

    /**
     * The value for the mandato_presidente field.
     *
     * @var        string
     */
    protected $mandato_presidente;

    /**
     * The value for the local field.
     *
     * @var        string
     */
    protected $local;

    /**
     * @var        ObjectCollection|ChildEquipe[] Collection to store aggregation of ChildEquipe objects.
     */
    protected $collEquipes;
    protected $collEquipesPartial;

    /**
     * @var        ObjectCollection|ChildParticipante[] Collection to store aggregation of ChildParticipante objects.
     */
    protected $collParticipantes;
    protected $collParticipantesPartial;

    /**
     * @var        ObjectCollection|ChildProva[] Collection to store aggregation of ChildProva objects.
     */
    protected $collProvas;
    protected $collProvasPartial;

    /**
     * @var        ObjectCollection|ChildResultado[] Collection to store aggregation of ChildResultado objects.
     */
    protected $collResultados;
    protected $collResultadosPartial;

    /**
     * Flag to prevent endless save loop, if this object is referenced
     * by another object which falls in this transaction.
     *
     * @var boolean
     */
    protected $alreadyInSave = false;

    /**
     * An array of objects scheduled for deletion.
     * @var ObjectCollection|ChildEquipe[]
     */
    protected $equipesScheduledForDeletion = null;

    /**
     * An array of objects scheduled for deletion.
     * @var ObjectCollection|ChildParticipante[]
     */
    protected $participantesScheduledForDeletion = null;

    /**
     * An array of objects scheduled for deletion.
     * @var ObjectCollection|ChildProva[]
     */
    protected $provasScheduledForDeletion = null;

    /**
     * An array of objects scheduled for deletion.
     * @var ObjectCollection|ChildResultado[]
     */
    protected $resultadosScheduledForDeletion = null;

    /**
     * Applies default values to this object.
     * This method should be called from the object's constructor (or
     * equivalent initialization method).
     * @see __construct()
     */
    public function applyDefaultValues()
    {
        $this->ativo = true;
        $this->principal = false;
        $this->finalizado = false;
        $this->spoilers = false;
        $this->tem_certificado = false;
    }

    /**
     * Initializes internal state of Baja\Model\Base\Evento object.
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
     * Compares this with another <code>Evento</code> instance.  If
     * <code>obj</code> is an instance of <code>Evento</code>, delegates to
     * <code>equals(Evento)</code>.  Otherwise, returns <code>false</code>.
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
     * Get the [titulo] column value.
     *
     * @return string
     */
    public function getTitulo()
    {
        return $this->titulo;
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
     * Get the [tipo] column value.
     *
     * @return string
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function getTipo()
    {
        if (null === $this->tipo) {
            return null;
        }
        $valueSet = EventoTableMap::getValueSet(EventoTableMap::COL_TIPO);
        if (!isset($valueSet[$this->tipo])) {
            throw new PropelException('Unknown stored enum key: ' . $this->tipo);
        }

        return $valueSet[$this->tipo];
    }

    /**
     * Get the [ano] column value.
     *
     * @return int
     */
    public function getAno()
    {
        return $this->ano;
    }

    /**
     * Get the [menu] column value.
     *
     * @return string
     */
    public function getMenu()
    {
        return $this->menu;
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
     * Get the [principal] column value.
     *
     * @return boolean
     */
    public function getPrincipal()
    {
        return $this->principal;
    }

    /**
     * Get the [principal] column value.
     *
     * @return boolean
     */
    public function isPrincipal()
    {
        return $this->getPrincipal();
    }

    /**
     * Get the [finalizado] column value.
     *
     * @return boolean
     */
    public function getFinalizado()
    {
        return $this->finalizado;
    }

    /**
     * Get the [finalizado] column value.
     *
     * @return boolean
     */
    public function isFinalizado()
    {
        return $this->getFinalizado();
    }

    /**
     * Get the [spoilers] column value.
     *
     * @return boolean
     */
    public function getSpoilers()
    {
        return $this->spoilers;
    }

    /**
     * Get the [spoilers] column value.
     *
     * @return boolean
     */
    public function isSpoilers()
    {
        return $this->getSpoilers();
    }

    /**
     * Get the [tem_certificado] column value.
     *
     * @return boolean
     */
    public function getTemCertificado()
    {
        return $this->tem_certificado;
    }

    /**
     * Get the [tem_certificado] column value.
     *
     * @return boolean
     */
    public function isTemCertificado()
    {
        return $this->getTemCertificado();
    }

    /**
     * Get the [presidente] column value.
     *
     * @return string
     */
    public function getPresidente()
    {
        return $this->presidente;
    }

    /**
     * Get the [data] column value.
     *
     * @return string
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * Get the [mandato_presidente] column value.
     *
     * @return string
     */
    public function getMandatoPresidente()
    {
        return $this->mandato_presidente;
    }

    /**
     * Get the [local] column value.
     *
     * @return string
     */
    public function getLocal()
    {
        return $this->local;
    }

    /**
     * Set the value of [evento_id] column.
     *
     * @param string $v New value
     * @return $this|\Baja\Model\Evento The current object (for fluent API support)
     */
    public function setEventoId($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->evento_id !== $v) {
            $this->evento_id = $v;
            $this->modifiedColumns[EventoTableMap::COL_EVENTO_ID] = true;
        }

        return $this;
    } // setEventoId()

    /**
     * Set the value of [titulo] column.
     *
     * @param string|null $v New value
     * @return $this|\Baja\Model\Evento The current object (for fluent API support)
     */
    public function setTitulo($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->titulo !== $v) {
            $this->titulo = $v;
            $this->modifiedColumns[EventoTableMap::COL_TITULO] = true;
        }

        return $this;
    } // setTitulo()

    /**
     * Set the value of [nome] column.
     *
     * @param string|null $v New value
     * @return $this|\Baja\Model\Evento The current object (for fluent API support)
     */
    public function setNome($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->nome !== $v) {
            $this->nome = $v;
            $this->modifiedColumns[EventoTableMap::COL_NOME] = true;
        }

        return $this;
    } // setNome()

    /**
     * Set the value of [tipo] column.
     *
     * @param  string $v new value
     * @return $this|\Baja\Model\Evento The current object (for fluent API support)
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function setTipo($v)
    {
        if ($v !== null) {
            $valueSet = EventoTableMap::getValueSet(EventoTableMap::COL_TIPO);
            if (!in_array($v, $valueSet)) {
                throw new PropelException(sprintf('Value "%s" is not accepted in this enumerated column', $v));
            }
            $v = array_search($v, $valueSet);
        }

        if ($this->tipo !== $v) {
            $this->tipo = $v;
            $this->modifiedColumns[EventoTableMap::COL_TIPO] = true;
        }

        return $this;
    } // setTipo()

    /**
     * Set the value of [ano] column.
     *
     * @param int|null $v New value
     * @return $this|\Baja\Model\Evento The current object (for fluent API support)
     */
    public function setAno($v)
    {
        if ($v !== null) {
            $v = (int) $v;
        }

        if ($this->ano !== $v) {
            $this->ano = $v;
            $this->modifiedColumns[EventoTableMap::COL_ANO] = true;
        }

        return $this;
    } // setAno()

    /**
     * Set the value of [menu] column.
     *
     * @param string|null $v New value
     * @return $this|\Baja\Model\Evento The current object (for fluent API support)
     */
    public function setMenu($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->menu !== $v) {
            $this->menu = $v;
            $this->modifiedColumns[EventoTableMap::COL_MENU] = true;
        }

        return $this;
    } // setMenu()

    /**
     * Sets the value of the [ativo] column.
     * Non-boolean arguments are converted using the following rules:
     *   * 1, '1', 'true',  'on',  and 'yes' are converted to boolean true
     *   * 0, '0', 'false', 'off', and 'no'  are converted to boolean false
     * Check on string values is case insensitive (so 'FaLsE' is seen as 'false').
     *
     * @param  boolean|integer|string $v The new value
     * @return $this|\Baja\Model\Evento The current object (for fluent API support)
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
            $this->modifiedColumns[EventoTableMap::COL_ATIVO] = true;
        }

        return $this;
    } // setAtivo()

    /**
     * Sets the value of the [principal] column.
     * Non-boolean arguments are converted using the following rules:
     *   * 1, '1', 'true',  'on',  and 'yes' are converted to boolean true
     *   * 0, '0', 'false', 'off', and 'no'  are converted to boolean false
     * Check on string values is case insensitive (so 'FaLsE' is seen as 'false').
     *
     * @param  boolean|integer|string $v The new value
     * @return $this|\Baja\Model\Evento The current object (for fluent API support)
     */
    public function setPrincipal($v)
    {
        if ($v !== null) {
            if (is_string($v)) {
                $v = in_array(strtolower($v), array('false', 'off', '-', 'no', 'n', '0', '')) ? false : true;
            } else {
                $v = (boolean) $v;
            }
        }

        if ($this->principal !== $v) {
            $this->principal = $v;
            $this->modifiedColumns[EventoTableMap::COL_PRINCIPAL] = true;
        }

        return $this;
    } // setPrincipal()

    /**
     * Sets the value of the [finalizado] column.
     * Non-boolean arguments are converted using the following rules:
     *   * 1, '1', 'true',  'on',  and 'yes' are converted to boolean true
     *   * 0, '0', 'false', 'off', and 'no'  are converted to boolean false
     * Check on string values is case insensitive (so 'FaLsE' is seen as 'false').
     *
     * @param  boolean|integer|string $v The new value
     * @return $this|\Baja\Model\Evento The current object (for fluent API support)
     */
    public function setFinalizado($v)
    {
        if ($v !== null) {
            if (is_string($v)) {
                $v = in_array(strtolower($v), array('false', 'off', '-', 'no', 'n', '0', '')) ? false : true;
            } else {
                $v = (boolean) $v;
            }
        }

        if ($this->finalizado !== $v) {
            $this->finalizado = $v;
            $this->modifiedColumns[EventoTableMap::COL_FINALIZADO] = true;
        }

        return $this;
    } // setFinalizado()

    /**
     * Sets the value of the [spoilers] column.
     * Non-boolean arguments are converted using the following rules:
     *   * 1, '1', 'true',  'on',  and 'yes' are converted to boolean true
     *   * 0, '0', 'false', 'off', and 'no'  are converted to boolean false
     * Check on string values is case insensitive (so 'FaLsE' is seen as 'false').
     *
     * @param  boolean|integer|string $v The new value
     * @return $this|\Baja\Model\Evento The current object (for fluent API support)
     */
    public function setSpoilers($v)
    {
        if ($v !== null) {
            if (is_string($v)) {
                $v = in_array(strtolower($v), array('false', 'off', '-', 'no', 'n', '0', '')) ? false : true;
            } else {
                $v = (boolean) $v;
            }
        }

        if ($this->spoilers !== $v) {
            $this->spoilers = $v;
            $this->modifiedColumns[EventoTableMap::COL_SPOILERS] = true;
        }

        return $this;
    } // setSpoilers()

    /**
     * Sets the value of the [tem_certificado] column.
     * Non-boolean arguments are converted using the following rules:
     *   * 1, '1', 'true',  'on',  and 'yes' are converted to boolean true
     *   * 0, '0', 'false', 'off', and 'no'  are converted to boolean false
     * Check on string values is case insensitive (so 'FaLsE' is seen as 'false').
     *
     * @param  boolean|integer|string $v The new value
     * @return $this|\Baja\Model\Evento The current object (for fluent API support)
     */
    public function setTemCertificado($v)
    {
        if ($v !== null) {
            if (is_string($v)) {
                $v = in_array(strtolower($v), array('false', 'off', '-', 'no', 'n', '0', '')) ? false : true;
            } else {
                $v = (boolean) $v;
            }
        }

        if ($this->tem_certificado !== $v) {
            $this->tem_certificado = $v;
            $this->modifiedColumns[EventoTableMap::COL_TEM_CERTIFICADO] = true;
        }

        return $this;
    } // setTemCertificado()

    /**
     * Set the value of [presidente] column.
     *
     * @param string|null $v New value
     * @return $this|\Baja\Model\Evento The current object (for fluent API support)
     */
    public function setPresidente($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->presidente !== $v) {
            $this->presidente = $v;
            $this->modifiedColumns[EventoTableMap::COL_PRESIDENTE] = true;
        }

        return $this;
    } // setPresidente()

    /**
     * Set the value of [data] column.
     *
     * @param string|null $v New value
     * @return $this|\Baja\Model\Evento The current object (for fluent API support)
     */
    public function setData($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->data !== $v) {
            $this->data = $v;
            $this->modifiedColumns[EventoTableMap::COL_DATA] = true;
        }

        return $this;
    } // setData()

    /**
     * Set the value of [mandato_presidente] column.
     *
     * @param string|null $v New value
     * @return $this|\Baja\Model\Evento The current object (for fluent API support)
     */
    public function setMandatoPresidente($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->mandato_presidente !== $v) {
            $this->mandato_presidente = $v;
            $this->modifiedColumns[EventoTableMap::COL_MANDATO_PRESIDENTE] = true;
        }

        return $this;
    } // setMandatoPresidente()

    /**
     * Set the value of [local] column.
     *
     * @param string|null $v New value
     * @return $this|\Baja\Model\Evento The current object (for fluent API support)
     */
    public function setLocal($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->local !== $v) {
            $this->local = $v;
            $this->modifiedColumns[EventoTableMap::COL_LOCAL] = true;
        }

        return $this;
    } // setLocal()

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
            if ($this->ativo !== true) {
                return false;
            }

            if ($this->principal !== false) {
                return false;
            }

            if ($this->finalizado !== false) {
                return false;
            }

            if ($this->spoilers !== false) {
                return false;
            }

            if ($this->tem_certificado !== false) {
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

            $col = $row[TableMap::TYPE_NUM == $indexType ? 0 + $startcol : EventoTableMap::translateFieldName('EventoId', TableMap::TYPE_PHPNAME, $indexType)];
            $this->evento_id = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 1 + $startcol : EventoTableMap::translateFieldName('Titulo', TableMap::TYPE_PHPNAME, $indexType)];
            $this->titulo = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 2 + $startcol : EventoTableMap::translateFieldName('Nome', TableMap::TYPE_PHPNAME, $indexType)];
            $this->nome = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 3 + $startcol : EventoTableMap::translateFieldName('Tipo', TableMap::TYPE_PHPNAME, $indexType)];
            $this->tipo = (null !== $col) ? (int) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 4 + $startcol : EventoTableMap::translateFieldName('Ano', TableMap::TYPE_PHPNAME, $indexType)];
            $this->ano = (null !== $col) ? (int) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 5 + $startcol : EventoTableMap::translateFieldName('Menu', TableMap::TYPE_PHPNAME, $indexType)];
            $this->menu = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 6 + $startcol : EventoTableMap::translateFieldName('Ativo', TableMap::TYPE_PHPNAME, $indexType)];
            $this->ativo = (null !== $col) ? (boolean) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 7 + $startcol : EventoTableMap::translateFieldName('Principal', TableMap::TYPE_PHPNAME, $indexType)];
            $this->principal = (null !== $col) ? (boolean) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 8 + $startcol : EventoTableMap::translateFieldName('Finalizado', TableMap::TYPE_PHPNAME, $indexType)];
            $this->finalizado = (null !== $col) ? (boolean) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 9 + $startcol : EventoTableMap::translateFieldName('Spoilers', TableMap::TYPE_PHPNAME, $indexType)];
            $this->spoilers = (null !== $col) ? (boolean) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 10 + $startcol : EventoTableMap::translateFieldName('TemCertificado', TableMap::TYPE_PHPNAME, $indexType)];
            $this->tem_certificado = (null !== $col) ? (boolean) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 11 + $startcol : EventoTableMap::translateFieldName('Presidente', TableMap::TYPE_PHPNAME, $indexType)];
            $this->presidente = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 12 + $startcol : EventoTableMap::translateFieldName('Data', TableMap::TYPE_PHPNAME, $indexType)];
            $this->data = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 13 + $startcol : EventoTableMap::translateFieldName('MandatoPresidente', TableMap::TYPE_PHPNAME, $indexType)];
            $this->mandato_presidente = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 14 + $startcol : EventoTableMap::translateFieldName('Local', TableMap::TYPE_PHPNAME, $indexType)];
            $this->local = (null !== $col) ? (string) $col : null;
            $this->resetModified();

            $this->setNew(false);

            if ($rehydrate) {
                $this->ensureConsistency();
            }

            return $startcol + 15; // 15 = EventoTableMap::NUM_HYDRATE_COLUMNS.

        } catch (Exception $e) {
            throw new PropelException(sprintf('Error populating %s object', '\\Baja\\Model\\Evento'), 0, $e);
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
            $con = Propel::getServiceContainer()->getReadConnection(EventoTableMap::DATABASE_NAME);
        }

        // We don't need to alter the object instance pool; we're just modifying this instance
        // already in the pool.

        $dataFetcher = ChildEventoQuery::create(null, $this->buildPkeyCriteria())->setFormatter(ModelCriteria::FORMAT_STATEMENT)->find($con);
        $row = $dataFetcher->fetch();
        $dataFetcher->close();
        if (!$row) {
            throw new PropelException('Cannot find matching row in the database to reload object values.');
        }
        $this->hydrate($row, 0, true, $dataFetcher->getIndexType()); // rehydrate

        if ($deep) {  // also de-associate any related objects?

            $this->collEquipes = null;

            $this->collParticipantes = null;

            $this->collProvas = null;

            $this->collResultados = null;

        } // if (deep)
    }

    /**
     * Removes this object from datastore and sets delete attribute.
     *
     * @param      ConnectionInterface $con
     * @return void
     * @throws PropelException
     * @see Evento::setDeleted()
     * @see Evento::isDeleted()
     */
    public function delete(ConnectionInterface $con = null)
    {
        if ($this->isDeleted()) {
            throw new PropelException("This object has already been deleted.");
        }

        if ($con === null) {
            $con = Propel::getServiceContainer()->getWriteConnection(EventoTableMap::DATABASE_NAME);
        }

        $con->transaction(function () use ($con) {
            $deleteQuery = ChildEventoQuery::create()
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
            $con = Propel::getServiceContainer()->getWriteConnection(EventoTableMap::DATABASE_NAME);
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
                EventoTableMap::addInstanceToPool($this);
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

            if ($this->equipesScheduledForDeletion !== null) {
                if (!$this->equipesScheduledForDeletion->isEmpty()) {
                    \Baja\Model\EquipeQuery::create()
                        ->filterByPrimaryKeys($this->equipesScheduledForDeletion->getPrimaryKeys(false))
                        ->delete($con);
                    $this->equipesScheduledForDeletion = null;
                }
            }

            if ($this->collEquipes !== null) {
                foreach ($this->collEquipes as $referrerFK) {
                    if (!$referrerFK->isDeleted() && ($referrerFK->isNew() || $referrerFK->isModified())) {
                        $affectedRows += $referrerFK->save($con);
                    }
                }
            }

            if ($this->participantesScheduledForDeletion !== null) {
                if (!$this->participantesScheduledForDeletion->isEmpty()) {
                    \Baja\Model\ParticipanteQuery::create()
                        ->filterByPrimaryKeys($this->participantesScheduledForDeletion->getPrimaryKeys(false))
                        ->delete($con);
                    $this->participantesScheduledForDeletion = null;
                }
            }

            if ($this->collParticipantes !== null) {
                foreach ($this->collParticipantes as $referrerFK) {
                    if (!$referrerFK->isDeleted() && ($referrerFK->isNew() || $referrerFK->isModified())) {
                        $affectedRows += $referrerFK->save($con);
                    }
                }
            }

            if ($this->provasScheduledForDeletion !== null) {
                if (!$this->provasScheduledForDeletion->isEmpty()) {
                    \Baja\Model\ProvaQuery::create()
                        ->filterByPrimaryKeys($this->provasScheduledForDeletion->getPrimaryKeys(false))
                        ->delete($con);
                    $this->provasScheduledForDeletion = null;
                }
            }

            if ($this->collProvas !== null) {
                foreach ($this->collProvas as $referrerFK) {
                    if (!$referrerFK->isDeleted() && ($referrerFK->isNew() || $referrerFK->isModified())) {
                        $affectedRows += $referrerFK->save($con);
                    }
                }
            }

            if ($this->resultadosScheduledForDeletion !== null) {
                if (!$this->resultadosScheduledForDeletion->isEmpty()) {
                    \Baja\Model\ResultadoQuery::create()
                        ->filterByPrimaryKeys($this->resultadosScheduledForDeletion->getPrimaryKeys(false))
                        ->delete($con);
                    $this->resultadosScheduledForDeletion = null;
                }
            }

            if ($this->collResultados !== null) {
                foreach ($this->collResultados as $referrerFK) {
                    if (!$referrerFK->isDeleted() && ($referrerFK->isNew() || $referrerFK->isModified())) {
                        $affectedRows += $referrerFK->save($con);
                    }
                }
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
        if ($this->isColumnModified(EventoTableMap::COL_EVENTO_ID)) {
            $modifiedColumns[':p' . $index++]  = 'evento_id';
        }
        if ($this->isColumnModified(EventoTableMap::COL_TITULO)) {
            $modifiedColumns[':p' . $index++]  = 'titulo';
        }
        if ($this->isColumnModified(EventoTableMap::COL_NOME)) {
            $modifiedColumns[':p' . $index++]  = 'nome';
        }
        if ($this->isColumnModified(EventoTableMap::COL_TIPO)) {
            $modifiedColumns[':p' . $index++]  = 'tipo';
        }
        if ($this->isColumnModified(EventoTableMap::COL_ANO)) {
            $modifiedColumns[':p' . $index++]  = 'ano';
        }
        if ($this->isColumnModified(EventoTableMap::COL_MENU)) {
            $modifiedColumns[':p' . $index++]  = 'menu';
        }
        if ($this->isColumnModified(EventoTableMap::COL_ATIVO)) {
            $modifiedColumns[':p' . $index++]  = 'ativo';
        }
        if ($this->isColumnModified(EventoTableMap::COL_PRINCIPAL)) {
            $modifiedColumns[':p' . $index++]  = 'principal';
        }
        if ($this->isColumnModified(EventoTableMap::COL_FINALIZADO)) {
            $modifiedColumns[':p' . $index++]  = 'finalizado';
        }
        if ($this->isColumnModified(EventoTableMap::COL_SPOILERS)) {
            $modifiedColumns[':p' . $index++]  = 'spoilers';
        }
        if ($this->isColumnModified(EventoTableMap::COL_TEM_CERTIFICADO)) {
            $modifiedColumns[':p' . $index++]  = 'tem_certificado';
        }
        if ($this->isColumnModified(EventoTableMap::COL_PRESIDENTE)) {
            $modifiedColumns[':p' . $index++]  = 'presidente';
        }
        if ($this->isColumnModified(EventoTableMap::COL_DATA)) {
            $modifiedColumns[':p' . $index++]  = 'data';
        }
        if ($this->isColumnModified(EventoTableMap::COL_MANDATO_PRESIDENTE)) {
            $modifiedColumns[':p' . $index++]  = 'mandato_presidente';
        }
        if ($this->isColumnModified(EventoTableMap::COL_LOCAL)) {
            $modifiedColumns[':p' . $index++]  = 'local';
        }

        $sql = sprintf(
            'INSERT INTO evento (%s) VALUES (%s)',
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
                    case 'titulo':
                        $stmt->bindValue($identifier, $this->titulo, PDO::PARAM_STR);
                        break;
                    case 'nome':
                        $stmt->bindValue($identifier, $this->nome, PDO::PARAM_STR);
                        break;
                    case 'tipo':
                        $stmt->bindValue($identifier, $this->tipo, PDO::PARAM_INT);
                        break;
                    case 'ano':
                        $stmt->bindValue($identifier, $this->ano, PDO::PARAM_INT);
                        break;
                    case 'menu':
                        $stmt->bindValue($identifier, $this->menu, PDO::PARAM_STR);
                        break;
                    case 'ativo':
                        $stmt->bindValue($identifier, (int) $this->ativo, PDO::PARAM_INT);
                        break;
                    case 'principal':
                        $stmt->bindValue($identifier, (int) $this->principal, PDO::PARAM_INT);
                        break;
                    case 'finalizado':
                        $stmt->bindValue($identifier, (int) $this->finalizado, PDO::PARAM_INT);
                        break;
                    case 'spoilers':
                        $stmt->bindValue($identifier, (int) $this->spoilers, PDO::PARAM_INT);
                        break;
                    case 'tem_certificado':
                        $stmt->bindValue($identifier, (int) $this->tem_certificado, PDO::PARAM_INT);
                        break;
                    case 'presidente':
                        $stmt->bindValue($identifier, $this->presidente, PDO::PARAM_STR);
                        break;
                    case 'data':
                        $stmt->bindValue($identifier, $this->data, PDO::PARAM_STR);
                        break;
                    case 'mandato_presidente':
                        $stmt->bindValue($identifier, $this->mandato_presidente, PDO::PARAM_STR);
                        break;
                    case 'local':
                        $stmt->bindValue($identifier, $this->local, PDO::PARAM_STR);
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
        $pos = EventoTableMap::translateFieldName($name, $type, TableMap::TYPE_NUM);
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
                return $this->getTitulo();
                break;
            case 2:
                return $this->getNome();
                break;
            case 3:
                return $this->getTipo();
                break;
            case 4:
                return $this->getAno();
                break;
            case 5:
                return $this->getMenu();
                break;
            case 6:
                return $this->getAtivo();
                break;
            case 7:
                return $this->getPrincipal();
                break;
            case 8:
                return $this->getFinalizado();
                break;
            case 9:
                return $this->getSpoilers();
                break;
            case 10:
                return $this->getTemCertificado();
                break;
            case 11:
                return $this->getPresidente();
                break;
            case 12:
                return $this->getData();
                break;
            case 13:
                return $this->getMandatoPresidente();
                break;
            case 14:
                return $this->getLocal();
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

        if (isset($alreadyDumpedObjects['Evento'][$this->hashCode()])) {
            return '*RECURSION*';
        }
        $alreadyDumpedObjects['Evento'][$this->hashCode()] = true;
        $keys = EventoTableMap::getFieldNames($keyType);
        $result = array(
            $keys[0] => $this->getEventoId(),
            $keys[1] => $this->getTitulo(),
            $keys[2] => $this->getNome(),
            $keys[3] => $this->getTipo(),
            $keys[4] => $this->getAno(),
            $keys[5] => $this->getMenu(),
            $keys[6] => $this->getAtivo(),
            $keys[7] => $this->getPrincipal(),
            $keys[8] => $this->getFinalizado(),
            $keys[9] => $this->getSpoilers(),
            $keys[10] => $this->getTemCertificado(),
            $keys[11] => $this->getPresidente(),
            $keys[12] => $this->getData(),
            $keys[13] => $this->getMandatoPresidente(),
            $keys[14] => $this->getLocal(),
        );
        $virtualColumns = $this->virtualColumns;
        foreach ($virtualColumns as $key => $virtualColumn) {
            $result[$key] = $virtualColumn;
        }

        if ($includeForeignObjects) {
            if (null !== $this->collEquipes) {

                switch ($keyType) {
                    case TableMap::TYPE_CAMELNAME:
                        $key = 'equipes';
                        break;
                    case TableMap::TYPE_FIELDNAME:
                        $key = 'equipes';
                        break;
                    default:
                        $key = 'Equipes';
                }

                $result[$key] = $this->collEquipes->toArray(null, false, $keyType, $includeLazyLoadColumns, $alreadyDumpedObjects);
            }
            if (null !== $this->collParticipantes) {

                switch ($keyType) {
                    case TableMap::TYPE_CAMELNAME:
                        $key = 'participantes';
                        break;
                    case TableMap::TYPE_FIELDNAME:
                        $key = 'participantess';
                        break;
                    default:
                        $key = 'Participantes';
                }

                $result[$key] = $this->collParticipantes->toArray(null, false, $keyType, $includeLazyLoadColumns, $alreadyDumpedObjects);
            }
            if (null !== $this->collProvas) {

                switch ($keyType) {
                    case TableMap::TYPE_CAMELNAME:
                        $key = 'provas';
                        break;
                    case TableMap::TYPE_FIELDNAME:
                        $key = 'provas';
                        break;
                    default:
                        $key = 'Provas';
                }

                $result[$key] = $this->collProvas->toArray(null, false, $keyType, $includeLazyLoadColumns, $alreadyDumpedObjects);
            }
            if (null !== $this->collResultados) {

                switch ($keyType) {
                    case TableMap::TYPE_CAMELNAME:
                        $key = 'resultados';
                        break;
                    case TableMap::TYPE_FIELDNAME:
                        $key = 'resultados';
                        break;
                    default:
                        $key = 'Resultados';
                }

                $result[$key] = $this->collResultados->toArray(null, false, $keyType, $includeLazyLoadColumns, $alreadyDumpedObjects);
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
     * @return $this|\Baja\Model\Evento
     */
    public function setByName($name, $value, $type = TableMap::TYPE_PHPNAME)
    {
        $pos = EventoTableMap::translateFieldName($name, $type, TableMap::TYPE_NUM);

        return $this->setByPosition($pos, $value);
    }

    /**
     * Sets a field from the object by Position as specified in the xml schema.
     * Zero-based.
     *
     * @param  int $pos position in xml schema
     * @param  mixed $value field value
     * @return $this|\Baja\Model\Evento
     */
    public function setByPosition($pos, $value)
    {
        switch ($pos) {
            case 0:
                $this->setEventoId($value);
                break;
            case 1:
                $this->setTitulo($value);
                break;
            case 2:
                $this->setNome($value);
                break;
            case 3:
                $valueSet = EventoTableMap::getValueSet(EventoTableMap::COL_TIPO);
                if (isset($valueSet[$value])) {
                    $value = $valueSet[$value];
                }
                $this->setTipo($value);
                break;
            case 4:
                $this->setAno($value);
                break;
            case 5:
                $this->setMenu($value);
                break;
            case 6:
                $this->setAtivo($value);
                break;
            case 7:
                $this->setPrincipal($value);
                break;
            case 8:
                $this->setFinalizado($value);
                break;
            case 9:
                $this->setSpoilers($value);
                break;
            case 10:
                $this->setTemCertificado($value);
                break;
            case 11:
                $this->setPresidente($value);
                break;
            case 12:
                $this->setData($value);
                break;
            case 13:
                $this->setMandatoPresidente($value);
                break;
            case 14:
                $this->setLocal($value);
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
        $keys = EventoTableMap::getFieldNames($keyType);

        if (array_key_exists($keys[0], $arr)) {
            $this->setEventoId($arr[$keys[0]]);
        }
        if (array_key_exists($keys[1], $arr)) {
            $this->setTitulo($arr[$keys[1]]);
        }
        if (array_key_exists($keys[2], $arr)) {
            $this->setNome($arr[$keys[2]]);
        }
        if (array_key_exists($keys[3], $arr)) {
            $this->setTipo($arr[$keys[3]]);
        }
        if (array_key_exists($keys[4], $arr)) {
            $this->setAno($arr[$keys[4]]);
        }
        if (array_key_exists($keys[5], $arr)) {
            $this->setMenu($arr[$keys[5]]);
        }
        if (array_key_exists($keys[6], $arr)) {
            $this->setAtivo($arr[$keys[6]]);
        }
        if (array_key_exists($keys[7], $arr)) {
            $this->setPrincipal($arr[$keys[7]]);
        }
        if (array_key_exists($keys[8], $arr)) {
            $this->setFinalizado($arr[$keys[8]]);
        }
        if (array_key_exists($keys[9], $arr)) {
            $this->setSpoilers($arr[$keys[9]]);
        }
        if (array_key_exists($keys[10], $arr)) {
            $this->setTemCertificado($arr[$keys[10]]);
        }
        if (array_key_exists($keys[11], $arr)) {
            $this->setPresidente($arr[$keys[11]]);
        }
        if (array_key_exists($keys[12], $arr)) {
            $this->setData($arr[$keys[12]]);
        }
        if (array_key_exists($keys[13], $arr)) {
            $this->setMandatoPresidente($arr[$keys[13]]);
        }
        if (array_key_exists($keys[14], $arr)) {
            $this->setLocal($arr[$keys[14]]);
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
     * @return $this|\Baja\Model\Evento The current object, for fluid interface
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
        $criteria = new Criteria(EventoTableMap::DATABASE_NAME);

        if ($this->isColumnModified(EventoTableMap::COL_EVENTO_ID)) {
            $criteria->add(EventoTableMap::COL_EVENTO_ID, $this->evento_id);
        }
        if ($this->isColumnModified(EventoTableMap::COL_TITULO)) {
            $criteria->add(EventoTableMap::COL_TITULO, $this->titulo);
        }
        if ($this->isColumnModified(EventoTableMap::COL_NOME)) {
            $criteria->add(EventoTableMap::COL_NOME, $this->nome);
        }
        if ($this->isColumnModified(EventoTableMap::COL_TIPO)) {
            $criteria->add(EventoTableMap::COL_TIPO, $this->tipo);
        }
        if ($this->isColumnModified(EventoTableMap::COL_ANO)) {
            $criteria->add(EventoTableMap::COL_ANO, $this->ano);
        }
        if ($this->isColumnModified(EventoTableMap::COL_MENU)) {
            $criteria->add(EventoTableMap::COL_MENU, $this->menu);
        }
        if ($this->isColumnModified(EventoTableMap::COL_ATIVO)) {
            $criteria->add(EventoTableMap::COL_ATIVO, $this->ativo);
        }
        if ($this->isColumnModified(EventoTableMap::COL_PRINCIPAL)) {
            $criteria->add(EventoTableMap::COL_PRINCIPAL, $this->principal);
        }
        if ($this->isColumnModified(EventoTableMap::COL_FINALIZADO)) {
            $criteria->add(EventoTableMap::COL_FINALIZADO, $this->finalizado);
        }
        if ($this->isColumnModified(EventoTableMap::COL_SPOILERS)) {
            $criteria->add(EventoTableMap::COL_SPOILERS, $this->spoilers);
        }
        if ($this->isColumnModified(EventoTableMap::COL_TEM_CERTIFICADO)) {
            $criteria->add(EventoTableMap::COL_TEM_CERTIFICADO, $this->tem_certificado);
        }
        if ($this->isColumnModified(EventoTableMap::COL_PRESIDENTE)) {
            $criteria->add(EventoTableMap::COL_PRESIDENTE, $this->presidente);
        }
        if ($this->isColumnModified(EventoTableMap::COL_DATA)) {
            $criteria->add(EventoTableMap::COL_DATA, $this->data);
        }
        if ($this->isColumnModified(EventoTableMap::COL_MANDATO_PRESIDENTE)) {
            $criteria->add(EventoTableMap::COL_MANDATO_PRESIDENTE, $this->mandato_presidente);
        }
        if ($this->isColumnModified(EventoTableMap::COL_LOCAL)) {
            $criteria->add(EventoTableMap::COL_LOCAL, $this->local);
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
        $criteria = ChildEventoQuery::create();
        $criteria->add(EventoTableMap::COL_EVENTO_ID, $this->evento_id);

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
        $validPk = null !== $this->getEventoId();

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
        return $this->getEventoId();
    }

    /**
     * Generic method to set the primary key (evento_id column).
     *
     * @param       string $key Primary key.
     * @return void
     */
    public function setPrimaryKey($key)
    {
        $this->setEventoId($key);
    }

    /**
     * Returns true if the primary key for this object is null.
     * @return boolean
     */
    public function isPrimaryKeyNull()
    {
        return null === $this->getEventoId();
    }

    /**
     * Sets contents of passed object to values from current object.
     *
     * If desired, this method can also make copies of all associated (fkey referrers)
     * objects.
     *
     * @param      object $copyObj An object of \Baja\Model\Evento (or compatible) type.
     * @param      boolean $deepCopy Whether to also copy all rows that refer (by fkey) to the current row.
     * @param      boolean $makeNew Whether to reset autoincrement PKs and make the object new.
     * @throws PropelException
     */
    public function copyInto($copyObj, $deepCopy = false, $makeNew = true)
    {
        $copyObj->setEventoId($this->getEventoId());
        $copyObj->setTitulo($this->getTitulo());
        $copyObj->setNome($this->getNome());
        $copyObj->setTipo($this->getTipo());
        $copyObj->setAno($this->getAno());
        $copyObj->setMenu($this->getMenu());
        $copyObj->setAtivo($this->getAtivo());
        $copyObj->setPrincipal($this->getPrincipal());
        $copyObj->setFinalizado($this->getFinalizado());
        $copyObj->setSpoilers($this->getSpoilers());
        $copyObj->setTemCertificado($this->getTemCertificado());
        $copyObj->setPresidente($this->getPresidente());
        $copyObj->setData($this->getData());
        $copyObj->setMandatoPresidente($this->getMandatoPresidente());
        $copyObj->setLocal($this->getLocal());

        if ($deepCopy) {
            // important: temporarily setNew(false) because this affects the behavior of
            // the getter/setter methods for fkey referrer objects.
            $copyObj->setNew(false);

            foreach ($this->getEquipes() as $relObj) {
                if ($relObj !== $this) {  // ensure that we don't try to copy a reference to ourselves
                    $copyObj->addEquipe($relObj->copy($deepCopy));
                }
            }

            foreach ($this->getParticipantes() as $relObj) {
                if ($relObj !== $this) {  // ensure that we don't try to copy a reference to ourselves
                    $copyObj->addParticipante($relObj->copy($deepCopy));
                }
            }

            foreach ($this->getProvas() as $relObj) {
                if ($relObj !== $this) {  // ensure that we don't try to copy a reference to ourselves
                    $copyObj->addProva($relObj->copy($deepCopy));
                }
            }

            foreach ($this->getResultados() as $relObj) {
                if ($relObj !== $this) {  // ensure that we don't try to copy a reference to ourselves
                    $copyObj->addResultado($relObj->copy($deepCopy));
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
     * @param  boolean $deepCopy Whether to also copy all rows that refer (by fkey) to the current row.
     * @return \Baja\Model\Evento Clone of current object.
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
     * Initializes a collection based on the name of a relation.
     * Avoids crafting an 'init[$relationName]s' method name
     * that wouldn't work when StandardEnglishPluralizer is used.
     *
     * @param      string $relationName The name of the relation to initialize
     * @return void
     */
    public function initRelation($relationName)
    {
        if ('Equipe' === $relationName) {
            $this->initEquipes();
            return;
        }
        if ('Participante' === $relationName) {
            $this->initParticipantes();
            return;
        }
        if ('Prova' === $relationName) {
            $this->initProvas();
            return;
        }
        if ('Resultado' === $relationName) {
            $this->initResultados();
            return;
        }
    }

    /**
     * Clears out the collEquipes collection
     *
     * This does not modify the database; however, it will remove any associated objects, causing
     * them to be refetched by subsequent calls to accessor method.
     *
     * @return void
     * @see        addEquipes()
     */
    public function clearEquipes()
    {
        $this->collEquipes = null; // important to set this to NULL since that means it is uninitialized
    }

    /**
     * Reset is the collEquipes collection loaded partially.
     */
    public function resetPartialEquipes($v = true)
    {
        $this->collEquipesPartial = $v;
    }

    /**
     * Initializes the collEquipes collection.
     *
     * By default this just sets the collEquipes collection to an empty array (like clearcollEquipes());
     * however, you may wish to override this method in your stub class to provide setting appropriate
     * to your application -- for example, setting the initial array to the values stored in database.
     *
     * @param      boolean $overrideExisting If set to true, the method call initializes
     *                                        the collection even if it is not empty
     *
     * @return void
     */
    public function initEquipes($overrideExisting = true)
    {
        if (null !== $this->collEquipes && !$overrideExisting) {
            return;
        }

        $collectionClassName = EquipeTableMap::getTableMap()->getCollectionClassName();

        $this->collEquipes = new $collectionClassName;
        $this->collEquipes->setModel('\Baja\Model\Equipe');
    }

    /**
     * Gets an array of ChildEquipe objects which contain a foreign key that references this object.
     *
     * If the $criteria is not null, it is used to always fetch the results from the database.
     * Otherwise the results are fetched from the database the first time, then cached.
     * Next time the same method is called without $criteria, the cached collection is returned.
     * If this ChildEvento is new, it will return
     * an empty collection or the current collection; the criteria is ignored on a new object.
     *
     * @param      Criteria $criteria optional Criteria object to narrow the query
     * @param      ConnectionInterface $con optional connection object
     * @return ObjectCollection|ChildEquipe[] List of ChildEquipe objects
     * @throws PropelException
     */
    public function getEquipes(Criteria $criteria = null, ConnectionInterface $con = null)
    {
        $partial = $this->collEquipesPartial && !$this->isNew();
        if (null === $this->collEquipes || null !== $criteria || $partial) {
            if ($this->isNew()) {
                // return empty collection
                if (null === $this->collEquipes) {
                    $this->initEquipes();
                } else {
                    $collectionClassName = EquipeTableMap::getTableMap()->getCollectionClassName();

                    $collEquipes = new $collectionClassName;
                    $collEquipes->setModel('\Baja\Model\Equipe');

                    return $collEquipes;
                }
            } else {
                $collEquipes = ChildEquipeQuery::create(null, $criteria)
                    ->filterByEvento($this)
                    ->find($con);

                if (null !== $criteria) {
                    if (false !== $this->collEquipesPartial && count($collEquipes)) {
                        $this->initEquipes(false);

                        foreach ($collEquipes as $obj) {
                            if (false == $this->collEquipes->contains($obj)) {
                                $this->collEquipes->append($obj);
                            }
                        }

                        $this->collEquipesPartial = true;
                    }

                    return $collEquipes;
                }

                if ($partial && $this->collEquipes) {
                    foreach ($this->collEquipes as $obj) {
                        if ($obj->isNew()) {
                            $collEquipes[] = $obj;
                        }
                    }
                }

                $this->collEquipes = $collEquipes;
                $this->collEquipesPartial = false;
            }
        }

        return $this->collEquipes;
    }

    /**
     * Sets a collection of ChildEquipe objects related by a one-to-many relationship
     * to the current object.
     * It will also schedule objects for deletion based on a diff between old objects (aka persisted)
     * and new objects from the given Propel collection.
     *
     * @param      Collection $equipes A Propel collection.
     * @param      ConnectionInterface $con Optional connection object
     * @return $this|ChildEvento The current object (for fluent API support)
     */
    public function setEquipes(Collection $equipes, ConnectionInterface $con = null)
    {
        /** @var ChildEquipe[] $equipesToDelete */
        $equipesToDelete = $this->getEquipes(new Criteria(), $con)->diff($equipes);


        //since at least one column in the foreign key is at the same time a PK
        //we can not just set a PK to NULL in the lines below. We have to store
        //a backup of all values, so we are able to manipulate these items based on the onDelete value later.
        $this->equipesScheduledForDeletion = clone $equipesToDelete;

        foreach ($equipesToDelete as $equipeRemoved) {
            $equipeRemoved->setEvento(null);
        }

        $this->collEquipes = null;
        foreach ($equipes as $equipe) {
            $this->addEquipe($equipe);
        }

        $this->collEquipes = $equipes;
        $this->collEquipesPartial = false;

        return $this;
    }

    /**
     * Returns the number of related Equipe objects.
     *
     * @param      Criteria $criteria
     * @param      boolean $distinct
     * @param      ConnectionInterface $con
     * @return int             Count of related Equipe objects.
     * @throws PropelException
     */
    public function countEquipes(Criteria $criteria = null, $distinct = false, ConnectionInterface $con = null)
    {
        $partial = $this->collEquipesPartial && !$this->isNew();
        if (null === $this->collEquipes || null !== $criteria || $partial) {
            if ($this->isNew() && null === $this->collEquipes) {
                return 0;
            }

            if ($partial && !$criteria) {
                return count($this->getEquipes());
            }

            $query = ChildEquipeQuery::create(null, $criteria);
            if ($distinct) {
                $query->distinct();
            }

            return $query
                ->filterByEvento($this)
                ->count($con);
        }

        return count($this->collEquipes);
    }

    /**
     * Method called to associate a ChildEquipe object to this object
     * through the ChildEquipe foreign key attribute.
     *
     * @param  ChildEquipe $l ChildEquipe
     * @return $this|\Baja\Model\Evento The current object (for fluent API support)
     */
    public function addEquipe(ChildEquipe $l)
    {
        if ($this->collEquipes === null) {
            $this->initEquipes();
            $this->collEquipesPartial = true;
        }

        if (!$this->collEquipes->contains($l)) {
            $this->doAddEquipe($l);

            if ($this->equipesScheduledForDeletion and $this->equipesScheduledForDeletion->contains($l)) {
                $this->equipesScheduledForDeletion->remove($this->equipesScheduledForDeletion->search($l));
            }
        }

        return $this;
    }

    /**
     * @param ChildEquipe $equipe The ChildEquipe object to add.
     */
    protected function doAddEquipe(ChildEquipe $equipe)
    {
        $this->collEquipes[]= $equipe;
        $equipe->setEvento($this);
    }

    /**
     * @param  ChildEquipe $equipe The ChildEquipe object to remove.
     * @return $this|ChildEvento The current object (for fluent API support)
     */
    public function removeEquipe(ChildEquipe $equipe)
    {
        if ($this->getEquipes()->contains($equipe)) {
            $pos = $this->collEquipes->search($equipe);
            $this->collEquipes->remove($pos);
            if (null === $this->equipesScheduledForDeletion) {
                $this->equipesScheduledForDeletion = clone $this->collEquipes;
                $this->equipesScheduledForDeletion->clear();
            }
            $this->equipesScheduledForDeletion[]= clone $equipe;
            $equipe->setEvento(null);
        }

        return $this;
    }

    /**
     * Clears out the collParticipantes collection
     *
     * This does not modify the database; however, it will remove any associated objects, causing
     * them to be refetched by subsequent calls to accessor method.
     *
     * @return void
     * @see        addParticipantes()
     */
    public function clearParticipantes()
    {
        $this->collParticipantes = null; // important to set this to NULL since that means it is uninitialized
    }

    /**
     * Reset is the collParticipantes collection loaded partially.
     */
    public function resetPartialParticipantes($v = true)
    {
        $this->collParticipantesPartial = $v;
    }

    /**
     * Initializes the collParticipantes collection.
     *
     * By default this just sets the collParticipantes collection to an empty array (like clearcollParticipantes());
     * however, you may wish to override this method in your stub class to provide setting appropriate
     * to your application -- for example, setting the initial array to the values stored in database.
     *
     * @param      boolean $overrideExisting If set to true, the method call initializes
     *                                        the collection even if it is not empty
     *
     * @return void
     */
    public function initParticipantes($overrideExisting = true)
    {
        if (null !== $this->collParticipantes && !$overrideExisting) {
            return;
        }

        $collectionClassName = ParticipanteTableMap::getTableMap()->getCollectionClassName();

        $this->collParticipantes = new $collectionClassName;
        $this->collParticipantes->setModel('\Baja\Model\Participante');
    }

    /**
     * Gets an array of ChildParticipante objects which contain a foreign key that references this object.
     *
     * If the $criteria is not null, it is used to always fetch the results from the database.
     * Otherwise the results are fetched from the database the first time, then cached.
     * Next time the same method is called without $criteria, the cached collection is returned.
     * If this ChildEvento is new, it will return
     * an empty collection or the current collection; the criteria is ignored on a new object.
     *
     * @param      Criteria $criteria optional Criteria object to narrow the query
     * @param      ConnectionInterface $con optional connection object
     * @return ObjectCollection|ChildParticipante[] List of ChildParticipante objects
     * @throws PropelException
     */
    public function getParticipantes(Criteria $criteria = null, ConnectionInterface $con = null)
    {
        $partial = $this->collParticipantesPartial && !$this->isNew();
        if (null === $this->collParticipantes || null !== $criteria || $partial) {
            if ($this->isNew()) {
                // return empty collection
                if (null === $this->collParticipantes) {
                    $this->initParticipantes();
                } else {
                    $collectionClassName = ParticipanteTableMap::getTableMap()->getCollectionClassName();

                    $collParticipantes = new $collectionClassName;
                    $collParticipantes->setModel('\Baja\Model\Participante');

                    return $collParticipantes;
                }
            } else {
                $collParticipantes = ChildParticipanteQuery::create(null, $criteria)
                    ->filterByEvento($this)
                    ->find($con);

                if (null !== $criteria) {
                    if (false !== $this->collParticipantesPartial && count($collParticipantes)) {
                        $this->initParticipantes(false);

                        foreach ($collParticipantes as $obj) {
                            if (false == $this->collParticipantes->contains($obj)) {
                                $this->collParticipantes->append($obj);
                            }
                        }

                        $this->collParticipantesPartial = true;
                    }

                    return $collParticipantes;
                }

                if ($partial && $this->collParticipantes) {
                    foreach ($this->collParticipantes as $obj) {
                        if ($obj->isNew()) {
                            $collParticipantes[] = $obj;
                        }
                    }
                }

                $this->collParticipantes = $collParticipantes;
                $this->collParticipantesPartial = false;
            }
        }

        return $this->collParticipantes;
    }

    /**
     * Sets a collection of ChildParticipante objects related by a one-to-many relationship
     * to the current object.
     * It will also schedule objects for deletion based on a diff between old objects (aka persisted)
     * and new objects from the given Propel collection.
     *
     * @param      Collection $participantes A Propel collection.
     * @param      ConnectionInterface $con Optional connection object
     * @return $this|ChildEvento The current object (for fluent API support)
     */
    public function setParticipantes(Collection $participantes, ConnectionInterface $con = null)
    {
        /** @var ChildParticipante[] $participantesToDelete */
        $participantesToDelete = $this->getParticipantes(new Criteria(), $con)->diff($participantes);


        //since at least one column in the foreign key is at the same time a PK
        //we can not just set a PK to NULL in the lines below. We have to store
        //a backup of all values, so we are able to manipulate these items based on the onDelete value later.
        $this->participantesScheduledForDeletion = clone $participantesToDelete;

        foreach ($participantesToDelete as $participanteRemoved) {
            $participanteRemoved->setEvento(null);
        }

        $this->collParticipantes = null;
        foreach ($participantes as $participante) {
            $this->addParticipante($participante);
        }

        $this->collParticipantes = $participantes;
        $this->collParticipantesPartial = false;

        return $this;
    }

    /**
     * Returns the number of related Participante objects.
     *
     * @param      Criteria $criteria
     * @param      boolean $distinct
     * @param      ConnectionInterface $con
     * @return int             Count of related Participante objects.
     * @throws PropelException
     */
    public function countParticipantes(Criteria $criteria = null, $distinct = false, ConnectionInterface $con = null)
    {
        $partial = $this->collParticipantesPartial && !$this->isNew();
        if (null === $this->collParticipantes || null !== $criteria || $partial) {
            if ($this->isNew() && null === $this->collParticipantes) {
                return 0;
            }

            if ($partial && !$criteria) {
                return count($this->getParticipantes());
            }

            $query = ChildParticipanteQuery::create(null, $criteria);
            if ($distinct) {
                $query->distinct();
            }

            return $query
                ->filterByEvento($this)
                ->count($con);
        }

        return count($this->collParticipantes);
    }

    /**
     * Method called to associate a ChildParticipante object to this object
     * through the ChildParticipante foreign key attribute.
     *
     * @param  ChildParticipante $l ChildParticipante
     * @return $this|\Baja\Model\Evento The current object (for fluent API support)
     */
    public function addParticipante(ChildParticipante $l)
    {
        if ($this->collParticipantes === null) {
            $this->initParticipantes();
            $this->collParticipantesPartial = true;
        }

        if (!$this->collParticipantes->contains($l)) {
            $this->doAddParticipante($l);

            if ($this->participantesScheduledForDeletion and $this->participantesScheduledForDeletion->contains($l)) {
                $this->participantesScheduledForDeletion->remove($this->participantesScheduledForDeletion->search($l));
            }
        }

        return $this;
    }

    /**
     * @param ChildParticipante $participante The ChildParticipante object to add.
     */
    protected function doAddParticipante(ChildParticipante $participante)
    {
        $this->collParticipantes[]= $participante;
        $participante->setEvento($this);
    }

    /**
     * @param  ChildParticipante $participante The ChildParticipante object to remove.
     * @return $this|ChildEvento The current object (for fluent API support)
     */
    public function removeParticipante(ChildParticipante $participante)
    {
        if ($this->getParticipantes()->contains($participante)) {
            $pos = $this->collParticipantes->search($participante);
            $this->collParticipantes->remove($pos);
            if (null === $this->participantesScheduledForDeletion) {
                $this->participantesScheduledForDeletion = clone $this->collParticipantes;
                $this->participantesScheduledForDeletion->clear();
            }
            $this->participantesScheduledForDeletion[]= clone $participante;
            $participante->setEvento(null);
        }

        return $this;
    }

    /**
     * Clears out the collProvas collection
     *
     * This does not modify the database; however, it will remove any associated objects, causing
     * them to be refetched by subsequent calls to accessor method.
     *
     * @return void
     * @see        addProvas()
     */
    public function clearProvas()
    {
        $this->collProvas = null; // important to set this to NULL since that means it is uninitialized
    }

    /**
     * Reset is the collProvas collection loaded partially.
     */
    public function resetPartialProvas($v = true)
    {
        $this->collProvasPartial = $v;
    }

    /**
     * Initializes the collProvas collection.
     *
     * By default this just sets the collProvas collection to an empty array (like clearcollProvas());
     * however, you may wish to override this method in your stub class to provide setting appropriate
     * to your application -- for example, setting the initial array to the values stored in database.
     *
     * @param      boolean $overrideExisting If set to true, the method call initializes
     *                                        the collection even if it is not empty
     *
     * @return void
     */
    public function initProvas($overrideExisting = true)
    {
        if (null !== $this->collProvas && !$overrideExisting) {
            return;
        }

        $collectionClassName = ProvaTableMap::getTableMap()->getCollectionClassName();

        $this->collProvas = new $collectionClassName;
        $this->collProvas->setModel('\Baja\Model\Prova');
    }

    /**
     * Gets an array of ChildProva objects which contain a foreign key that references this object.
     *
     * If the $criteria is not null, it is used to always fetch the results from the database.
     * Otherwise the results are fetched from the database the first time, then cached.
     * Next time the same method is called without $criteria, the cached collection is returned.
     * If this ChildEvento is new, it will return
     * an empty collection or the current collection; the criteria is ignored on a new object.
     *
     * @param      Criteria $criteria optional Criteria object to narrow the query
     * @param      ConnectionInterface $con optional connection object
     * @return ObjectCollection|ChildProva[] List of ChildProva objects
     * @throws PropelException
     */
    public function getProvas(Criteria $criteria = null, ConnectionInterface $con = null)
    {
        $partial = $this->collProvasPartial && !$this->isNew();
        if (null === $this->collProvas || null !== $criteria || $partial) {
            if ($this->isNew()) {
                // return empty collection
                if (null === $this->collProvas) {
                    $this->initProvas();
                } else {
                    $collectionClassName = ProvaTableMap::getTableMap()->getCollectionClassName();

                    $collProvas = new $collectionClassName;
                    $collProvas->setModel('\Baja\Model\Prova');

                    return $collProvas;
                }
            } else {
                $collProvas = ChildProvaQuery::create(null, $criteria)
                    ->filterByEvento($this)
                    ->find($con);

                if (null !== $criteria) {
                    if (false !== $this->collProvasPartial && count($collProvas)) {
                        $this->initProvas(false);

                        foreach ($collProvas as $obj) {
                            if (false == $this->collProvas->contains($obj)) {
                                $this->collProvas->append($obj);
                            }
                        }

                        $this->collProvasPartial = true;
                    }

                    return $collProvas;
                }

                if ($partial && $this->collProvas) {
                    foreach ($this->collProvas as $obj) {
                        if ($obj->isNew()) {
                            $collProvas[] = $obj;
                        }
                    }
                }

                $this->collProvas = $collProvas;
                $this->collProvasPartial = false;
            }
        }

        return $this->collProvas;
    }

    /**
     * Sets a collection of ChildProva objects related by a one-to-many relationship
     * to the current object.
     * It will also schedule objects for deletion based on a diff between old objects (aka persisted)
     * and new objects from the given Propel collection.
     *
     * @param      Collection $provas A Propel collection.
     * @param      ConnectionInterface $con Optional connection object
     * @return $this|ChildEvento The current object (for fluent API support)
     */
    public function setProvas(Collection $provas, ConnectionInterface $con = null)
    {
        /** @var ChildProva[] $provasToDelete */
        $provasToDelete = $this->getProvas(new Criteria(), $con)->diff($provas);


        //since at least one column in the foreign key is at the same time a PK
        //we can not just set a PK to NULL in the lines below. We have to store
        //a backup of all values, so we are able to manipulate these items based on the onDelete value later.
        $this->provasScheduledForDeletion = clone $provasToDelete;

        foreach ($provasToDelete as $provaRemoved) {
            $provaRemoved->setEvento(null);
        }

        $this->collProvas = null;
        foreach ($provas as $prova) {
            $this->addProva($prova);
        }

        $this->collProvas = $provas;
        $this->collProvasPartial = false;

        return $this;
    }

    /**
     * Returns the number of related Prova objects.
     *
     * @param      Criteria $criteria
     * @param      boolean $distinct
     * @param      ConnectionInterface $con
     * @return int             Count of related Prova objects.
     * @throws PropelException
     */
    public function countProvas(Criteria $criteria = null, $distinct = false, ConnectionInterface $con = null)
    {
        $partial = $this->collProvasPartial && !$this->isNew();
        if (null === $this->collProvas || null !== $criteria || $partial) {
            if ($this->isNew() && null === $this->collProvas) {
                return 0;
            }

            if ($partial && !$criteria) {
                return count($this->getProvas());
            }

            $query = ChildProvaQuery::create(null, $criteria);
            if ($distinct) {
                $query->distinct();
            }

            return $query
                ->filterByEvento($this)
                ->count($con);
        }

        return count($this->collProvas);
    }

    /**
     * Method called to associate a ChildProva object to this object
     * through the ChildProva foreign key attribute.
     *
     * @param  ChildProva $l ChildProva
     * @return $this|\Baja\Model\Evento The current object (for fluent API support)
     */
    public function addProva(ChildProva $l)
    {
        if ($this->collProvas === null) {
            $this->initProvas();
            $this->collProvasPartial = true;
        }

        if (!$this->collProvas->contains($l)) {
            $this->doAddProva($l);

            if ($this->provasScheduledForDeletion and $this->provasScheduledForDeletion->contains($l)) {
                $this->provasScheduledForDeletion->remove($this->provasScheduledForDeletion->search($l));
            }
        }

        return $this;
    }

    /**
     * @param ChildProva $prova The ChildProva object to add.
     */
    protected function doAddProva(ChildProva $prova)
    {
        $this->collProvas[]= $prova;
        $prova->setEvento($this);
    }

    /**
     * @param  ChildProva $prova The ChildProva object to remove.
     * @return $this|ChildEvento The current object (for fluent API support)
     */
    public function removeProva(ChildProva $prova)
    {
        if ($this->getProvas()->contains($prova)) {
            $pos = $this->collProvas->search($prova);
            $this->collProvas->remove($pos);
            if (null === $this->provasScheduledForDeletion) {
                $this->provasScheduledForDeletion = clone $this->collProvas;
                $this->provasScheduledForDeletion->clear();
            }
            $this->provasScheduledForDeletion[]= clone $prova;
            $prova->setEvento(null);
        }

        return $this;
    }

    /**
     * Clears out the collResultados collection
     *
     * This does not modify the database; however, it will remove any associated objects, causing
     * them to be refetched by subsequent calls to accessor method.
     *
     * @return void
     * @see        addResultados()
     */
    public function clearResultados()
    {
        $this->collResultados = null; // important to set this to NULL since that means it is uninitialized
    }

    /**
     * Reset is the collResultados collection loaded partially.
     */
    public function resetPartialResultados($v = true)
    {
        $this->collResultadosPartial = $v;
    }

    /**
     * Initializes the collResultados collection.
     *
     * By default this just sets the collResultados collection to an empty array (like clearcollResultados());
     * however, you may wish to override this method in your stub class to provide setting appropriate
     * to your application -- for example, setting the initial array to the values stored in database.
     *
     * @param      boolean $overrideExisting If set to true, the method call initializes
     *                                        the collection even if it is not empty
     *
     * @return void
     */
    public function initResultados($overrideExisting = true)
    {
        if (null !== $this->collResultados && !$overrideExisting) {
            return;
        }

        $collectionClassName = ResultadoTableMap::getTableMap()->getCollectionClassName();

        $this->collResultados = new $collectionClassName;
        $this->collResultados->setModel('\Baja\Model\Resultado');
    }

    /**
     * Gets an array of ChildResultado objects which contain a foreign key that references this object.
     *
     * If the $criteria is not null, it is used to always fetch the results from the database.
     * Otherwise the results are fetched from the database the first time, then cached.
     * Next time the same method is called without $criteria, the cached collection is returned.
     * If this ChildEvento is new, it will return
     * an empty collection or the current collection; the criteria is ignored on a new object.
     *
     * @param      Criteria $criteria optional Criteria object to narrow the query
     * @param      ConnectionInterface $con optional connection object
     * @return ObjectCollection|ChildResultado[] List of ChildResultado objects
     * @throws PropelException
     */
    public function getResultados(Criteria $criteria = null, ConnectionInterface $con = null)
    {
        $partial = $this->collResultadosPartial && !$this->isNew();
        if (null === $this->collResultados || null !== $criteria || $partial) {
            if ($this->isNew()) {
                // return empty collection
                if (null === $this->collResultados) {
                    $this->initResultados();
                } else {
                    $collectionClassName = ResultadoTableMap::getTableMap()->getCollectionClassName();

                    $collResultados = new $collectionClassName;
                    $collResultados->setModel('\Baja\Model\Resultado');

                    return $collResultados;
                }
            } else {
                $collResultados = ChildResultadoQuery::create(null, $criteria)
                    ->filterByEvento($this)
                    ->find($con);

                if (null !== $criteria) {
                    if (false !== $this->collResultadosPartial && count($collResultados)) {
                        $this->initResultados(false);

                        foreach ($collResultados as $obj) {
                            if (false == $this->collResultados->contains($obj)) {
                                $this->collResultados->append($obj);
                            }
                        }

                        $this->collResultadosPartial = true;
                    }

                    return $collResultados;
                }

                if ($partial && $this->collResultados) {
                    foreach ($this->collResultados as $obj) {
                        if ($obj->isNew()) {
                            $collResultados[] = $obj;
                        }
                    }
                }

                $this->collResultados = $collResultados;
                $this->collResultadosPartial = false;
            }
        }

        return $this->collResultados;
    }

    /**
     * Sets a collection of ChildResultado objects related by a one-to-many relationship
     * to the current object.
     * It will also schedule objects for deletion based on a diff between old objects (aka persisted)
     * and new objects from the given Propel collection.
     *
     * @param      Collection $resultados A Propel collection.
     * @param      ConnectionInterface $con Optional connection object
     * @return $this|ChildEvento The current object (for fluent API support)
     */
    public function setResultados(Collection $resultados, ConnectionInterface $con = null)
    {
        /** @var ChildResultado[] $resultadosToDelete */
        $resultadosToDelete = $this->getResultados(new Criteria(), $con)->diff($resultados);


        $this->resultadosScheduledForDeletion = $resultadosToDelete;

        foreach ($resultadosToDelete as $resultadoRemoved) {
            $resultadoRemoved->setEvento(null);
        }

        $this->collResultados = null;
        foreach ($resultados as $resultado) {
            $this->addResultado($resultado);
        }

        $this->collResultados = $resultados;
        $this->collResultadosPartial = false;

        return $this;
    }

    /**
     * Returns the number of related Resultado objects.
     *
     * @param      Criteria $criteria
     * @param      boolean $distinct
     * @param      ConnectionInterface $con
     * @return int             Count of related Resultado objects.
     * @throws PropelException
     */
    public function countResultados(Criteria $criteria = null, $distinct = false, ConnectionInterface $con = null)
    {
        $partial = $this->collResultadosPartial && !$this->isNew();
        if (null === $this->collResultados || null !== $criteria || $partial) {
            if ($this->isNew() && null === $this->collResultados) {
                return 0;
            }

            if ($partial && !$criteria) {
                return count($this->getResultados());
            }

            $query = ChildResultadoQuery::create(null, $criteria);
            if ($distinct) {
                $query->distinct();
            }

            return $query
                ->filterByEvento($this)
                ->count($con);
        }

        return count($this->collResultados);
    }

    /**
     * Method called to associate a ChildResultado object to this object
     * through the ChildResultado foreign key attribute.
     *
     * @param  ChildResultado $l ChildResultado
     * @return $this|\Baja\Model\Evento The current object (for fluent API support)
     */
    public function addResultado(ChildResultado $l)
    {
        if ($this->collResultados === null) {
            $this->initResultados();
            $this->collResultadosPartial = true;
        }

        if (!$this->collResultados->contains($l)) {
            $this->doAddResultado($l);

            if ($this->resultadosScheduledForDeletion and $this->resultadosScheduledForDeletion->contains($l)) {
                $this->resultadosScheduledForDeletion->remove($this->resultadosScheduledForDeletion->search($l));
            }
        }

        return $this;
    }

    /**
     * @param ChildResultado $resultado The ChildResultado object to add.
     */
    protected function doAddResultado(ChildResultado $resultado)
    {
        $this->collResultados[]= $resultado;
        $resultado->setEvento($this);
    }

    /**
     * @param  ChildResultado $resultado The ChildResultado object to remove.
     * @return $this|ChildEvento The current object (for fluent API support)
     */
    public function removeResultado(ChildResultado $resultado)
    {
        if ($this->getResultados()->contains($resultado)) {
            $pos = $this->collResultados->search($resultado);
            $this->collResultados->remove($pos);
            if (null === $this->resultadosScheduledForDeletion) {
                $this->resultadosScheduledForDeletion = clone $this->collResultados;
                $this->resultadosScheduledForDeletion->clear();
            }
            $this->resultadosScheduledForDeletion[]= clone $resultado;
            $resultado->setEvento(null);
        }

        return $this;
    }

    /**
     * Clears the current object, sets all attributes to their default values and removes
     * outgoing references as well as back-references (from other objects to this one. Results probably in a database
     * change of those foreign objects when you call `save` there).
     */
    public function clear()
    {
        $this->evento_id = null;
        $this->titulo = null;
        $this->nome = null;
        $this->tipo = null;
        $this->ano = null;
        $this->menu = null;
        $this->ativo = null;
        $this->principal = null;
        $this->finalizado = null;
        $this->spoilers = null;
        $this->tem_certificado = null;
        $this->presidente = null;
        $this->data = null;
        $this->mandato_presidente = null;
        $this->local = null;
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
            if ($this->collEquipes) {
                foreach ($this->collEquipes as $o) {
                    $o->clearAllReferences($deep);
                }
            }
            if ($this->collParticipantes) {
                foreach ($this->collParticipantes as $o) {
                    $o->clearAllReferences($deep);
                }
            }
            if ($this->collProvas) {
                foreach ($this->collProvas as $o) {
                    $o->clearAllReferences($deep);
                }
            }
            if ($this->collResultados) {
                foreach ($this->collResultados as $o) {
                    $o->clearAllReferences($deep);
                }
            }
        } // if ($deep)

        $this->collEquipes = null;
        $this->collParticipantes = null;
        $this->collProvas = null;
        $this->collResultados = null;
    }

    /**
     * Return the string representation of this object
     *
     * @return string
     */
    public function __toString()
    {
        return (string) $this->exportTo(EventoTableMap::DEFAULT_STRING_FORMAT);
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
