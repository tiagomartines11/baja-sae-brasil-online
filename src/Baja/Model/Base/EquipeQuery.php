<?php

namespace Baja\Model\Base;

use \Exception;
use \PDO;
use Baja\Model\Equipe as ChildEquipe;
use Baja\Model\EquipeQuery as ChildEquipeQuery;
use Baja\Model\Map\EquipeTableMap;
use Propel\Runtime\Propel;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\ModelCriteria;
use Propel\Runtime\ActiveQuery\ModelJoin;
use Propel\Runtime\Collection\Collection;
use Propel\Runtime\Collection\ObjectCollection;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Exception\PropelException;

/**
 * Base class that represents a query for the `equipe` table.
 *
 * @method     ChildEquipeQuery orderByEventoId($order = Criteria::ASC) Order by the evento_id column
 * @method     ChildEquipeQuery orderByEquipeId($order = Criteria::ASC) Order by the equipe_id column
 * @method     ChildEquipeQuery orderByEscola($order = Criteria::ASC) Order by the escola column
 * @method     ChildEquipeQuery orderByEscolaCurto($order = Criteria::ASC) Order by the escola_curto column
 * @method     ChildEquipeQuery orderByCidade($order = Criteria::ASC) Order by the cidade column
 * @method     ChildEquipeQuery orderByEquipe($order = Criteria::ASC) Order by the equipe column
 * @method     ChildEquipeQuery orderByEquipeCurto($order = Criteria::ASC) Order by the equipe_curto column
 * @method     ChildEquipeQuery orderByEstado($order = Criteria::ASC) Order by the estado column
 * @method     ChildEquipeQuery orderByPresente($order = Criteria::ASC) Order by the presente column
 * @method     ChildEquipeQuery orderByDesclassificado($order = Criteria::ASC) Order by the desclassificado column
 *
 * @method     ChildEquipeQuery groupByEventoId() Group by the evento_id column
 * @method     ChildEquipeQuery groupByEquipeId() Group by the equipe_id column
 * @method     ChildEquipeQuery groupByEscola() Group by the escola column
 * @method     ChildEquipeQuery groupByEscolaCurto() Group by the escola_curto column
 * @method     ChildEquipeQuery groupByCidade() Group by the cidade column
 * @method     ChildEquipeQuery groupByEquipe() Group by the equipe column
 * @method     ChildEquipeQuery groupByEquipeCurto() Group by the equipe_curto column
 * @method     ChildEquipeQuery groupByEstado() Group by the estado column
 * @method     ChildEquipeQuery groupByPresente() Group by the presente column
 * @method     ChildEquipeQuery groupByDesclassificado() Group by the desclassificado column
 *
 * @method     ChildEquipeQuery leftJoin($relation) Adds a LEFT JOIN clause to the query
 * @method     ChildEquipeQuery rightJoin($relation) Adds a RIGHT JOIN clause to the query
 * @method     ChildEquipeQuery innerJoin($relation) Adds a INNER JOIN clause to the query
 *
 * @method     ChildEquipeQuery leftJoinWith($relation) Adds a LEFT JOIN clause and with to the query
 * @method     ChildEquipeQuery rightJoinWith($relation) Adds a RIGHT JOIN clause and with to the query
 * @method     ChildEquipeQuery innerJoinWith($relation) Adds a INNER JOIN clause and with to the query
 *
 * @method     ChildEquipeQuery leftJoinEvento($relationAlias = null) Adds a LEFT JOIN clause to the query using the Evento relation
 * @method     ChildEquipeQuery rightJoinEvento($relationAlias = null) Adds a RIGHT JOIN clause to the query using the Evento relation
 * @method     ChildEquipeQuery innerJoinEvento($relationAlias = null) Adds a INNER JOIN clause to the query using the Evento relation
 *
 * @method     ChildEquipeQuery joinWithEvento($joinType = Criteria::INNER_JOIN) Adds a join clause and with to the query using the Evento relation
 *
 * @method     ChildEquipeQuery leftJoinWithEvento() Adds a LEFT JOIN clause and with to the query using the Evento relation
 * @method     ChildEquipeQuery rightJoinWithEvento() Adds a RIGHT JOIN clause and with to the query using the Evento relation
 * @method     ChildEquipeQuery innerJoinWithEvento() Adds a INNER JOIN clause and with to the query using the Evento relation
 *
 * @method     ChildEquipeQuery leftJoinInput($relationAlias = null) Adds a LEFT JOIN clause to the query using the Input relation
 * @method     ChildEquipeQuery rightJoinInput($relationAlias = null) Adds a RIGHT JOIN clause to the query using the Input relation
 * @method     ChildEquipeQuery innerJoinInput($relationAlias = null) Adds a INNER JOIN clause to the query using the Input relation
 *
 * @method     ChildEquipeQuery joinWithInput($joinType = Criteria::INNER_JOIN) Adds a join clause and with to the query using the Input relation
 *
 * @method     ChildEquipeQuery leftJoinWithInput() Adds a LEFT JOIN clause and with to the query using the Input relation
 * @method     ChildEquipeQuery rightJoinWithInput() Adds a RIGHT JOIN clause and with to the query using the Input relation
 * @method     ChildEquipeQuery innerJoinWithInput() Adds a INNER JOIN clause and with to the query using the Input relation
 *
 * @method     ChildEquipeQuery leftJoinTournament($relationAlias = null) Adds a LEFT JOIN clause to the query using the Tournament relation
 * @method     ChildEquipeQuery rightJoinTournament($relationAlias = null) Adds a RIGHT JOIN clause to the query using the Tournament relation
 * @method     ChildEquipeQuery innerJoinTournament($relationAlias = null) Adds a INNER JOIN clause to the query using the Tournament relation
 *
 * @method     ChildEquipeQuery joinWithTournament($joinType = Criteria::INNER_JOIN) Adds a join clause and with to the query using the Tournament relation
 *
 * @method     ChildEquipeQuery leftJoinWithTournament() Adds a LEFT JOIN clause and with to the query using the Tournament relation
 * @method     ChildEquipeQuery rightJoinWithTournament() Adds a RIGHT JOIN clause and with to the query using the Tournament relation
 * @method     ChildEquipeQuery innerJoinWithTournament() Adds a INNER JOIN clause and with to the query using the Tournament relation
 *
 * @method     ChildEquipeQuery leftJoinSenha($relationAlias = null) Adds a LEFT JOIN clause to the query using the Senha relation
 * @method     ChildEquipeQuery rightJoinSenha($relationAlias = null) Adds a RIGHT JOIN clause to the query using the Senha relation
 * @method     ChildEquipeQuery innerJoinSenha($relationAlias = null) Adds a INNER JOIN clause to the query using the Senha relation
 *
 * @method     ChildEquipeQuery joinWithSenha($joinType = Criteria::INNER_JOIN) Adds a join clause and with to the query using the Senha relation
 *
 * @method     ChildEquipeQuery leftJoinWithSenha() Adds a LEFT JOIN clause and with to the query using the Senha relation
 * @method     ChildEquipeQuery rightJoinWithSenha() Adds a RIGHT JOIN clause and with to the query using the Senha relation
 * @method     ChildEquipeQuery innerJoinWithSenha() Adds a INNER JOIN clause and with to the query using the Senha relation
 *
 * @method     \Baja\Model\EventoQuery|\Baja\Model\InputQuery|\Baja\Model\TournamentQuery|\Baja\Model\SenhaQuery endUse() Finalizes a secondary criteria and merges it with its primary Criteria
 *
 * @method     ChildEquipe|null findOne(?ConnectionInterface $con = null) Return the first ChildEquipe matching the query
 * @method     ChildEquipe findOneOrCreate(?ConnectionInterface $con = null) Return the first ChildEquipe matching the query, or a new ChildEquipe object populated from the query conditions when no match is found
 *
 * @method     ChildEquipe|null findOneByEventoId(string $evento_id) Return the first ChildEquipe filtered by the evento_id column
 * @method     ChildEquipe|null findOneByEquipeId(int $equipe_id) Return the first ChildEquipe filtered by the equipe_id column
 * @method     ChildEquipe|null findOneByEscola(string $escola) Return the first ChildEquipe filtered by the escola column
 * @method     ChildEquipe|null findOneByEscolaCurto(string $escola_curto) Return the first ChildEquipe filtered by the escola_curto column
 * @method     ChildEquipe|null findOneByCidade(string $cidade) Return the first ChildEquipe filtered by the cidade column
 * @method     ChildEquipe|null findOneByEquipe(string $equipe) Return the first ChildEquipe filtered by the equipe column
 * @method     ChildEquipe|null findOneByEquipeCurto(string $equipe_curto) Return the first ChildEquipe filtered by the equipe_curto column
 * @method     ChildEquipe|null findOneByEstado(string $estado) Return the first ChildEquipe filtered by the estado column
 * @method     ChildEquipe|null findOneByPresente(boolean $presente) Return the first ChildEquipe filtered by the presente column
 * @method     ChildEquipe|null findOneByDesclassificado(boolean $desclassificado) Return the first ChildEquipe filtered by the desclassificado column
 *
 * @method     ChildEquipe requirePk($key, ?ConnectionInterface $con = null) Return the ChildEquipe by primary key and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildEquipe requireOne(?ConnectionInterface $con = null) Return the first ChildEquipe matching the query and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 *
 * @method     ChildEquipe requireOneByEventoId(string $evento_id) Return the first ChildEquipe filtered by the evento_id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildEquipe requireOneByEquipeId(int $equipe_id) Return the first ChildEquipe filtered by the equipe_id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildEquipe requireOneByEscola(string $escola) Return the first ChildEquipe filtered by the escola column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildEquipe requireOneByEscolaCurto(string $escola_curto) Return the first ChildEquipe filtered by the escola_curto column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildEquipe requireOneByCidade(string $cidade) Return the first ChildEquipe filtered by the cidade column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildEquipe requireOneByEquipe(string $equipe) Return the first ChildEquipe filtered by the equipe column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildEquipe requireOneByEquipeCurto(string $equipe_curto) Return the first ChildEquipe filtered by the equipe_curto column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildEquipe requireOneByEstado(string $estado) Return the first ChildEquipe filtered by the estado column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildEquipe requireOneByPresente(boolean $presente) Return the first ChildEquipe filtered by the presente column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildEquipe requireOneByDesclassificado(boolean $desclassificado) Return the first ChildEquipe filtered by the desclassificado column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 *
 * @method     ChildEquipe[]|Collection find(?ConnectionInterface $con = null) Return ChildEquipe objects based on current ModelCriteria
 * @psalm-method Collection&\Traversable<ChildEquipe> find(?ConnectionInterface $con = null) Return ChildEquipe objects based on current ModelCriteria
 *
 * @method     ChildEquipe[]|Collection findByEventoId(string|array<string> $evento_id) Return ChildEquipe objects filtered by the evento_id column
 * @psalm-method Collection&\Traversable<ChildEquipe> findByEventoId(string|array<string> $evento_id) Return ChildEquipe objects filtered by the evento_id column
 * @method     ChildEquipe[]|Collection findByEquipeId(int|array<int> $equipe_id) Return ChildEquipe objects filtered by the equipe_id column
 * @psalm-method Collection&\Traversable<ChildEquipe> findByEquipeId(int|array<int> $equipe_id) Return ChildEquipe objects filtered by the equipe_id column
 * @method     ChildEquipe[]|Collection findByEscola(string|array<string> $escola) Return ChildEquipe objects filtered by the escola column
 * @psalm-method Collection&\Traversable<ChildEquipe> findByEscola(string|array<string> $escola) Return ChildEquipe objects filtered by the escola column
 * @method     ChildEquipe[]|Collection findByEscolaCurto(string|array<string> $escola_curto) Return ChildEquipe objects filtered by the escola_curto column
 * @psalm-method Collection&\Traversable<ChildEquipe> findByEscolaCurto(string|array<string> $escola_curto) Return ChildEquipe objects filtered by the escola_curto column
 * @method     ChildEquipe[]|Collection findByCidade(string|array<string> $cidade) Return ChildEquipe objects filtered by the cidade column
 * @psalm-method Collection&\Traversable<ChildEquipe> findByCidade(string|array<string> $cidade) Return ChildEquipe objects filtered by the cidade column
 * @method     ChildEquipe[]|Collection findByEquipe(string|array<string> $equipe) Return ChildEquipe objects filtered by the equipe column
 * @psalm-method Collection&\Traversable<ChildEquipe> findByEquipe(string|array<string> $equipe) Return ChildEquipe objects filtered by the equipe column
 * @method     ChildEquipe[]|Collection findByEquipeCurto(string|array<string> $equipe_curto) Return ChildEquipe objects filtered by the equipe_curto column
 * @psalm-method Collection&\Traversable<ChildEquipe> findByEquipeCurto(string|array<string> $equipe_curto) Return ChildEquipe objects filtered by the equipe_curto column
 * @method     ChildEquipe[]|Collection findByEstado(string|array<string> $estado) Return ChildEquipe objects filtered by the estado column
 * @psalm-method Collection&\Traversable<ChildEquipe> findByEstado(string|array<string> $estado) Return ChildEquipe objects filtered by the estado column
 * @method     ChildEquipe[]|Collection findByPresente(boolean|array<boolean> $presente) Return ChildEquipe objects filtered by the presente column
 * @psalm-method Collection&\Traversable<ChildEquipe> findByPresente(boolean|array<boolean> $presente) Return ChildEquipe objects filtered by the presente column
 * @method     ChildEquipe[]|Collection findByDesclassificado(boolean|array<boolean> $desclassificado) Return ChildEquipe objects filtered by the desclassificado column
 * @psalm-method Collection&\Traversable<ChildEquipe> findByDesclassificado(boolean|array<boolean> $desclassificado) Return ChildEquipe objects filtered by the desclassificado column
 *
 * @method     ChildEquipe[]|\Propel\Runtime\Util\PropelModelPager paginate($page = 1, $maxPerPage = 10, ?ConnectionInterface $con = null) Issue a SELECT query based on the current ModelCriteria and uses a page and a maximum number of results per page to compute an offset and a limit
 * @psalm-method \Propel\Runtime\Util\PropelModelPager&\Traversable<ChildEquipe> paginate($page = 1, $maxPerPage = 10, ?ConnectionInterface $con = null) Issue a SELECT query based on the current ModelCriteria and uses a page and a maximum number of results per page to compute an offset and a limit
 */
