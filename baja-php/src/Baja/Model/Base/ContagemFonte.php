<?php

namespace Baja\Model\Base;

use \Exception;
use \PDO;
use Baja\Model\ContagemDispositivo as ChildContagemDispositivo;
use Baja\Model\ContagemDispositivoQuery as ChildContagemDispositivoQuery;
use Baja\Model\ContagemFonte as ChildContagemFonte;
use Baja\Model\ContagemFonteQuery as ChildContagemFonteQuery;
use Baja\Model\ContagemFonteUsuario as ChildContagemFonteUsuario;
use Baja\Model\ContagemFonteUsuarioQuery as ChildContagemFonteUsuarioQuery;
use Baja\Model\ContagemPassagem as ChildContagemPassagem;
use Baja\Model\ContagemPassagemQuery as ChildContagemPassagemQuery;
use Baja\Model\ContagemTotal as ChildContagemTotal;
use Baja\Model\ContagemTotalQuery as ChildContagemTotalQuery;
use Baja\Model\Prova as ChildProva;
use Baja\Model\ProvaQuery as ChildProvaQuery;
use Baja\Model\Map\ContagemDispositivoTableMap;
use Baja\Model\Map\ContagemFonteTableMap;
use Baja\Model\Map\ContagemFonteUsuarioTableMap;
use Baja\Model\Map\ContagemPassagemTableMap;
use Baja\Model\Map\ContagemTotalTableMap;
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
 * Base class that represents a row from the 'contagem_fonte' table.
 *
 *
 *
 * @package    propel.generator.Baja.Model.Base
 */
abstract class ContagemFonte implements ActiveRecordInterface
{
    /**
     * TableMap class name
     *
     * @var string
     */
    public const TABLE_MAP = '\\Baja\\Model\\Map\\ContagemFonteTableMap';


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
     * The value for the fonte_id field.
     *
     * @var        int
     */
    protected $fonte_id;

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
     * The value for the nome field.
     *
     * @var        string
     */
    protected $nome;

    /**
     * The value for the tipo field.
     *
     * Note: this column has a database default value of: 'manual'
     * @var        string
     */
    protected $tipo;

    /**
     * The value for the qualidade_tempo field.
     *
     * Note: this column has a database default value of: 'humana'
     * @var        string
     */
    protected $qualidade_tempo;

    /**
     * The value for the ativa field.
     *
     * Note: this column has a database default value of: true
     * @var        boolean
     */
    protected $ativa;

    /**
     * The value for the ordem field.
     *
     * Note: this column has a database default value of: 0
     * @var        int
     */
    protected $ordem;

    /**
     * The value for the desativada_em_ms field.
     *
     * @var        string|null
     */
    protected $desativada_em_ms;

    /**
     * @var        ChildProva
     */
    protected $aProva;

    /**
     * @var        ObjectCollection|ChildContagemFonteUsuario[] Collection to store aggregation of ChildContagemFonteUsuario objects.
     * @phpstan-var ObjectCollection&\Traversable<ChildContagemFonteUsuario> Collection to store aggregation of ChildContagemFonteUsuario objects.
     */
    protected $collContagemFonteUsuarios;
    protected $collContagemFonteUsuariosPartial;

    /**
     * @var        ObjectCollection|ChildContagemDispositivo[] Collection to store aggregation of ChildContagemDispositivo objects.
     * @phpstan-var ObjectCollection&\Traversable<ChildContagemDispositivo> Collection to store aggregation of ChildContagemDispositivo objects.
     */
    protected $collContagemDispositivos;
    protected $collContagemDispositivosPartial;

    /**
     * @var        ObjectCollection|ChildContagemPassagem[] Collection to store aggregation of ChildContagemPassagem objects.
     * @phpstan-var ObjectCollection&\Traversable<ChildContagemPassagem> Collection to store aggregation of ChildContagemPassagem objects.
     */
    protected $collContagemPassagems;
    protected $collContagemPassagemsPartial;

    /**
     * @var        ObjectCollection|ChildContagemTotal[] Collection to store aggregation of ChildContagemTotal objects.
     * @phpstan-var ObjectCollection&\Traversable<ChildContagemTotal> Collection to store aggregation of ChildContagemTotal objects.
     */
    protected $collContagemTotals;
    protected $collContagemTotalsPartial;

    /**
     * Flag to prevent endless save loop, if this object is referenced
     * by another object which falls in this transaction.
     *
     * @var bool
     */
    protected $alreadyInSave = false;

    /**
     * An array of objects scheduled for deletion.
     * @var ObjectCollection|ChildContagemFonteUsuario[]
     * @phpstan-var ObjectCollection&\Traversable<ChildContagemFonteUsuario>
     */
    protected $contagemFonteUsuariosScheduledForDeletion = null;

    /**
     * An array of objects scheduled for deletion.
     * @var ObjectCollection|ChildContagemDispositivo[]
     * @phpstan-var ObjectCollection&\Traversable<ChildContagemDispositivo>
     */
    protected $contagemDispositivosScheduledForDeletion = null;

    /**
     * An array of objects scheduled for deletion.
     * @var ObjectCollection|ChildContagemPassagem[]
     * @phpstan-var ObjectCollection&\Traversable<ChildContagemPassagem>
     */
    protected $contagemPassagemsScheduledForDeletion = null;

    /**
     * An array of objects scheduled for deletion.
     * @var ObjectCollection|ChildContagemTotal[]
     * @phpstan-var ObjectCollection&\Traversable<ChildContagemTotal>
     */
    protected $contagemTotalsScheduledForDeletion = null;

    /**
     * Applies default values to this object.
     * This method should be called from the object's constructor (or
     * equivalent initialization method).
     * @see __construct()
     */
    public function applyDefaultValues(): void
    {
        $this->tipo = 'manual';
        $this->qualidade_tempo = 'humana';
        $this->ativa = true;
        $this->ordem = 0;
    }