abstract class EquipeQuery extends ModelCriteria
{
    protected $entityNotFoundExceptionClass = '\\Propel\\Runtime\\Exception\\EntityNotFoundException';

    /**
     * Initializes internal state of \Baja\Model\Base\EquipeQuery object.
     *
     * @param string $dbName The database name
     * @param string $modelName The phpName of a model, e.g. 'Book'
     * @param string $modelAlias The alias for the model in this query, e.g. 'b'
     */
    public function __construct($dbName = 'resultados', $modelName = '\\Baja\\Model\\Equipe', $modelAlias = null)
    {
        parent::__construct($dbName, $modelName, $modelAlias);
    }

    /**
     * Returns a new ChildEquipeQuery object.
     *
     * @param string $modelAlias The alias of a model in the query
     * @param Criteria $criteria Optional Criteria to build the query from
     *
     * @return ChildEquipeQuery
     */
    public static function create(?string $modelAlias = null, ?Criteria $criteria = null): Criteria
    {
        if ($criteria instanceof ChildEquipeQuery) {
            return $criteria;
        }
        $query = new ChildEquipeQuery();
        if (null !== $modelAlias) {
            $query->setModelAlias($modelAlias);
        }
        if ($criteria instanceof Criteria) {
            $query->mergeWith($criteria);
        }

        return $query;
    }