    /**
     * Initializes internal state of Baja\Model\Base\ContagemFonte object.
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
     * Compares this with another <code>ContagemFonte</code> instance.  If
     * <code>obj</code> is an instance of <code>ContagemFonte</code>, delegates to
     * <code>equals(ContagemFonte)</code>.  Otherwise, returns <code>false</code>.
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
     * Get the [fonte_id] column value.
     *
     * @return int
     */
    public function getFonteId()
    {
        return $this->fonte_id;
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
     */
    public function getTipo()
    {
        return $this->tipo;
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
     * Get the [ativa] column value.
     *
     * @return boolean
     */
    public function getAtiva()
    {
        return $this->ativa;
    }

    /**
     * Get the [ativa] column value.
     *
     * @return boolean
     */
    public function isAtiva()
    {
        return $this->getAtiva();
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
     * Get the [desativada_em_ms] column value.
     *
     * @return string|null
     */
    public function getDesativadaEmMs()
    {
        return $this->desativada_em_ms;
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
            $this->modifiedColumns[ContagemFonteTableMap::COL_FONTE_ID] = true;
        }

        return $this;
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
            $this->modifiedColumns[ContagemFonteTableMap::COL_EVENTO_ID] = true;
        }

        if ($this->aProva !== null && $this->aProva->getEventoId() !== $v) {
            $this->aProva = null;
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
            $this->modifiedColumns[ContagemFonteTableMap::COL_PROVA_ID] = true;
        }

        if ($this->aProva !== null && $this->aProva->getProvaId() !== $v) {
            $this->aProva = null;
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
            $this->modifiedColumns[ContagemFonteTableMap::COL_NOME] = true;
        }

        return $this;
    }

    /**
     * Set the value of [tipo] column.
     *
     * @param string $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setTipo($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->tipo !== $v) {
            $this->tipo = $v;
            $this->modifiedColumns[ContagemFonteTableMap::COL_TIPO] = true;
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
            $this->modifiedColumns[ContagemFonteTableMap::COL_QUALIDADE_TEMPO] = true;
        }

        return $this;
    }

    /**
     * Sets the value of the [ativa] column.
     * Non-boolean arguments are converted using the following rules:
     *   * 1, '1', 'true',  'on',  and 'yes' are converted to boolean true
     *   * 0, '0', 'false', 'off', and 'no'  are converted to boolean false
     * Check on string values is case insensitive (so 'FaLsE' is seen as 'false').
     *
     * @param bool|integer|string $v The new value
     * @return $this The current object (for fluent API support)
     */
    public function setAtiva($v)
    {
        if ($v !== null) {
            if (is_string($v)) {
                $v = in_array(strtolower($v), array('false', 'off', '-', 'no', 'n', '0', '')) ? false : true;
            } else {
                $v = (boolean) $v;
            }
        }

        if ($this->ativa !== $v) {
            $this->ativa = $v;
            $this->modifiedColumns[ContagemFonteTableMap::COL_ATIVA] = true;
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
            $this->modifiedColumns[ContagemFonteTableMap::COL_ORDEM] = true;
        }

        return $this;
    }

    /**
     * Set the value of [desativada_em_ms] column.
     *
     * @param string|null $v New value
     * @return $this The current object (for fluent API support)
     */
    public function setDesativadaEmMs($v)
    {
        if ($v !== null) {
            $v = (string) $v;
        }

        if ($this->desativada_em_ms !== $v) {
            $this->desativada_em_ms = $v;
            $this->modifiedColumns[ContagemFonteTableMap::COL_DESATIVADA_EM_MS] = true;
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
            if ($this->tipo !== 'manual') {
                return false;
            }

            if ($this->qualidade_tempo !== 'humana') {
                return false;
            }

            if ($this->ativa !== true) {
                return false;
            }

            if ($this->ordem !== 0) {
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

            $col = $row[TableMap::TYPE_NUM == $indexType ? 0 + $startcol : ContagemFonteTableMap::translateFieldName('FonteId', TableMap::TYPE_PHPNAME, $indexType)];
            $this->fonte_id = (null !== $col) ? (int) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 1 + $startcol : ContagemFonteTableMap::translateFieldName('EventoId', TableMap::TYPE_PHPNAME, $indexType)];
            $this->evento_id = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 2 + $startcol : ContagemFonteTableMap::translateFieldName('ProvaId', TableMap::TYPE_PHPNAME, $indexType)];
            $this->prova_id = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 3 + $startcol : ContagemFonteTableMap::translateFieldName('Nome', TableMap::TYPE_PHPNAME, $indexType)];
            $this->nome = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 4 + $startcol : ContagemFonteTableMap::translateFieldName('Tipo', TableMap::TYPE_PHPNAME, $indexType)];
            $this->tipo = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 5 + $startcol : ContagemFonteTableMap::translateFieldName('QualidadeTempo', TableMap::TYPE_PHPNAME, $indexType)];
            $this->qualidade_tempo = (null !== $col) ? (string) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 6 + $startcol : ContagemFonteTableMap::translateFieldName('Ativa', TableMap::TYPE_PHPNAME, $indexType)];
            $this->ativa = (null !== $col) ? (boolean) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 7 + $startcol : ContagemFonteTableMap::translateFieldName('Ordem', TableMap::TYPE_PHPNAME, $indexType)];
            $this->ordem = (null !== $col) ? (int) $col : null;

            $col = $row[TableMap::TYPE_NUM == $indexType ? 8 + $startcol : ContagemFonteTableMap::translateFieldName('DesativadaEmMs', TableMap::TYPE_PHPNAME, $indexType)];
            $this->desativada_em_ms = (null !== $col) ? (string) $col : null;

            $this->resetModified();
            $this->setNew(false);

            if ($rehydrate) {
                $this->ensureConsistency();
            }

            return $startcol + 9; // 9 = ContagemFonteTableMap::NUM_HYDRATE_COLUMNS.

        } catch (Exception $e) {
            throw new PropelException(sprintf('Error populating %s object', '\\Baja\\Model\\ContagemFonte'), 0, $e);
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
        if ($this->aProva !== null && $this->prova_id !== $this->aProva->getProvaId()) {
            $this->aProva = null;
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
            $con = Propel::getServiceContainer()->getReadConnection(ContagemFonteTableMap::DATABASE_NAME);
        }

        // We don't need to alter the object instance pool; we're just modifying this instance
        // already in the pool.

        $dataFetcher = ChildContagemFonteQuery::create(null, $this->buildPkeyCriteria())->setFormatter(ModelCriteria::FORMAT_STATEMENT)->find($con);
        $row = $dataFetcher->fetch();
        $dataFetcher->close();
        if (!$row) {
            throw new PropelException('Cannot find matching row in the database to reload object values.');
        }
        $this->hydrate($row, 0, true, $dataFetcher->getIndexType()); // rehydrate

        if ($deep) {  // also de-associate any related objects?

            $this->aProva = null;
            $this->collContagemFonteUsuarios = null;

            $this->collContagemDispositivos = null;

            $this->collContagemPassagems = null;

            $this->collContagemTotals = null;

        } // if (deep)
    }

    /**
     * Removes this object from datastore and sets delete attribute.
     *
     * @param ConnectionInterface $con
     * @return void
     * @throws \Propel\Runtime\Exception\PropelException
     * @see ContagemFonte::setDeleted()
     * @see ContagemFonte::isDeleted()
     */
    public function delete(?ConnectionInterface $con = null): void
    {
        if ($this->isDeleted()) {
            throw new PropelException("This object has already been deleted.");
        }

        if ($con === null) {
            $con = Propel::getServiceContainer()->getWriteConnection(ContagemFonteTableMap::DATABASE_NAME);
        }

        $con->transaction(function () use ($con) {
            $deleteQuery = ChildContagemFonteQuery::create()
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
            $con = Propel::getServiceContainer()->getWriteConnection(ContagemFonteTableMap::DATABASE_NAME);
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
                ContagemFonteTableMap::addInstanceToPool($this);
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

            if ($this->contagemFonteUsuariosScheduledForDeletion !== null) {
                if (!$this->contagemFonteUsuariosScheduledForDeletion->isEmpty()) {
                    \Baja\Model\ContagemFonteUsuarioQuery::create()
                        ->filterByPrimaryKeys($this->contagemFonteUsuariosScheduledForDeletion->getPrimaryKeys(false))
                        ->delete($con);
                    $this->contagemFonteUsuariosScheduledForDeletion = null;
                }
            }

            if ($this->collContagemFonteUsuarios !== null) {
                foreach ($this->collContagemFonteUsuarios as $referrerFK) {
                    if (!$referrerFK->isDeleted() && ($referrerFK->isNew() || $referrerFK->isModified())) {
                        $affectedRows += $referrerFK->save($con);
                    }
                }
            }

            if ($this->contagemDispositivosScheduledForDeletion !== null) {
                if (!$this->contagemDispositivosScheduledForDeletion->isEmpty()) {
                    \Baja\Model\ContagemDispositivoQuery::create()
                        ->filterByPrimaryKeys($this->contagemDispositivosScheduledForDeletion->getPrimaryKeys(false))
                        ->delete($con);
                    $this->contagemDispositivosScheduledForDeletion = null;
                }
            }

            if ($this->collContagemDispositivos !== null) {
                foreach ($this->collContagemDispositivos as $referrerFK) {
                    if (!$referrerFK->isDeleted() && ($referrerFK->isNew() || $referrerFK->isModified())) {
                        $affectedRows += $referrerFK->save($con);
                    }
                }
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

            if ($this->contagemTotalsScheduledForDeletion !== null) {
                if (!$this->contagemTotalsScheduledForDeletion->isEmpty()) {
                    \Baja\Model\ContagemTotalQuery::create()
                        ->filterByPrimaryKeys($this->contagemTotalsScheduledForDeletion->getPrimaryKeys(false))
                        ->delete($con);
                    $this->contagemTotalsScheduledForDeletion = null;
                }
            }

            if ($this->collContagemTotals !== null) {
                foreach ($this->collContagemTotals as $referrerFK) {
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

        $this->modifiedColumns[ContagemFonteTableMap::COL_FONTE_ID] = true;
        if (null !== $this->fonte_id) {
            throw new PropelException('Cannot insert a value for auto-increment primary key (' . ContagemFonteTableMap::COL_FONTE_ID . ')');
        }

         // check the columns in natural order for more readable SQL queries
        if ($this->isColumnModified(ContagemFonteTableMap::COL_FONTE_ID)) {
            $modifiedColumns[':p' . $index++]  = 'fonte_id';
        }
        if ($this->isColumnModified(ContagemFonteTableMap::COL_EVENTO_ID)) {
            $modifiedColumns[':p' . $index++]  = 'evento_id';
        }
        if ($this->isColumnModified(ContagemFonteTableMap::COL_PROVA_ID)) {
            $modifiedColumns[':p' . $index++]  = 'prova_id';
        }
        if ($this->isColumnModified(ContagemFonteTableMap::COL_NOME)) {
            $modifiedColumns[':p' . $index++]  = 'nome';
        }
        if ($this->isColumnModified(ContagemFonteTableMap::COL_TIPO)) {
            $modifiedColumns[':p' . $index++]  = 'tipo';
        }
        if ($this->isColumnModified(ContagemFonteTableMap::COL_QUALIDADE_TEMPO)) {
            $modifiedColumns[':p' . $index++]  = 'qualidade_tempo';
        }
        if ($this->isColumnModified(ContagemFonteTableMap::COL_ATIVA)) {
            $modifiedColumns[':p' . $index++]  = 'ativa';
        }
        if ($this->isColumnModified(ContagemFonteTableMap::COL_ORDEM)) {
            $modifiedColumns[':p' . $index++]  = 'ordem';
        }
        if ($this->isColumnModified(ContagemFonteTableMap::COL_DESATIVADA_EM_MS)) {
            $modifiedColumns[':p' . $index++]  = 'desativada_em_ms';
        }

        $sql = sprintf(
            'INSERT INTO contagem_fonte (%s) VALUES (%s)',
            implode(', ', $modifiedColumns),
            implode(', ', array_keys($modifiedColumns))
        );

        try {
            $stmt = $con->prepare($sql);
            foreach ($modifiedColumns as $identifier => $columnName) {
                switch ($columnName) {
                    case 'fonte_id':
                        $stmt->bindValue($identifier, $this->fonte_id, PDO::PARAM_INT);

                        break;
                    case 'evento_id':
                        $stmt->bindValue($identifier, $this->evento_id, PDO::PARAM_STR);

                        break;
                    case 'prova_id':
                        $stmt->bindValue($identifier, $this->prova_id, PDO::PARAM_STR);

                        break;
                    case 'nome':
                        $stmt->bindValue($identifier, $this->nome, PDO::PARAM_STR);

                        break;
                    case 'tipo':
                        $stmt->bindValue($identifier, $this->tipo, PDO::PARAM_STR);

                        break;
                    case 'qualidade_tempo':
                        $stmt->bindValue($identifier, $this->qualidade_tempo, PDO::PARAM_STR);

                        break;
                    case 'ativa':
                        $stmt->bindValue($identifier, (int) $this->ativa, PDO::PARAM_INT);

                        break;
                    case 'ordem':
                        $stmt->bindValue($identifier, $this->ordem, PDO::PARAM_INT);

                        break;
                    case 'desativada_em_ms':
                        $stmt->bindValue($identifier, $this->desativada_em_ms, PDO::PARAM_INT);

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
        $this->setFonteId($pk);

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
        $pos = ContagemFonteTableMap::translateFieldName($name, $type, TableMap::TYPE_NUM);
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
                return $this->getFonteId();

            case 1:
                return $this->getEventoId();

            case 2:
                return $this->getProvaId();

            case 3:
                return $this->getNome();

            case 4:
                return $this->getTipo();

            case 5:
                return $this->getQualidadeTempo();

            case 6:
                return $this->getAtiva();

            case 7:
                return $this->getOrdem();

            case 8:
                return $this->getDesativadaEmMs();

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
        if (isset($alreadyDumpedObjects['ContagemFonte'][$this->hashCode()])) {
            return ['*RECURSION*'];
        }
        $alreadyDumpedObjects['ContagemFonte'][$this->hashCode()] = true;
        $keys = ContagemFonteTableMap::getFieldNames($keyType);
        $result = [
            $keys[0] => $this->getFonteId(),
            $keys[1] => $this->getEventoId(),
            $keys[2] => $this->getProvaId(),
            $keys[3] => $this->getNome(),
            $keys[4] => $this->getTipo(),
            $keys[5] => $this->getQualidadeTempo(),
            $keys[6] => $this->getAtiva(),
            $keys[7] => $this->getOrdem(),
            $keys[8] => $this->getDesativadaEmMs(),
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
            if (null !== $this->collContagemFonteUsuarios) {

                switch ($keyType) {
                    case TableMap::TYPE_CAMELNAME:
                        $key = 'contagemFonteUsuarios';
                        break;
                    case TableMap::TYPE_FIELDNAME:
                        $key = 'contagem_fonte_usuarios';
                        break;
                    default:
                        $key = 'ContagemFonteUsuarios';
                }

                $result[$key] = $this->collContagemFonteUsuarios->toArray(null, false, $keyType, $includeLazyLoadColumns, $alreadyDumpedObjects);
            }
            if (null !== $this->collContagemDispositivos) {

                switch ($keyType) {
                    case TableMap::TYPE_CAMELNAME:
                        $key = 'contagemDispositivos';
                        break;
                    case TableMap::TYPE_FIELDNAME:
                        $key = 'contagem_dispositivos';
                        break;
                    default:
                        $key = 'ContagemDispositivos';
                }

                $result[$key] = $this->collContagemDispositivos->toArray(null, false, $keyType, $includeLazyLoadColumns, $alreadyDumpedObjects);
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
            if (null !== $this->collContagemTotals) {

                switch ($keyType) {
                    case TableMap::TYPE_CAMELNAME:
                        $key = 'contagemTotals';
                        break;
                    case TableMap::TYPE_FIELDNAME:
                        $key = 'contagem_totals';
                        break;
                    default:
                        $key = 'ContagemTotals';
                }

                $result[$key] = $this->collContagemTotals->toArray(null, false, $keyType, $includeLazyLoadColumns, $alreadyDumpedObjects);
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
        $pos = ContagemFonteTableMap::translateFieldName($name, $type, TableMap::TYPE_NUM);

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
                $this->setFonteId($value);
                break;
            case 1:
                $this->setEventoId($value);
                break;
            case 2:
                $this->setProvaId($value);
                break;
            case 3:
                $this->setNome($value);
                break;
            case 4:
                $this->setTipo($value);
                break;
            case 5:
                $this->setQualidadeTempo($value);
                break;
            case 6:
                $this->setAtiva($value);
                break;
            case 7:
                $this->setOrdem($value);
                break;
            case 8:
                $this->setDesativadaEmMs($value);
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
        $keys = ContagemFonteTableMap::getFieldNames($keyType);

        if (array_key_exists($keys[0], $arr)) {
            $this->setFonteId($arr[$keys[0]]);
        }
        if (array_key_exists($keys[1], $arr)) {
            $this->setEventoId($arr[$keys[1]]);
        }
        if (array_key_exists($keys[2], $arr)) {
            $this->setProvaId($arr[$keys[2]]);
        }
        if (array_key_exists($keys[3], $arr)) {
            $this->setNome($arr[$keys[3]]);
        }
        if (array_key_exists($keys[4], $arr)) {
            $this->setTipo($arr[$keys[4]]);
        }
        if (array_key_exists($keys[5], $arr)) {
            $this->setQualidadeTempo($arr[$keys[5]]);
        }
        if (array_key_exists($keys[6], $arr)) {
            $this->setAtiva($arr[$keys[6]]);
        }
        if (array_key_exists($keys[7], $arr)) {
            $this->setOrdem($arr[$keys[7]]);
        }
        if (array_key_exists($keys[8], $arr)) {
            $this->setDesativadaEmMs($arr[$keys[8]]);
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
        $criteria = new Criteria(ContagemFonteTableMap::DATABASE_NAME);

        if ($this->isColumnModified(ContagemFonteTableMap::COL_FONTE_ID)) {
            $criteria->add(ContagemFonteTableMap::COL_FONTE_ID, $this->fonte_id);
        }
        if ($this->isColumnModified(ContagemFonteTableMap::COL_EVENTO_ID)) {
            $criteria->add(ContagemFonteTableMap::COL_EVENTO_ID, $this->evento_id);
        }
        if ($this->isColumnModified(ContagemFonteTableMap::COL_PROVA_ID)) {
            $criteria->add(ContagemFonteTableMap::COL_PROVA_ID, $this->prova_id);
        }
        if ($this->isColumnModified(ContagemFonteTableMap::COL_NOME)) {
            $criteria->add(ContagemFonteTableMap::COL_NOME, $this->nome);
        }
        if ($this->isColumnModified(ContagemFonteTableMap::COL_TIPO)) {
            $criteria->add(ContagemFonteTableMap::COL_TIPO, $this->tipo);
        }
        if ($this->isColumnModified(ContagemFonteTableMap::COL_QUALIDADE_TEMPO)) {
            $criteria->add(ContagemFonteTableMap::COL_QUALIDADE_TEMPO, $this->qualidade_tempo);
        }
        if ($this->isColumnModified(ContagemFonteTableMap::COL_ATIVA)) {
            $criteria->add(ContagemFonteTableMap::COL_ATIVA, $this->ativa);
        }
        if ($this->isColumnModified(ContagemFonteTableMap::COL_ORDEM)) {
            $criteria->add(ContagemFonteTableMap::COL_ORDEM, $this->ordem);
        }
        if ($this->isColumnModified(ContagemFonteTableMap::COL_DESATIVADA_EM_MS)) {
            $criteria->add(ContagemFonteTableMap::COL_DESATIVADA_EM_MS, $this->desativada_em_ms);
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
        $criteria = ChildContagemFonteQuery::create();
        $criteria->add(ContagemFonteTableMap::COL_FONTE_ID, $this->fonte_id);

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
        $validPk = null !== $this->getFonteId();

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
        return $this->getFonteId();
    }

    /**
     * Generic method to set the primary key (fonte_id column).
     *
     * @param int|null $key Primary key.
     * @return void
     */
    public function setPrimaryKey(?int $key = null): void
    {
        $this->setFonteId($key);
    }

    /**
     * Returns true if the primary key for this object is null.
     *
     * @return bool
     */
    public function isPrimaryKeyNull(): bool
    {
        return null === $this->getFonteId();
    }

    /**
     * Sets contents of passed object to values from current object.
     *
     * If desired, this method can also make copies of all associated (fkey referrers)
     * objects.
     *
     * @param object $copyObj An object of \Baja\Model\ContagemFonte (or compatible) type.
     * @param bool $deepCopy Whether to also copy all rows that refer (by fkey) to the current row.
     * @param bool $makeNew Whether to reset autoincrement PKs and make the object new.
     * @throws \Propel\Runtime\Exception\PropelException
     * @return void
     */
    public function copyInto(object $copyObj, bool $deepCopy = false, bool $makeNew = true): void
    {
        $copyObj->setEventoId($this->getEventoId());
        $copyObj->setProvaId($this->getProvaId());
        $copyObj->setNome($this->getNome());
        $copyObj->setTipo($this->getTipo());
        $copyObj->setQualidadeTempo($this->getQualidadeTempo());
        $copyObj->setAtiva($this->getAtiva());
        $copyObj->setOrdem($this->getOrdem());
        $copyObj->setDesativadaEmMs($this->getDesativadaEmMs());

        if ($deepCopy) {
            // important: temporarily setNew(false) because this affects the behavior of
            // the getter/setter methods for fkey referrer objects.
            $copyObj->setNew(false);

            foreach ($this->getContagemFonteUsuarios() as $relObj) {
                if ($relObj !== $this) {  // ensure that we don't try to copy a reference to ourselves
                    $copyObj->addContagemFonteUsuario($relObj->copy($deepCopy));
                }
            }

            foreach ($this->getContagemDispositivos() as $relObj) {
                if ($relObj !== $this) {  // ensure that we don't try to copy a reference to ourselves
                    $copyObj->addContagemDispositivo($relObj->copy($deepCopy));
                }
            }

            foreach ($this->getContagemPassagems() as $relObj) {
                if ($relObj !== $this) {  // ensure that we don't try to copy a reference to ourselves
                    $copyObj->addContagemPassagem($relObj->copy($deepCopy));
                }
            }

            foreach ($this->getContagemTotals() as $relObj) {
                if ($relObj !== $this) {  // ensure that we don't try to copy a reference to ourselves
                    $copyObj->addContagemTotal($relObj->copy($deepCopy));
                }
            }

        } // if ($deepCopy)

        if ($makeNew) {
            $copyObj->setNew(true);
            $copyObj->setFonteId(NULL); // this is a auto-increment column, so set to default value
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
     * @return \Baja\Model\ContagemFonte Clone of current object.
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
            $v->addContagemFonte($this);
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
                $this->aProva->addContagemFontes($this);
             */
        }

        return $this->aProva;
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
        if ('ContagemFonteUsuario' === $relationName) {
            $this->initContagemFonteUsuarios();
            return;
        }
        if ('ContagemDispositivo' === $relationName) {
            $this->initContagemDispositivos();
            return;
        }
        if ('ContagemPassagem' === $relationName) {
            $this->initContagemPassagems();
            return;
        }
        if ('ContagemTotal' === $relationName) {
            $this->initContagemTotals();
            return;
        }
    }

    /**
     * Clears out the collContagemFonteUsuarios collection
     *
     * This does not modify the database; however, it will remove any associated objects, causing
     * them to be refetched by subsequent calls to accessor method.
     *
     * @return $this
     * @see addContagemFonteUsuarios()
     */
    public function clearContagemFonteUsuarios()
    {
        $this->collContagemFonteUsuarios = null; // important to set this to NULL since that means it is uninitialized

        return $this;
    }

    /**
     * Reset is the collContagemFonteUsuarios collection loaded partially.
     *
     * @return void
     */
    public function resetPartialContagemFonteUsuarios($v = true): void
    {
        $this->collContagemFonteUsuariosPartial = $v;
    }

    /**
     * Initializes the collContagemFonteUsuarios collection.
     *
     * By default this just sets the collContagemFonteUsuarios collection to an empty array (like clearcollContagemFonteUsuarios());
     * however, you may wish to override this method in your stub class to provide setting appropriate
     * to your application -- for example, setting the initial array to the values stored in database.
     *
     * @param bool $overrideExisting If set to true, the method call initializes
     *                                        the collection even if it is not empty
     *
     * @return void
     */
    public function initContagemFonteUsuarios(bool $overrideExisting = true): void
    {
        if (null !== $this->collContagemFonteUsuarios && !$overrideExisting) {
            return;
        }

        $collectionClassName = ContagemFonteUsuarioTableMap::getTableMap()->getCollectionClassName();

        $this->collContagemFonteUsuarios = new $collectionClassName;
        $this->collContagemFonteUsuarios->setModel('\Baja\Model\ContagemFonteUsuario');
    }

    /**
     * Gets an array of ChildContagemFonteUsuario objects which contain a foreign key that references this object.
     *
     * If the $criteria is not null, it is used to always fetch the results from the database.
     * Otherwise the results are fetched from the database the first time, then cached.
     * Next time the same method is called without $criteria, the cached collection is returned.
     * If this ChildContagemFonte is new, it will return
     * an empty collection or the current collection; the criteria is ignored on a new object.
     *
     * @param Criteria $criteria optional Criteria object to narrow the query
     * @param ConnectionInterface $con optional connection object
     * @return ObjectCollection|ChildContagemFonteUsuario[] List of ChildContagemFonteUsuario objects
     * @phpstan-return ObjectCollection&\Traversable<ChildContagemFonteUsuario> List of ChildContagemFonteUsuario objects
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function getContagemFonteUsuarios(?Criteria $criteria = null, ?ConnectionInterface $con = null)
    {
        $partial = $this->collContagemFonteUsuariosPartial && !$this->isNew();
        if (null === $this->collContagemFonteUsuarios || null !== $criteria || $partial) {
            if ($this->isNew()) {
                // return empty collection
                if (null === $this->collContagemFonteUsuarios) {
                    $this->initContagemFonteUsuarios();
                } else {
                    $collectionClassName = ContagemFonteUsuarioTableMap::getTableMap()->getCollectionClassName();

                    $collContagemFonteUsuarios = new $collectionClassName;
                    $collContagemFonteUsuarios->setModel('\Baja\Model\ContagemFonteUsuario');

                    return $collContagemFonteUsuarios;
                }
            } else {
                $collContagemFonteUsuarios = ChildContagemFonteUsuarioQuery::create(null, $criteria)
                    ->filterByContagemFonte($this)
                    ->find($con);

                if (null !== $criteria) {
                    if (false !== $this->collContagemFonteUsuariosPartial && count($collContagemFonteUsuarios)) {
                        $this->initContagemFonteUsuarios(false);

                        foreach ($collContagemFonteUsuarios as $obj) {
                            if (false == $this->collContagemFonteUsuarios->contains($obj)) {
                                $this->collContagemFonteUsuarios->append($obj);
                            }
                        }

                        $this->collContagemFonteUsuariosPartial = true;
                    }

                    return $collContagemFonteUsuarios;
                }

                if ($partial && $this->collContagemFonteUsuarios) {
                    foreach ($this->collContagemFonteUsuarios as $obj) {
                        if ($obj->isNew()) {
                            $collContagemFonteUsuarios[] = $obj;
                        }
                    }
                }

                $this->collContagemFonteUsuarios = $collContagemFonteUsuarios;
                $this->collContagemFonteUsuariosPartial = false;
            }
        }

        return $this->collContagemFonteUsuarios;
    }

    /**
     * Sets a collection of ChildContagemFonteUsuario objects related by a one-to-many relationship
     * to the current object.
     * It will also schedule objects for deletion based on a diff between old objects (aka persisted)
     * and new objects from the given Propel collection.
     *
     * @param Collection $contagemFonteUsuarios A Propel collection.
     * @param ConnectionInterface $con Optional connection object
     * @return $this The current object (for fluent API support)
     */
    public function setContagemFonteUsuarios(Collection $contagemFonteUsuarios, ?ConnectionInterface $con = null)
    {
        /** @var ChildContagemFonteUsuario[] $contagemFonteUsuariosToDelete */
        $contagemFonteUsuariosToDelete = $this->getContagemFonteUsuarios(new Criteria(), $con)->diff($contagemFonteUsuarios);


        //since at least one column in the foreign key is at the same time a PK
        //we can not just set a PK to NULL in the lines below. We have to store
        //a backup of all values, so we are able to manipulate these items based on the onDelete value later.
        $this->contagemFonteUsuariosScheduledForDeletion = clone $contagemFonteUsuariosToDelete;

        foreach ($contagemFonteUsuariosToDelete as $contagemFonteUsuarioRemoved) {
            $contagemFonteUsuarioRemoved->setContagemFonte(null);
        }

        $this->collContagemFonteUsuarios = null;
        foreach ($contagemFonteUsuarios as $contagemFonteUsuario) {
            $this->addContagemFonteUsuario($contagemFonteUsuario);
        }

        $this->collContagemFonteUsuarios = $contagemFonteUsuarios;
        $this->collContagemFonteUsuariosPartial = false;

        return $this;
    }

    /**
     * Returns the number of related ContagemFonteUsuario objects.
     *
     * @param Criteria $criteria
     * @param bool $distinct
     * @param ConnectionInterface $con
     * @return int Count of related ContagemFonteUsuario objects.
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function countContagemFonteUsuarios(?Criteria $criteria = null, bool $distinct = false, ?ConnectionInterface $con = null): int
    {
        $partial = $this->collContagemFonteUsuariosPartial && !$this->isNew();
        if (null === $this->collContagemFonteUsuarios || null !== $criteria || $partial) {
            if ($this->isNew() && null === $this->collContagemFonteUsuarios) {
                return 0;
            }

            if ($partial && !$criteria) {
                return count($this->getContagemFonteUsuarios());
            }

            $query = ChildContagemFonteUsuarioQuery::create(null, $criteria);
            if ($distinct) {
                $query->distinct();
            }

            return $query
                ->filterByContagemFonte($this)
                ->count($con);
        }

        return count($this->collContagemFonteUsuarios);
    }

    /**
     * Method called to associate a ChildContagemFonteUsuario object to this object
     * through the ChildContagemFonteUsuario foreign key attribute.
     *
     * @param ChildContagemFonteUsuario $l ChildContagemFonteUsuario
     * @return $this The current object (for fluent API support)
     */
    public function addContagemFonteUsuario(ChildContagemFonteUsuario $l)
    {
        if ($this->collContagemFonteUsuarios === null) {
            $this->initContagemFonteUsuarios();
            $this->collContagemFonteUsuariosPartial = true;
        }

        if (!$this->collContagemFonteUsuarios->contains($l)) {
            $this->doAddContagemFonteUsuario($l);

            if ($this->contagemFonteUsuariosScheduledForDeletion and $this->contagemFonteUsuariosScheduledForDeletion->contains($l)) {
                $this->contagemFonteUsuariosScheduledForDeletion->remove($this->contagemFonteUsuariosScheduledForDeletion->search($l));
            }
        }

        return $this;
    }

    /**
     * @param ChildContagemFonteUsuario $contagemFonteUsuario The ChildContagemFonteUsuario object to add.
     */
    protected function doAddContagemFonteUsuario(ChildContagemFonteUsuario $contagemFonteUsuario): void
    {
        $this->collContagemFonteUsuarios[]= $contagemFonteUsuario;
        $contagemFonteUsuario->setContagemFonte($this);
    }

    /**
     * @param ChildContagemFonteUsuario $contagemFonteUsuario The ChildContagemFonteUsuario object to remove.
     * @return $this The current object (for fluent API support)
     */
    public function removeContagemFonteUsuario(ChildContagemFonteUsuario $contagemFonteUsuario)
    {
        if ($this->getContagemFonteUsuarios()->contains($contagemFonteUsuario)) {
            $pos = $this->collContagemFonteUsuarios->search($contagemFonteUsuario);
            $this->collContagemFonteUsuarios->remove($pos);
            if (null === $this->contagemFonteUsuariosScheduledForDeletion) {
                $this->contagemFonteUsuariosScheduledForDeletion = clone $this->collContagemFonteUsuarios;
                $this->contagemFonteUsuariosScheduledForDeletion->clear();
            }
            $this->contagemFonteUsuariosScheduledForDeletion[]= clone $contagemFonteUsuario;
            $contagemFonteUsuario->setContagemFonte(null);
        }

        return $this;
    }

    /**
     * Clears out the collContagemDispositivos collection
     *
     * This does not modify the database; however, it will remove any associated objects, causing
     * them to be refetched by subsequent calls to accessor method.
     *
     * @return $this
     * @see addContagemDispositivos()
     */
    public function clearContagemDispositivos()
    {
        $this->collContagemDispositivos = null; // important to set this to NULL since that means it is uninitialized

        return $this;
    }

    /**
     * Reset is the collContagemDispositivos collection loaded partially.
     *
     * @return void
     */
    public function resetPartialContagemDispositivos($v = true): void
    {
        $this->collContagemDispositivosPartial = $v;
    }

    /**
     * Initializes the collContagemDispositivos collection.
     *
     * By default this just sets the collContagemDispositivos collection to an empty array (like clearcollContagemDispositivos());
     * however, you may wish to override this method in your stub class to provide setting appropriate
     * to your application -- for example, setting the initial array to the values stored in database.
     *
     * @param bool $overrideExisting If set to true, the method call initializes
     *                                        the collection even if it is not empty
     *
     * @return void
     */
    public function initContagemDispositivos(bool $overrideExisting = true): void
    {
        if (null !== $this->collContagemDispositivos && !$overrideExisting) {
            return;
        }

        $collectionClassName = ContagemDispositivoTableMap::getTableMap()->getCollectionClassName();

        $this->collContagemDispositivos = new $collectionClassName;
        $this->collContagemDispositivos->setModel('\Baja\Model\ContagemDispositivo');
    }

    /**
     * Gets an array of ChildContagemDispositivo objects which contain a foreign key that references this object.
     *
     * If the $criteria is not null, it is used to always fetch the results from the database.
     * Otherwise the results are fetched from the database the first time, then cached.
     * Next time the same method is called without $criteria, the cached collection is returned.
     * If this ChildContagemFonte is new, it will return
     * an empty collection or the current collection; the criteria is ignored on a new object.
     *
     * @param Criteria $criteria optional Criteria object to narrow the query
     * @param ConnectionInterface $con optional connection object
     * @return ObjectCollection|ChildContagemDispositivo[] List of ChildContagemDispositivo objects
     * @phpstan-return ObjectCollection&\Traversable<ChildContagemDispositivo> List of ChildContagemDispositivo objects
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function getContagemDispositivos(?Criteria $criteria = null, ?ConnectionInterface $con = null)
    {
        $partial = $this->collContagemDispositivosPartial && !$this->isNew();
        if (null === $this->collContagemDispositivos || null !== $criteria || $partial) {
            if ($this->isNew()) {
                // return empty collection
                if (null === $this->collContagemDispositivos) {
                    $this->initContagemDispositivos();
                } else {
                    $collectionClassName = ContagemDispositivoTableMap::getTableMap()->getCollectionClassName();

                    $collContagemDispositivos = new $collectionClassName;
                    $collContagemDispositivos->setModel('\Baja\Model\ContagemDispositivo');

                    return $collContagemDispositivos;
                }
            } else {
                $collContagemDispositivos = ChildContagemDispositivoQuery::create(null, $criteria)
                    ->filterByContagemFonte($this)
                    ->find($con);

                if (null !== $criteria) {
                    if (false !== $this->collContagemDispositivosPartial && count($collContagemDispositivos)) {
                        $this->initContagemDispositivos(false);

                        foreach ($collContagemDispositivos as $obj) {
                            if (false == $this->collContagemDispositivos->contains($obj)) {
                                $this->collContagemDispositivos->append($obj);
                            }
                        }

                        $this->collContagemDispositivosPartial = true;
                    }

                    return $collContagemDispositivos;
                }

                if ($partial && $this->collContagemDispositivos) {
                    foreach ($this->collContagemDispositivos as $obj) {
                        if ($obj->isNew()) {
                            $collContagemDispositivos[] = $obj;
                        }
                    }
                }

                $this->collContagemDispositivos = $collContagemDispositivos;
                $this->collContagemDispositivosPartial = false;
            }
        }

        return $this->collContagemDispositivos;
    }

    /**
     * Sets a collection of ChildContagemDispositivo objects related by a one-to-many relationship
     * to the current object.
     * It will also schedule objects for deletion based on a diff between old objects (aka persisted)
     * and new objects from the given Propel collection.
     *
     * @param Collection $contagemDispositivos A Propel collection.
     * @param ConnectionInterface $con Optional connection object
     * @return $this The current object (for fluent API support)
     */
    public function setContagemDispositivos(Collection $contagemDispositivos, ?ConnectionInterface $con = null)
    {
        /** @var ChildContagemDispositivo[] $contagemDispositivosToDelete */
        $contagemDispositivosToDelete = $this->getContagemDispositivos(new Criteria(), $con)->diff($contagemDispositivos);


        $this->contagemDispositivosScheduledForDeletion = $contagemDispositivosToDelete;

        foreach ($contagemDispositivosToDelete as $contagemDispositivoRemoved) {
            $contagemDispositivoRemoved->setContagemFonte(null);
        }

        $this->collContagemDispositivos = null;
        foreach ($contagemDispositivos as $contagemDispositivo) {
            $this->addContagemDispositivo($contagemDispositivo);
        }

        $this->collContagemDispositivos = $contagemDispositivos;
        $this->collContagemDispositivosPartial = false;

        return $this;
    }

    /**
     * Returns the number of related ContagemDispositivo objects.
     *
     * @param Criteria $criteria
     * @param bool $distinct
     * @param ConnectionInterface $con
     * @return int Count of related ContagemDispositivo objects.
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function countContagemDispositivos(?Criteria $criteria = null, bool $distinct = false, ?ConnectionInterface $con = null): int
    {
        $partial = $this->collContagemDispositivosPartial && !$this->isNew();
        if (null === $this->collContagemDispositivos || null !== $criteria || $partial) {
            if ($this->isNew() && null === $this->collContagemDispositivos) {
                return 0;
            }

            if ($partial && !$criteria) {
                return count($this->getContagemDispositivos());
            }

            $query = ChildContagemDispositivoQuery::create(null, $criteria);
            if ($distinct) {
                $query->distinct();
            }

            return $query
                ->filterByContagemFonte($this)
                ->count($con);
        }

        return count($this->collContagemDispositivos);
    }

    /**
     * Method called to associate a ChildContagemDispositivo object to this object
     * through the ChildContagemDispositivo foreign key attribute.
     *
     * @param ChildContagemDispositivo $l ChildContagemDispositivo
     * @return $this The current object (for fluent API support)
     */
    public function addContagemDispositivo(ChildContagemDispositivo $l)
    {
        if ($this->collContagemDispositivos === null) {
            $this->initContagemDispositivos();
            $this->collContagemDispositivosPartial = true;
        }

        if (!$this->collContagemDispositivos->contains($l)) {
            $this->doAddContagemDispositivo($l);

            if ($this->contagemDispositivosScheduledForDeletion and $this->contagemDispositivosScheduledForDeletion->contains($l)) {
                $this->contagemDispositivosScheduledForDeletion->remove($this->contagemDispositivosScheduledForDeletion->search($l));
            }
        }

        return $this;
    }

    /**
     * @param ChildContagemDispositivo $contagemDispositivo The ChildContagemDispositivo object to add.
     */
    protected function doAddContagemDispositivo(ChildContagemDispositivo $contagemDispositivo): void
    {
        $this->collContagemDispositivos[]= $contagemDispositivo;
        $contagemDispositivo->setContagemFonte($this);
    }

    /**
     * @param ChildContagemDispositivo $contagemDispositivo The ChildContagemDispositivo object to remove.
     * @return $this The current object (for fluent API support)
     */
    public function removeContagemDispositivo(ChildContagemDispositivo $contagemDispositivo)
    {
        if ($this->getContagemDispositivos()->contains($contagemDispositivo)) {
            $pos = $this->collContagemDispositivos->search($contagemDispositivo);
            $this->collContagemDispositivos->remove($pos);
            if (null === $this->contagemDispositivosScheduledForDeletion) {
                $this->contagemDispositivosScheduledForDeletion = clone $this->collContagemDispositivos;
                $this->contagemDispositivosScheduledForDeletion->clear();
            }
            $this->contagemDispositivosScheduledForDeletion[]= clone $contagemDispositivo;
            $contagemDispositivo->setContagemFonte(null);
        }

        return $this;
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
     * If this ChildContagemFonte is new, it will return
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
                    ->filterByContagemFonte($this)
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
            $contagemPassagemRemoved->setContagemFonte(null);
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
                ->filterByContagemFonte($this)
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
        $contagemPassagem->setContagemFonte($this);
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
            $contagemPassagem->setContagemFonte(null);
        }

        return $this;
    }


    /**
     * If this collection has already been initialized with
     * an identical criteria, it returns the collection.
     * Otherwise if this ContagemFonte is new, it will return
     * an empty collection; or if this ContagemFonte has previously
     * been saved, it will retrieve related ContagemPassagems from storage.
     *
     * This method is protected by default in order to keep the public
     * api reasonable.  You can provide public methods for those you
     * actually need in ContagemFonte.
     *
     * @param Criteria $criteria optional Criteria object to narrow the query
     * @param ConnectionInterface $con optional connection object
     * @param string $joinBehavior optional join type to use (defaults to Criteria::LEFT_JOIN)
     * @return ObjectCollection|ChildContagemPassagem[] List of ChildContagemPassagem objects
     * @phpstan-return ObjectCollection&\Traversable<ChildContagemPassagem}> List of ChildContagemPassagem objects
     */
    public function getContagemPassagemsJoinContagemDispositivo(?Criteria $criteria = null, ?ConnectionInterface $con = null, $joinBehavior = Criteria::LEFT_JOIN)
    {
        $query = ChildContagemPassagemQuery::create(null, $criteria);
        $query->joinWith('ContagemDispositivo', $joinBehavior);

        return $this->getContagemPassagems($query, $con);
    }

    /**
     * Clears out the collContagemTotals collection
     *
     * This does not modify the database; however, it will remove any associated objects, causing
     * them to be refetched by subsequent calls to accessor method.
     *
     * @return $this
     * @see addContagemTotals()
     */
    public function clearContagemTotals()
    {
        $this->collContagemTotals = null; // important to set this to NULL since that means it is uninitialized

        return $this;
    }

    /**
     * Reset is the collContagemTotals collection loaded partially.
     *
     * @return void
     */
    public function resetPartialContagemTotals($v = true): void
    {
        $this->collContagemTotalsPartial = $v;
    }

    /**
     * Initializes the collContagemTotals collection.
     *
     * By default this just sets the collContagemTotals collection to an empty array (like clearcollContagemTotals());
     * however, you may wish to override this method in your stub class to provide setting appropriate
     * to your application -- for example, setting the initial array to the values stored in database.
     *
     * @param bool $overrideExisting If set to true, the method call initializes
     *                                        the collection even if it is not empty
     *
     * @return void
     */
    public function initContagemTotals(bool $overrideExisting = true): void
    {
        if (null !== $this->collContagemTotals && !$overrideExisting) {
            return;
        }

        $collectionClassName = ContagemTotalTableMap::getTableMap()->getCollectionClassName();

        $this->collContagemTotals = new $collectionClassName;
        $this->collContagemTotals->setModel('\Baja\Model\ContagemTotal');
    }

    /**
     * Gets an array of ChildContagemTotal objects which contain a foreign key that references this object.
     *
     * If the $criteria is not null, it is used to always fetch the results from the database.
     * Otherwise the results are fetched from the database the first time, then cached.
     * Next time the same method is called without $criteria, the cached collection is returned.
     * If this ChildContagemFonte is new, it will return
     * an empty collection or the current collection; the criteria is ignored on a new object.
     *
     * @param Criteria $criteria optional Criteria object to narrow the query
     * @param ConnectionInterface $con optional connection object
     * @return ObjectCollection|ChildContagemTotal[] List of ChildContagemTotal objects
     * @phpstan-return ObjectCollection&\Traversable<ChildContagemTotal> List of ChildContagemTotal objects
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function getContagemTotals(?Criteria $criteria = null, ?ConnectionInterface $con = null)
    {
        $partial = $this->collContagemTotalsPartial && !$this->isNew();
        if (null === $this->collContagemTotals || null !== $criteria || $partial) {
            if ($this->isNew()) {
                // return empty collection
                if (null === $this->collContagemTotals) {
                    $this->initContagemTotals();
                } else {
                    $collectionClassName = ContagemTotalTableMap::getTableMap()->getCollectionClassName();

                    $collContagemTotals = new $collectionClassName;
                    $collContagemTotals->setModel('\Baja\Model\ContagemTotal');

                    return $collContagemTotals;
                }
            } else {
                $collContagemTotals = ChildContagemTotalQuery::create(null, $criteria)
                    ->filterByContagemFonte($this)
                    ->find($con);

                if (null !== $criteria) {
                    if (false !== $this->collContagemTotalsPartial && count($collContagemTotals)) {
                        $this->initContagemTotals(false);

                        foreach ($collContagemTotals as $obj) {
                            if (false == $this->collContagemTotals->contains($obj)) {
                                $this->collContagemTotals->append($obj);
                            }
                        }

                        $this->collContagemTotalsPartial = true;
                    }

                    return $collContagemTotals;
                }

                if ($partial && $this->collContagemTotals) {
                    foreach ($this->collContagemTotals as $obj) {
                        if ($obj->isNew()) {
                            $collContagemTotals[] = $obj;
                        }
                    }
                }

                $this->collContagemTotals = $collContagemTotals;
                $this->collContagemTotalsPartial = false;
            }
        }

        return $this->collContagemTotals;
    }

    /**
     * Sets a collection of ChildContagemTotal objects related by a one-to-many relationship
     * to the current object.
     * It will also schedule objects for deletion based on a diff between old objects (aka persisted)
     * and new objects from the given Propel collection.
     *
     * @param Collection $contagemTotals A Propel collection.
     * @param ConnectionInterface $con Optional connection object
     * @return $this The current object (for fluent API support)
     */
    public function setContagemTotals(Collection $contagemTotals, ?ConnectionInterface $con = null)
    {
        /** @var ChildContagemTotal[] $contagemTotalsToDelete */
        $contagemTotalsToDelete = $this->getContagemTotals(new Criteria(), $con)->diff($contagemTotals);


        //since at least one column in the foreign key is at the same time a PK
        //we can not just set a PK to NULL in the lines below. We have to store
        //a backup of all values, so we are able to manipulate these items based on the onDelete value later.
        $this->contagemTotalsScheduledForDeletion = clone $contagemTotalsToDelete;

        foreach ($contagemTotalsToDelete as $contagemTotalRemoved) {
            $contagemTotalRemoved->setContagemFonte(null);
        }

        $this->collContagemTotals = null;
        foreach ($contagemTotals as $contagemTotal) {
            $this->addContagemTotal($contagemTotal);
        }

        $this->collContagemTotals = $contagemTotals;
        $this->collContagemTotalsPartial = false;

        return $this;
    }

    /**
     * Returns the number of related ContagemTotal objects.
     *
     * @param Criteria $criteria
     * @param bool $distinct
     * @param ConnectionInterface $con
     * @return int Count of related ContagemTotal objects.
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function countContagemTotals(?Criteria $criteria = null, bool $distinct = false, ?ConnectionInterface $con = null): int
    {
        $partial = $this->collContagemTotalsPartial && !$this->isNew();
        if (null === $this->collContagemTotals || null !== $criteria || $partial) {
            if ($this->isNew() && null === $this->collContagemTotals) {
                return 0;
            }

            if ($partial && !$criteria) {
                return count($this->getContagemTotals());
            }

            $query = ChildContagemTotalQuery::create(null, $criteria);
            if ($distinct) {
                $query->distinct();
            }

            return $query
                ->filterByContagemFonte($this)
                ->count($con);
        }

        return count($this->collContagemTotals);
    }

    /**
     * Method called to associate a ChildContagemTotal object to this object
     * through the ChildContagemTotal foreign key attribute.
     *
     * @param ChildContagemTotal $l ChildContagemTotal
     * @return $this The current object (for fluent API support)
     */
    public function addContagemTotal(ChildContagemTotal $l)
    {
        if ($this->collContagemTotals === null) {
            $this->initContagemTotals();
            $this->collContagemTotalsPartial = true;
        }

        if (!$this->collContagemTotals->contains($l)) {
            $this->doAddContagemTotal($l);

            if ($this->contagemTotalsScheduledForDeletion and $this->contagemTotalsScheduledForDeletion->contains($l)) {
                $this->contagemTotalsScheduledForDeletion->remove($this->contagemTotalsScheduledForDeletion->search($l));
            }
        }

        return $this;
    }

    /**
     * @param ChildContagemTotal $contagemTotal The ChildContagemTotal object to add.
     */
    protected function doAddContagemTotal(ChildContagemTotal $contagemTotal): void
    {
        $this->collContagemTotals[]= $contagemTotal;
        $contagemTotal->setContagemFonte($this);
    }

    /**
     * @param ChildContagemTotal $contagemTotal The ChildContagemTotal object to remove.
     * @return $this The current object (for fluent API support)
     */
    public function removeContagemTotal(ChildContagemTotal $contagemTotal)
    {
        if ($this->getContagemTotals()->contains($contagemTotal)) {
            $pos = $this->collContagemTotals->search($contagemTotal);
            $this->collContagemTotals->remove($pos);
            if (null === $this->contagemTotalsScheduledForDeletion) {
                $this->contagemTotalsScheduledForDeletion = clone $this->collContagemTotals;
                $this->contagemTotalsScheduledForDeletion->clear();
            }
            $this->contagemTotalsScheduledForDeletion[]= clone $contagemTotal;
            $contagemTotal->setContagemFonte(null);
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
        if (null !== $this->aProva) {
            $this->aProva->removeContagemFonte($this);
        }
        $this->fonte_id = null;
        $this->evento_id = null;
        $this->prova_id = null;
        $this->nome = null;
        $this->tipo = null;
        $this->qualidade_tempo = null;
        $this->ativa = null;
        $this->ordem = null;
        $this->desativada_em_ms = null;
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
            if ($this->collContagemFonteUsuarios) {
                foreach ($this->collContagemFonteUsuarios as $o) {
                    $o->clearAllReferences($deep);
                }
            }
            if ($this->collContagemDispositivos) {
                foreach ($this->collContagemDispositivos as $o) {
                    $o->clearAllReferences($deep);
                }
            }
            if ($this->collContagemPassagems) {
                foreach ($this->collContagemPassagems as $o) {
                    $o->clearAllReferences($deep);
                }
            }
            if ($this->collContagemTotals) {
                foreach ($this->collContagemTotals as $o) {
                    $o->clearAllReferences($deep);
                }
            }
        } // if ($deep)

        $this->collContagemFonteUsuarios = null;
        $this->collContagemDispositivos = null;
        $this->collContagemPassagems = null;
        $this->collContagemTotals = null;
        $this->aProva = null;
        return $this;
    }

    /**
     * Return the string representation of this object
     *
     * @return string
     */
    public function __toString()
    {
        return (string) $this->exportTo(ContagemFonteTableMap::DEFAULT_STRING_FORMAT);
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