    /**
     * Find object by primary key.
     * Propel uses the instance pool to skip the database if the object exists.
     * Go fast if the query is untouched.
     *
     * <code>
     * $obj = $c->findPk(array(12, 34), $con);
     * </code>
     *
     * @param array[$evento_id, $equipe_id] $key Primary key to use for the query
     * @param ConnectionInterface $con an optional connection object
     *
     * @return ChildEquipe|array|mixed the result, formatted by the current formatter
     */
    public function findPk($key, ?ConnectionInterface $con = null)
    {
        if ($key === null) {
            return null;
        }

        if ($con === null) {
            $con = Propel::getServiceContainer()->getReadConnection(EquipeTableMap::DATABASE_NAME);
        }

        $this->basePreSelect($con);

        if (
            $this->formatter || $this->modelAlias || $this->with || $this->select
            || $this->selectColumns || $this->asColumns || $this->selectModifiers
            || $this->map || $this->having || $this->joins
        ) {
            return $this->findPkComplex($key, $con);
        }

        if ((null !== ($obj = EquipeTableMap::getInstanceFromPool(serialize([(null === $key[0] || is_scalar($key[0]) || is_callable([$key[0], '__toString']) ? (string) $key[0] : $key[0]), (null === $key[1] || is_scalar($key[1]) || is_callable([$key[1], '__toString']) ? (string) $key[1] : $key[1])]))))) {
            // the object is already in the instance pool
            return $obj;
        }

        return $this->findPkSimple($key, $con);
    }

    /**
     * Find object by primary key using raw SQL to go fast.
     * Bypass doSelect() and the object formatter by using generated code.
     *
     * @param mixed $key Primary key to use for the query
     * @param ConnectionInterface $con A connection object
     *
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return ChildEquipe A model object, or null if the key is not found
     */
    protected function findPkSimple($key, ConnectionInterface $con)
    {
        $sql = 'SELECT evento_id, equipe_id, escola, escola_curto, cidade, equipe, equipe_curto, estado, presente, desclassificado FROM equipe WHERE evento_id = :p0 AND equipe_id = :p1';
        try {
            $stmt = $con->prepare($sql);
            $stmt->bindValue(':p0', $key[0], PDO::PARAM_STR);
            $stmt->bindValue(':p1', $key[1], PDO::PARAM_INT);
            $stmt->execute();
        } catch (Exception $e) {
            Propel::log($e->getMessage(), Propel::LOG_ERR);
            throw new PropelException(sprintf('Unable to execute SELECT statement [%s]', $sql), 0, $e);
        }
        $obj = null;
        if ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
            /** @var ChildEquipe $obj */
            $obj = new ChildEquipe();
            $obj->hydrate($row);
            EquipeTableMap::addInstanceToPool($obj, serialize([(null === $key[0] || is_scalar($key[0]) || is_callable([$key[0], '__toString']) ? (string) $key[0] : $key[0]), (null === $key[1] || is_scalar($key[1]) || is_callable([$key[1], '__toString']) ? (string) $key[1] : $key[1])]));
        }
        $stmt->closeCursor();

        return $obj;
    }

    /**
     * Find object by primary key.
     *
     * @param mixed $key Primary key to use for the query
     * @param ConnectionInterface $con A connection object
     *
     * @return ChildEquipe|array|mixed the result, formatted by the current formatter
     */
    protected function findPkComplex($key, ConnectionInterface $con)
    {
        // As the query uses a PK condition, no limit(1) is necessary.
        $criteria = $this->isKeepQuery() ? clone $this : $this;
        $dataFetcher = $criteria
            ->filterByPrimaryKey($key)
            ->doSelect($con);

        return $criteria->getFormatter()->init($criteria)->formatOne($dataFetcher);
    }

    /**
     * Find objects by primary key
     * <code>
     * $objs = $c->findPks(array(array(12, 56), array(832, 123), array(123, 456)), $con);
     * </code>
     * @param array $keys Primary keys to use for the query
     * @param ConnectionInterface $con an optional connection object
     *
     * @return Collection|array|mixed the list of results, formatted by the current formatter
     */
    public function findPks($keys, ?ConnectionInterface $con = null)
    {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getReadConnection($this->getDbName());
        }
        $this->basePreSelect($con);
        $criteria = $this->isKeepQuery() ? clone $this : $this;
        $dataFetcher = $criteria
            ->filterByPrimaryKeys($keys)
            ->doSelect($con);

        return $criteria->getFormatter()->init($criteria)->format($dataFetcher);
    }

    /**
     * Filter the query by primary key
     *
     * @param mixed $key Primary key to use for the query
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByPrimaryKey($key)
    {
        $this->addUsingAlias(EquipeTableMap::COL_EVENTO_ID, $key[0], Criteria::EQUAL);
        $this->addUsingAlias(EquipeTableMap::COL_EQUIPE_ID, $key[1], Criteria::EQUAL);

        return $this;
    }

    /**
     * Filter the query by a list of primary keys
     *
     * @param array|int $keys The list of primary key to use for the query
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByPrimaryKeys($keys)
    {
        if (empty($keys)) {
            $this->add(null, '1<>1', Criteria::CUSTOM);

            return $this;
        }
        foreach ($keys as $key) {
            $cton0 = $this->getNewCriterion(EquipeTableMap::COL_EVENTO_ID, $key[0], Criteria::EQUAL);
            $cton1 = $this->getNewCriterion(EquipeTableMap::COL_EQUIPE_ID, $key[1], Criteria::EQUAL);
            $cton0->addAnd($cton1);
            $this->addOr($cton0);
        }

        return $this;
    }

    /**
     * Filter the query on the evento_id column
     *
     * Example usage:
     * <code>
     * $query->filterByEventoId('fooValue');   // WHERE evento_id = 'fooValue'
     * $query->filterByEventoId('%fooValue%', Criteria::LIKE); // WHERE evento_id LIKE '%fooValue%'
     * $query->filterByEventoId(['foo', 'bar']); // WHERE evento_id IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $eventoId The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByEventoId($eventoId = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($eventoId)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(EquipeTableMap::COL_EVENTO_ID, $eventoId, $comparison);

        return $this;
    }

    /**
     * Filter the query on the equipe_id column
     *
     * Example usage:
     * <code>
     * $query->filterByEquipeId(1234); // WHERE equipe_id = 1234
     * $query->filterByEquipeId(array(12, 34)); // WHERE equipe_id IN (12, 34)
     * $query->filterByEquipeId(array('min' => 12)); // WHERE equipe_id > 12
     * </code>
     *
     * @param mixed $equipeId The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByEquipeId($equipeId = null, ?string $comparison = null)
    {
        if (is_array($equipeId)) {
            $useMinMax = false;
            if (isset($equipeId['min'])) {
                $this->addUsingAlias(EquipeTableMap::COL_EQUIPE_ID, $equipeId['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($equipeId['max'])) {
                $this->addUsingAlias(EquipeTableMap::COL_EQUIPE_ID, $equipeId['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(EquipeTableMap::COL_EQUIPE_ID, $equipeId, $comparison);

        return $this;
    }

    /**
     * Filter the query on the escola column
     *
     * Example usage:
     * <code>
     * $query->filterByEscola('fooValue');   // WHERE escola = 'fooValue'
     * $query->filterByEscola('%fooValue%', Criteria::LIKE); // WHERE escola LIKE '%fooValue%'
     * $query->filterByEscola(['foo', 'bar']); // WHERE escola IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $escola The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByEscola($escola = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($escola)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(EquipeTableMap::COL_ESCOLA, $escola, $comparison);

        return $this;
    }

    /**
     * Filter the query on the escola_curto column
     *
     * Example usage:
     * <code>
     * $query->filterByEscolaCurto('fooValue');   // WHERE escola_curto = 'fooValue'
     * $query->filterByEscolaCurto('%fooValue%', Criteria::LIKE); // WHERE escola_curto LIKE '%fooValue%'
     * $query->filterByEscolaCurto(['foo', 'bar']); // WHERE escola_curto IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $escolaCurto The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByEscolaCurto($escolaCurto = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($escolaCurto)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(EquipeTableMap::COL_ESCOLA_CURTO, $escolaCurto, $comparison);

        return $this;
    }

    /**
     * Filter the query on the cidade column
     *
     * Example usage:
     * <code>
     * $query->filterByCidade('fooValue');   // WHERE cidade = 'fooValue'
     * $query->filterByCidade('%fooValue%', Criteria::LIKE); // WHERE cidade LIKE '%fooValue%'
     * $query->filterByCidade(['foo', 'bar']); // WHERE cidade IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $cidade The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByCidade($cidade = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($cidade)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(EquipeTableMap::COL_CIDADE, $cidade, $comparison);

        return $this;
    }

    /**
     * Filter the query on the equipe column
     *
     * Example usage:
     * <code>
     * $query->filterByEquipe('fooValue');   // WHERE equipe = 'fooValue'
     * $query->filterByEquipe('%fooValue%', Criteria::LIKE); // WHERE equipe LIKE '%fooValue%'
     * $query->filterByEquipe(['foo', 'bar']); // WHERE equipe IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $equipe The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByEquipe($equipe = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($equipe)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(EquipeTableMap::COL_EQUIPE, $equipe, $comparison);

        return $this;
    }

    /**
     * Filter the query on the equipe_curto column
     *
     * Example usage:
     * <code>
     * $query->filterByEquipeCurto('fooValue');   // WHERE equipe_curto = 'fooValue'
     * $query->filterByEquipeCurto('%fooValue%', Criteria::LIKE); // WHERE equipe_curto LIKE '%fooValue%'
     * $query->filterByEquipeCurto(['foo', 'bar']); // WHERE equipe_curto IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $equipeCurto The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByEquipeCurto($equipeCurto = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($equipeCurto)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(EquipeTableMap::COL_EQUIPE_CURTO, $equipeCurto, $comparison);

        return $this;
    }

    /**
     * Filter the query on the estado column
     *
     * Example usage:
     * <code>
     * $query->filterByEstado('fooValue');   // WHERE estado = 'fooValue'
     * $query->filterByEstado('%fooValue%', Criteria::LIKE); // WHERE estado LIKE '%fooValue%'
     * $query->filterByEstado(['foo', 'bar']); // WHERE estado IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $estado The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByEstado($estado = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($estado)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(EquipeTableMap::COL_ESTADO, $estado, $comparison);

        return $this;
    }

    /**
     * Filter the query on the presente column
     *
     * Example usage:
     * <code>
     * $query->filterByPresente(true); // WHERE presente = true
     * $query->filterByPresente('yes'); // WHERE presente = true
     * </code>
     *
     * @param bool|string $presente The value to use as filter.
     *              Non-boolean arguments are converted using the following rules:
     *                * 1, '1', 'true',  'on',  and 'yes' are converted to boolean true
     *                * 0, '0', 'false', 'off', and 'no'  are converted to boolean false
     *              Check on string values is case insensitive (so 'FaLsE' is seen as 'false').
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByPresente($presente = null, ?string $comparison = null)
    {
        if (is_string($presente)) {
            $presente = in_array(strtolower($presente), array('false', 'off', '-', 'no', 'n', '0', ''), true) ? false : true;
        }

        $this->addUsingAlias(EquipeTableMap::COL_PRESENTE, $presente, $comparison);

        return $this;
    }

    /**
     * Filter the query on the desclassificado column
     *
     * Example usage:
     * <code>
     * $query->filterByDesclassificado(true); // WHERE desclassificado = true
     * $query->filterByDesclassificado('yes'); // WHERE desclassificado = true
     * </code>
     *
     * @param bool|string $desclassificado The value to use as filter.
     *              Non-boolean arguments are converted using the following rules:
     *                * 1, '1', 'true',  'on',  and 'yes' are converted to boolean true
     *                * 0, '0', 'false', 'off', and 'no'  are converted to boolean false
     *              Check on string values is case insensitive (so 'FaLsE' is seen as 'false').
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByDesclassificado($desclassificado = null, ?string $comparison = null)
    {
        if (is_string($desclassificado)) {
            $desclassificado = in_array(strtolower($desclassificado), array('false', 'off', '-', 'no', 'n', '0', ''), true) ? false : true;
        }

        $this->addUsingAlias(EquipeTableMap::COL_DESCLASSIFICADO, $desclassificado, $comparison);

        return $this;
    }

    /**
     * Filter the query by a related \Baja\Model\Evento object
     *
     * @param \Baja\Model\Evento|ObjectCollection $evento The related object(s) to use as filter
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByEvento($evento, ?string $comparison = null)
    {
        if ($evento instanceof \Baja\Model\Evento) {
            return $this
                ->addUsingAlias(EquipeTableMap::COL_EVENTO_ID, $evento->getEventoId(), $comparison);
        } elseif ($evento instanceof ObjectCollection) {
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }

            $this
                ->addUsingAlias(EquipeTableMap::COL_EVENTO_ID, $evento->toKeyValue('PrimaryKey', 'EventoId'), $comparison);

            return $this;
        } else {
            throw new PropelException('filterByEvento() only accepts arguments of type \Baja\Model\Evento or Collection');
        }
    }

    /**
     * Adds a JOIN clause to the query using the Evento relation
     *
     * @param string|null $relationAlias Optional alias for the relation
     * @param string|null $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return $this The current query, for fluid interface
     */
    public function joinEvento(?string $relationAlias = null, ?string $joinType = Criteria::INNER_JOIN)
    {
        $tableMap = $this->getTableMap();
        $relationMap = $tableMap->getRelation('Evento');

        // create a ModelJoin object for this join
        $join = new ModelJoin();
        $join->setJoinType($joinType);
        $join->setRelationMap($relationMap, $this->useAliasInSQL ? $this->getModelAlias() : null, $relationAlias);
        if ($previousJoin = $this->getPreviousJoin()) {
            $join->setPreviousJoin($previousJoin);
        }

        // add the ModelJoin to the current object
        if ($relationAlias) {
            $this->addAlias($relationAlias, $relationMap->getRightTable()->getName());
            $this->addJoinObject($join, $relationAlias);
        } else {
            $this->addJoinObject($join, 'Evento');
        }

        return $this;
    }

    /**
     * Use the Evento relation Evento object
     *
     * @see useQuery()
     *
     * @param string $relationAlias optional alias for the relation,
     *                                   to be used as main alias in the secondary query
     * @param string $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return \Baja\Model\EventoQuery A secondary query class using the current class as primary query
     */
    public function useEventoQuery($relationAlias = null, $joinType = Criteria::INNER_JOIN)
    {
        return $this
            ->joinEvento($relationAlias, $joinType)
            ->useQuery($relationAlias ? $relationAlias : 'Evento', '\Baja\Model\EventoQuery');
    }

    /**
     * Use the Evento relation Evento object
     *
     * @param callable(\Baja\Model\EventoQuery):\Baja\Model\EventoQuery $callable A function working on the related query
     *
     * @param string|null $relationAlias optional alias for the relation
     *
     * @param string|null $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return $this
     */
    public function withEventoQuery(
        callable $callable,
        string $relationAlias = null,
        ?string $joinType = Criteria::INNER_JOIN
    ) {
        $relatedQuery = $this->useEventoQuery(
            $relationAlias,
            $joinType
        );
        $callable($relatedQuery);
        $relatedQuery->endUse();

        return $this;
    }

    /**
     * Use the relation to Evento table for an EXISTS query.
     *
     * @see \Propel\Runtime\ActiveQuery\ModelCriteria::useExistsQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the exists query, like ExtendedBookQuery::class
     * @param string $typeOfExists Either ExistsQueryCriterion::TYPE_EXISTS or ExistsQueryCriterion::TYPE_NOT_EXISTS
     *
     * @return \Baja\Model\EventoQuery The inner query object of the EXISTS statement
     */
    public function useEventoExistsQuery($modelAlias = null, $queryClass = null, $typeOfExists = 'EXISTS')
    {
        /** @var $q \Baja\Model\EventoQuery */
        $q = $this->useExistsQuery('Evento', $modelAlias, $queryClass, $typeOfExists);
        return $q;
    }

    /**
     * Use the relation to Evento table for a NOT EXISTS query.
     *
     * @see useEventoExistsQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the exists query, like ExtendedBookQuery::class
     *
     * @return \Baja\Model\EventoQuery The inner query object of the NOT EXISTS statement
     */
    public function useEventoNotExistsQuery($modelAlias = null, $queryClass = null)
    {
        /** @var $q \Baja\Model\EventoQuery */
        $q = $this->useExistsQuery('Evento', $modelAlias, $queryClass, 'NOT EXISTS');
        return $q;
    }

    /**
     * Use the relation to Evento table for an IN query.
     *
     * @see \Propel\Runtime\ActiveQuery\ModelCriteria::useInQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the IN query, like ExtendedBookQuery::class
     * @param string $typeOfIn Criteria::IN or Criteria::NOT_IN
     *
     * @return \Baja\Model\EventoQuery The inner query object of the IN statement
     */
    public function useInEventoQuery($modelAlias = null, $queryClass = null, $typeOfIn = 'IN')
    {
        /** @var $q \Baja\Model\EventoQuery */
        $q = $this->useInQuery('Evento', $modelAlias, $queryClass, $typeOfIn);
        return $q;
    }

    /**
     * Use the relation to Evento table for a NOT IN query.
     *
     * @see useEventoInQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the NOT IN query, like ExtendedBookQuery::class
     *
     * @return \Baja\Model\EventoQuery The inner query object of the NOT IN statement
     */
    public function useNotInEventoQuery($modelAlias = null, $queryClass = null)
    {
        /** @var $q \Baja\Model\EventoQuery */
        $q = $this->useInQuery('Evento', $modelAlias, $queryClass, 'NOT IN');
        return $q;
    }

    /**
     * Filter the query by a related \Baja\Model\Input object
     *
     * @param \Baja\Model\Input|ObjectCollection $input the related object to use as filter
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByInput($input, ?string $comparison = null)
    {
        if ($input instanceof \Baja\Model\Input) {
            $this
                ->addUsingAlias(EquipeTableMap::COL_EVENTO_ID, $input->getEventoId(), $comparison)
                ->addUsingAlias(EquipeTableMap::COL_EQUIPE_ID, $input->getEquipeId(), $comparison);

            return $this;
        } else {
            throw new PropelException('filterByInput() only accepts arguments of type \Baja\Model\Input');
        }
    }

    /**
     * Adds a JOIN clause to the query using the Input relation
     *
     * @param string|null $relationAlias Optional alias for the relation
     * @param string|null $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return $this The current query, for fluid interface
     */
    public function joinInput(?string $relationAlias = null, ?string $joinType = Criteria::INNER_JOIN)
    {
        $tableMap = $this->getTableMap();
        $relationMap = $tableMap->getRelation('Input');

        // create a ModelJoin object for this join
        $join = new ModelJoin();
        $join->setJoinType($joinType);
        $join->setRelationMap($relationMap, $this->useAliasInSQL ? $this->getModelAlias() : null, $relationAlias);
        if ($previousJoin = $this->getPreviousJoin()) {
            $join->setPreviousJoin($previousJoin);
        }

        // add the ModelJoin to the current object
        if ($relationAlias) {
            $this->addAlias($relationAlias, $relationMap->getRightTable()->getName());
            $this->addJoinObject($join, $relationAlias);
        } else {
            $this->addJoinObject($join, 'Input');
        }

        return $this;
    }

    /**
     * Use the Input relation Input object
     *
     * @see useQuery()
     *
     * @param string $relationAlias optional alias for the relation,
     *                                   to be used as main alias in the secondary query
     * @param string $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return \Baja\Model\InputQuery A secondary query class using the current class as primary query
     */
    public function useInputQuery($relationAlias = null, $joinType = Criteria::INNER_JOIN)
    {
        return $this
            ->joinInput($relationAlias, $joinType)
            ->useQuery($relationAlias ? $relationAlias : 'Input', '\Baja\Model\InputQuery');
    }

    /**
     * Use the Input relation Input object
     *
     * @param callable(\Baja\Model\InputQuery):\Baja\Model\InputQuery $callable A function working on the related query
     *
     * @param string|null $relationAlias optional alias for the relation
     *
     * @param string|null $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return $this
     */
    public function withInputQuery(
        callable $callable,
        string $relationAlias = null,
        ?string $joinType = Criteria::INNER_JOIN
    ) {
        $relatedQuery = $this->useInputQuery(
            $relationAlias,
            $joinType
        );
        $callable($relatedQuery);
        $relatedQuery->endUse();

        return $this;
    }

    /**
     * Use the relation to Input table for an EXISTS query.
     *
     * @see \Propel\Runtime\ActiveQuery\ModelCriteria::useExistsQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the exists query, like ExtendedBookQuery::class
     * @param string $typeOfExists Either ExistsQueryCriterion::TYPE_EXISTS or ExistsQueryCriterion::TYPE_NOT_EXISTS
     *
     * @return \Baja\Model\InputQuery The inner query object of the EXISTS statement
     */
    public function useInputExistsQuery($modelAlias = null, $queryClass = null, $typeOfExists = 'EXISTS')
    {
        /** @var $q \Baja\Model\InputQuery */
        $q = $this->useExistsQuery('Input', $modelAlias, $queryClass, $typeOfExists);
        return $q;
    }

    /**
     * Use the relation to Input table for a NOT EXISTS query.
     *
     * @see useInputExistsQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the exists query, like ExtendedBookQuery::class
     *
     * @return \Baja\Model\InputQuery The inner query object of the NOT EXISTS statement
     */
    public function useInputNotExistsQuery($modelAlias = null, $queryClass = null)
    {
        /** @var $q \Baja\Model\InputQuery */
        $q = $this->useExistsQuery('Input', $modelAlias, $queryClass, 'NOT EXISTS');
        return $q;
    }

    /**
     * Use the relation to Input table for an IN query.
     *
     * @see \Propel\Runtime\ActiveQuery\ModelCriteria::useInQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the IN query, like ExtendedBookQuery::class
     * @param string $typeOfIn Criteria::IN or Criteria::NOT_IN
     *
     * @return \Baja\Model\InputQuery The inner query object of the IN statement
     */
    public function useInInputQuery($modelAlias = null, $queryClass = null, $typeOfIn = 'IN')
    {
        /** @var $q \Baja\Model\InputQuery */
        $q = $this->useInQuery('Input', $modelAlias, $queryClass, $typeOfIn);
        return $q;
    }

    /**
     * Use the relation to Input table for a NOT IN query.
     *
     * @see useInputInQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the NOT IN query, like ExtendedBookQuery::class
     *
     * @return \Baja\Model\InputQuery The inner query object of the NOT IN statement
     */
    public function useNotInInputQuery($modelAlias = null, $queryClass = null)
    {
        /** @var $q \Baja\Model\InputQuery */
        $q = $this->useInQuery('Input', $modelAlias, $queryClass, 'NOT IN');
        return $q;
    }

    /**
     * Filter the query by a related \Baja\Model\Tournament object
     *
     * @param \Baja\Model\Tournament|ObjectCollection $tournament the related object to use as filter
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByTournament($tournament, ?string $comparison = null)
    {
        if ($tournament instanceof \Baja\Model\Tournament) {
            $this
                ->addUsingAlias(EquipeTableMap::COL_EVENTO_ID, $tournament->getEventoId(), $comparison)
                ->addUsingAlias(EquipeTableMap::COL_EQUIPE_ID, $tournament->getWinner(), $comparison);

            return $this;
        } else {
            throw new PropelException('filterByTournament() only accepts arguments of type \Baja\Model\Tournament');
        }
    }

    /**
     * Adds a JOIN clause to the query using the Tournament relation
     *
     * @param string|null $relationAlias Optional alias for the relation
     * @param string|null $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return $this The current query, for fluid interface
     */
    public function joinTournament(?string $relationAlias = null, ?string $joinType = Criteria::LEFT_JOIN)
    {
        $tableMap = $this->getTableMap();
        $relationMap = $tableMap->getRelation('Tournament');

        // create a ModelJoin object for this join
        $join = new ModelJoin();
        $join->setJoinType($joinType);
        $join->setRelationMap($relationMap, $this->useAliasInSQL ? $this->getModelAlias() : null, $relationAlias);
        if ($previousJoin = $this->getPreviousJoin()) {
            $join->setPreviousJoin($previousJoin);
        }

        // add the ModelJoin to the current object
        if ($relationAlias) {
            $this->addAlias($relationAlias, $relationMap->getRightTable()->getName());
            $this->addJoinObject($join, $relationAlias);
        } else {
            $this->addJoinObject($join, 'Tournament');
        }

        return $this;
    }

    /**
     * Use the Tournament relation Tournament object
     *
     * @see useQuery()
     *
     * @param string $relationAlias optional alias for the relation,
     *                                   to be used as main alias in the secondary query
     * @param string $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return \Baja\Model\TournamentQuery A secondary query class using the current class as primary query
     */
    public function useTournamentQuery($relationAlias = null, $joinType = Criteria::LEFT_JOIN)
    {
        return $this
            ->joinTournament($relationAlias, $joinType)
            ->useQuery($relationAlias ? $relationAlias : 'Tournament', '\Baja\Model\TournamentQuery');
    }

    /**
     * Use the Tournament relation Tournament object
     *
     * @param callable(\Baja\Model\TournamentQuery):\Baja\Model\TournamentQuery $callable A function working on the related query
     *
     * @param string|null $relationAlias optional alias for the relation
     *
     * @param string|null $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return $this
     */
    public function withTournamentQuery(
        callable $callable,
        string $relationAlias = null,
        ?string $joinType = Criteria::LEFT_JOIN
    ) {
        $relatedQuery = $this->useTournamentQuery(
            $relationAlias,
            $joinType
        );
        $callable($relatedQuery);
        $relatedQuery->endUse();

        return $this;
    }

    /**
     * Use the relation to Tournament table for an EXISTS query.
     *
     * @see \Propel\Runtime\ActiveQuery\ModelCriteria::useExistsQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the exists query, like ExtendedBookQuery::class
     * @param string $typeOfExists Either ExistsQueryCriterion::TYPE_EXISTS or ExistsQueryCriterion::TYPE_NOT_EXISTS
     *
     * @return \Baja\Model\TournamentQuery The inner query object of the EXISTS statement
     */
    public function useTournamentExistsQuery($modelAlias = null, $queryClass = null, $typeOfExists = 'EXISTS')
    {
        /** @var $q \Baja\Model\TournamentQuery */
        $q = $this->useExistsQuery('Tournament', $modelAlias, $queryClass, $typeOfExists);
        return $q;
    }

    /**
     * Use the relation to Tournament table for a NOT EXISTS query.
     *
     * @see useTournamentExistsQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the exists query, like ExtendedBookQuery::class
     *
     * @return \Baja\Model\TournamentQuery The inner query object of the NOT EXISTS statement
     */
    public function useTournamentNotExistsQuery($modelAlias = null, $queryClass = null)
    {
        /** @var $q \Baja\Model\TournamentQuery */
        $q = $this->useExistsQuery('Tournament', $modelAlias, $queryClass, 'NOT EXISTS');
        return $q;
    }

    /**
     * Use the relation to Tournament table for an IN query.
     *
     * @see \Propel\Runtime\ActiveQuery\ModelCriteria::useInQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the IN query, like ExtendedBookQuery::class
     * @param string $typeOfIn Criteria::IN or Criteria::NOT_IN
     *
     * @return \Baja\Model\TournamentQuery The inner query object of the IN statement
     */
    public function useInTournamentQuery($modelAlias = null, $queryClass = null, $typeOfIn = 'IN')
    {
        /** @var $q \Baja\Model\TournamentQuery */
        $q = $this->useInQuery('Tournament', $modelAlias, $queryClass, $typeOfIn);
        return $q;
    }

    /**
     * Use the relation to Tournament table for a NOT IN query.
     *
     * @see useTournamentInQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the NOT IN query, like ExtendedBookQuery::class
     *
     * @return \Baja\Model\TournamentQuery The inner query object of the NOT IN statement
     */
    public function useNotInTournamentQuery($modelAlias = null, $queryClass = null)
    {
        /** @var $q \Baja\Model\TournamentQuery */
        $q = $this->useInQuery('Tournament', $modelAlias, $queryClass, 'NOT IN');
        return $q;
    }

    /**
     * Filter the query by a related \Baja\Model\Senha object
     *
     * @param \Baja\Model\Senha|ObjectCollection $senha the related object to use as filter
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterBySenha($senha, ?string $comparison = null)
    {
        if ($senha instanceof \Baja\Model\Senha) {
            $this
                ->addUsingAlias(EquipeTableMap::COL_EQUIPE_ID, $senha->getEquipeId(), $comparison)
                ->addUsingAlias(EquipeTableMap::COL_EVENTO_ID, $senha->getEventoId(), $comparison);

            return $this;
        } else {
            throw new PropelException('filterBySenha() only accepts arguments of type \Baja\Model\Senha');
        }
    }

    /**
     * Adds a JOIN clause to the query using the Senha relation
     *
     * @param string|null $relationAlias Optional alias for the relation
     * @param string|null $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return $this The current query, for fluid interface
     */
    public function joinSenha(?string $relationAlias = null, ?string $joinType = Criteria::INNER_JOIN)
    {
        $tableMap = $this->getTableMap();
        $relationMap = $tableMap->getRelation('Senha');

        // create a ModelJoin object for this join
        $join = new ModelJoin();
        $join->setJoinType($joinType);
        $join->setRelationMap($relationMap, $this->useAliasInSQL ? $this->getModelAlias() : null, $relationAlias);
        if ($previousJoin = $this->getPreviousJoin()) {
            $join->setPreviousJoin($previousJoin);
        }

        // add the ModelJoin to the current object
        if ($relationAlias) {
            $this->addAlias($relationAlias, $relationMap->getRightTable()->getName());
            $this->addJoinObject($join, $relationAlias);
        } else {
            $this->addJoinObject($join, 'Senha');
        }

        return $this;
    }

    /**
     * Use the Senha relation Senha object
     *
     * @see useQuery()
     *
     * @param string $relationAlias optional alias for the relation,
     *                                   to be used as main alias in the secondary query
     * @param string $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return \Baja\Model\SenhaQuery A secondary query class using the current class as primary query
     */
    public function useSenhaQuery($relationAlias = null, $joinType = Criteria::INNER_JOIN)
    {
        return $this
            ->joinSenha($relationAlias, $joinType)
            ->useQuery($relationAlias ? $relationAlias : 'Senha', '\Baja\Model\SenhaQuery');
    }

    /**
     * Use the Senha relation Senha object
     *
     * @param callable(\Baja\Model\SenhaQuery):\Baja\Model\SenhaQuery $callable A function working on the related query
     *
     * @param string|null $relationAlias optional alias for the relation
     *
     * @param string|null $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return $this
     */
    public function withSenhaQuery(
        callable $callable,
        string $relationAlias = null,
        ?string $joinType = Criteria::INNER_JOIN
    ) {
        $relatedQuery = $this->useSenhaQuery(
            $relationAlias,
            $joinType
        );
        $callable($relatedQuery);
        $relatedQuery->endUse();

        return $this;
    }

    /**
     * Use the relation to Senha table for an EXISTS query.
     *
     * @see \Propel\Runtime\ActiveQuery\ModelCriteria::useExistsQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the exists query, like ExtendedBookQuery::class
     * @param string $typeOfExists Either ExistsQueryCriterion::TYPE_EXISTS or ExistsQueryCriterion::TYPE_NOT_EXISTS
     *
     * @return \Baja\Model\SenhaQuery The inner query object of the EXISTS statement
     */
    public function useSenhaExistsQuery($modelAlias = null, $queryClass = null, $typeOfExists = 'EXISTS')
    {
        /** @var $q \Baja\Model\SenhaQuery */
        $q = $this->useExistsQuery('Senha', $modelAlias, $queryClass, $typeOfExists);
        return $q;
    }

    /**
     * Use the relation to Senha table for a NOT EXISTS query.
     *
     * @see useSenhaExistsQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the exists query, like ExtendedBookQuery::class
     *
     * @return \Baja\Model\SenhaQuery The inner query object of the NOT EXISTS statement
     */
    public function useSenhaNotExistsQuery($modelAlias = null, $queryClass = null)
    {
        /** @var $q \Baja\Model\SenhaQuery */
        $q = $this->useExistsQuery('Senha', $modelAlias, $queryClass, 'NOT EXISTS');
        return $q;
    }

    /**
     * Use the relation to Senha table for an IN query.
     *
     * @see \Propel\Runtime\ActiveQuery\ModelCriteria::useInQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the IN query, like ExtendedBookQuery::class
     * @param string $typeOfIn Criteria::IN or Criteria::NOT_IN
     *
     * @return \Baja\Model\SenhaQuery The inner query object of the IN statement
     */
    public function useInSenhaQuery($modelAlias = null, $queryClass = null, $typeOfIn = 'IN')
    {
        /** @var $q \Baja\Model\SenhaQuery */
        $q = $this->useInQuery('Senha', $modelAlias, $queryClass, $typeOfIn);
        return $q;
    }

    /**
     * Use the relation to Senha table for a NOT IN query.
     *
     * @see useSenhaInQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the NOT IN query, like ExtendedBookQuery::class
     *
     * @return \Baja\Model\SenhaQuery The inner query object of the NOT IN statement
     */
    public function useNotInSenhaQuery($modelAlias = null, $queryClass = null)
    {
        /** @var $q \Baja\Model\SenhaQuery */
        $q = $this->useInQuery('Senha', $modelAlias, $queryClass, 'NOT IN');
        return $q;
    }

    /**
     * Exclude object from result
     *
     * @param ChildEquipe $equipe Object to remove from the list of results
     *
     * @return $this The current query, for fluid interface
     */
    public function prune($equipe = null)
    {
        if ($equipe) {
            $this->addCond('pruneCond0', $this->getAliasedColName(EquipeTableMap::COL_EVENTO_ID), $equipe->getEventoId(), Criteria::NOT_EQUAL);
            $this->addCond('pruneCond1', $this->getAliasedColName(EquipeTableMap::COL_EQUIPE_ID), $equipe->getEquipeId(), Criteria::NOT_EQUAL);
            $this->combine(array('pruneCond0', 'pruneCond1'), Criteria::LOGICAL_OR);
        }

        return $this;
    }

    /**
     * Deletes all rows from the equipe table.
     *
     * @param ConnectionInterface $con the connection to use
     * @return int The number of affected rows (if supported by underlying database driver).
     */
    public function doDeleteAll(?ConnectionInterface $con = null): int
    {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(EquipeTableMap::DATABASE_NAME);
        }

        // use transaction because $criteria could contain info
        // for more than one table or we could emulating ON DELETE CASCADE, etc.
        return $con->transaction(function () use ($con) {
            $affectedRows = 0; // initialize var to track total num of affected rows
            $affectedRows += parent::doDeleteAll($con);
            // Because this db requires some delete cascade/set null emulation, we have to
            // clear the cached instance *after* the emulation has happened (since
            // instances get re-added by the select statement contained therein).
            EquipeTableMap::clearInstancePool();
            EquipeTableMap::clearRelatedInstancePool();

            return $affectedRows;
        });
    }

    /**
     * Performs a DELETE on the database based on the current ModelCriteria
     *
     * @param ConnectionInterface $con the connection to use
     * @return int The number of affected rows (if supported by underlying database driver).  This includes CASCADE-related rows
     *                         if supported by native driver or if emulated using Propel.
     * @throws \Propel\Runtime\Exception\PropelException Any exceptions caught during processing will be
     *                         rethrown wrapped into a PropelException.
     */
    public function delete(?ConnectionInterface $con = null): int
    {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(EquipeTableMap::DATABASE_NAME);
        }

        $criteria = $this;

        // Set the correct dbName
        $criteria->setDbName(EquipeTableMap::DATABASE_NAME);

        // use transaction because $criteria could contain info
        // for more than one table or we could emulating ON DELETE CASCADE, etc.
        return $con->transaction(function () use ($con, $criteria) {
            $affectedRows = 0; // initialize var to track total num of affected rows

            EquipeTableMap::removeInstanceFromPool($criteria);

            $affectedRows += ModelCriteria::delete($con);
            EquipeTableMap::clearRelatedInstancePool();

            return $affectedRows;
        });
    }

}
