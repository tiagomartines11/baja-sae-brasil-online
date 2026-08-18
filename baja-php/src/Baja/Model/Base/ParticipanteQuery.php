<?php

namespace Baja\Model\Base;

use \Exception;
use \PDO;
use Baja\Model\Participante as ChildParticipante;
use Baja\Model\ParticipanteQuery as ChildParticipanteQuery;
use Baja\Model\Map\ParticipanteTableMap;
use Propel\Runtime\Propel;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\ModelCriteria;
use Propel\Runtime\ActiveQuery\ModelJoin;
use Propel\Runtime\Collection\Collection;
use Propel\Runtime\Collection\ObjectCollection;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Exception\PropelException;

/**
 * Base class that represents a query for the `participantes` table.
 *
 * @method     ChildParticipanteQuery orderByNome($order = Criteria::ASC) Order by the nome column
 * @method     ChildParticipanteQuery orderByFuncao($order = Criteria::ASC) Order by the funcao column
 * @method     ChildParticipanteQuery orderByCpf($order = Criteria::ASC) Order by the cpf column
 * @method     ChildParticipanteQuery orderByDocumentoEstrangeiro($order = Criteria::ASC) Order by the documento_estrangeiro column
 * @method     ChildParticipanteQuery orderByEventoId($order = Criteria::ASC) Order by the evento column
 * @method     ChildParticipanteQuery orderByToken($order = Criteria::ASC) Order by the token column
 * @method     ChildParticipanteQuery orderByCriadoPor($order = Criteria::ASC) Order by the criado_por column
 * @method     ChildParticipanteQuery orderByCriadoEm($order = Criteria::ASC) Order by the criado_em column
 * @method     ChildParticipanteQuery orderByLoteId($order = Criteria::ASC) Order by the lote_id column
 *
 * @method     ChildParticipanteQuery groupByNome() Group by the nome column
 * @method     ChildParticipanteQuery groupByFuncao() Group by the funcao column
 * @method     ChildParticipanteQuery groupByCpf() Group by the cpf column
 * @method     ChildParticipanteQuery groupByDocumentoEstrangeiro() Group by the documento_estrangeiro column
 * @method     ChildParticipanteQuery groupByEventoId() Group by the evento column
 * @method     ChildParticipanteQuery groupByToken() Group by the token column
 * @method     ChildParticipanteQuery groupByCriadoPor() Group by the criado_por column
 * @method     ChildParticipanteQuery groupByCriadoEm() Group by the criado_em column
 * @method     ChildParticipanteQuery groupByLoteId() Group by the lote_id column
 *
 * @method     ChildParticipanteQuery leftJoin($relation) Adds a LEFT JOIN clause to the query
 * @method     ChildParticipanteQuery rightJoin($relation) Adds a RIGHT JOIN clause to the query
 * @method     ChildParticipanteQuery innerJoin($relation) Adds a INNER JOIN clause to the query
 *
 * @method     ChildParticipanteQuery leftJoinWith($relation) Adds a LEFT JOIN clause and with to the query
 * @method     ChildParticipanteQuery rightJoinWith($relation) Adds a RIGHT JOIN clause and with to the query
 * @method     ChildParticipanteQuery innerJoinWith($relation) Adds a INNER JOIN clause and with to the query
 *
 * @method     ChildParticipanteQuery leftJoinEvento($relationAlias = null) Adds a LEFT JOIN clause to the query using the Evento relation
 * @method     ChildParticipanteQuery rightJoinEvento($relationAlias = null) Adds a RIGHT JOIN clause to the query using the Evento relation
 * @method     ChildParticipanteQuery innerJoinEvento($relationAlias = null) Adds a INNER JOIN clause to the query using the Evento relation
 *
 * @method     ChildParticipanteQuery joinWithEvento($joinType = Criteria::INNER_JOIN) Adds a join clause and with to the query using the Evento relation
 *
 * @method     ChildParticipanteQuery leftJoinWithEvento() Adds a LEFT JOIN clause and with to the query using the Evento relation
 * @method     ChildParticipanteQuery rightJoinWithEvento() Adds a RIGHT JOIN clause and with to the query using the Evento relation
 * @method     ChildParticipanteQuery innerJoinWithEvento() Adds a INNER JOIN clause and with to the query using the Evento relation
 *
 * @method     ChildParticipanteQuery leftJoinUser($relationAlias = null) Adds a LEFT JOIN clause to the query using the User relation
 * @method     ChildParticipanteQuery rightJoinUser($relationAlias = null) Adds a RIGHT JOIN clause to the query using the User relation
 * @method     ChildParticipanteQuery innerJoinUser($relationAlias = null) Adds a INNER JOIN clause to the query using the User relation
 *
 * @method     ChildParticipanteQuery joinWithUser($joinType = Criteria::INNER_JOIN) Adds a join clause and with to the query using the User relation
 *
 * @method     ChildParticipanteQuery leftJoinWithUser() Adds a LEFT JOIN clause and with to the query using the User relation
 * @method     ChildParticipanteQuery rightJoinWithUser() Adds a RIGHT JOIN clause and with to the query using the User relation
 * @method     ChildParticipanteQuery innerJoinWithUser() Adds a INNER JOIN clause and with to the query using the User relation
 *
 * @method     \Baja\Model\EventoQuery|\Baja\Model\UserQuery endUse() Finalizes a secondary criteria and merges it with its primary Criteria
 *
 * @method     ChildParticipante|null findOne(?ConnectionInterface $con = null) Return the first ChildParticipante matching the query
 * @method     ChildParticipante findOneOrCreate(?ConnectionInterface $con = null) Return the first ChildParticipante matching the query, or a new ChildParticipante object populated from the query conditions when no match is found
 *
 * @method     ChildParticipante|null findOneByNome(string $nome) Return the first ChildParticipante filtered by the nome column
 * @method     ChildParticipante|null findOneByFuncao(string $funcao) Return the first ChildParticipante filtered by the funcao column
 * @method     ChildParticipante|null findOneByCpf(string $cpf) Return the first ChildParticipante filtered by the cpf column
 * @method     ChildParticipante|null findOneByDocumentoEstrangeiro(string $documento_estrangeiro) Return the first ChildParticipante filtered by the documento_estrangeiro column
 * @method     ChildParticipante|null findOneByEventoId(string $evento) Return the first ChildParticipante filtered by the evento column
 * @method     ChildParticipante|null findOneByToken(string $token) Return the first ChildParticipante filtered by the token column
 * @method     ChildParticipante|null findOneByCriadoPor(int $criado_por) Return the first ChildParticipante filtered by the criado_por column
 * @method     ChildParticipante|null findOneByCriadoEm(string $criado_em) Return the first ChildParticipante filtered by the criado_em column
 * @method     ChildParticipante|null findOneByLoteId(string $lote_id) Return the first ChildParticipante filtered by the lote_id column
 *
 * @method     ChildParticipante requirePk($key, ?ConnectionInterface $con = null) Return the ChildParticipante by primary key and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildParticipante requireOne(?ConnectionInterface $con = null) Return the first ChildParticipante matching the query and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 *
 * @method     ChildParticipante requireOneByNome(string $nome) Return the first ChildParticipante filtered by the nome column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildParticipante requireOneByFuncao(string $funcao) Return the first ChildParticipante filtered by the funcao column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildParticipante requireOneByCpf(string $cpf) Return the first ChildParticipante filtered by the cpf column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildParticipante requireOneByDocumentoEstrangeiro(string $documento_estrangeiro) Return the first ChildParticipante filtered by the documento_estrangeiro column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildParticipante requireOneByEventoId(string $evento) Return the first ChildParticipante filtered by the evento column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildParticipante requireOneByToken(string $token) Return the first ChildParticipante filtered by the token column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildParticipante requireOneByCriadoPor(int $criado_por) Return the first ChildParticipante filtered by the criado_por column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildParticipante requireOneByCriadoEm(string $criado_em) Return the first ChildParticipante filtered by the criado_em column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildParticipante requireOneByLoteId(string $lote_id) Return the first ChildParticipante filtered by the lote_id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 *
 * @method     ChildParticipante[]|Collection find(?ConnectionInterface $con = null) Return ChildParticipante objects based on current ModelCriteria
 * @psalm-method Collection&\Traversable<ChildParticipante> find(?ConnectionInterface $con = null) Return ChildParticipante objects based on current ModelCriteria
 *
 * @method     ChildParticipante[]|Collection findByNome(string|array<string> $nome) Return ChildParticipante objects filtered by the nome column
 * @psalm-method Collection&\Traversable<ChildParticipante> findByNome(string|array<string> $nome) Return ChildParticipante objects filtered by the nome column
 * @method     ChildParticipante[]|Collection findByFuncao(string|array<string> $funcao) Return ChildParticipante objects filtered by the funcao column
 * @psalm-method Collection&\Traversable<ChildParticipante> findByFuncao(string|array<string> $funcao) Return ChildParticipante objects filtered by the funcao column
 * @method     ChildParticipante[]|Collection findByCpf(string|array<string> $cpf) Return ChildParticipante objects filtered by the cpf column
 * @psalm-method Collection&\Traversable<ChildParticipante> findByCpf(string|array<string> $cpf) Return ChildParticipante objects filtered by the cpf column
 * @method     ChildParticipante[]|Collection findByDocumentoEstrangeiro(string|array<string> $documento_estrangeiro) Return ChildParticipante objects filtered by the documento_estrangeiro column
 * @psalm-method Collection&\Traversable<ChildParticipante> findByDocumentoEstrangeiro(string|array<string> $documento_estrangeiro) Return ChildParticipante objects filtered by the documento_estrangeiro column
 * @method     ChildParticipante[]|Collection findByEventoId(string|array<string> $evento) Return ChildParticipante objects filtered by the evento column
 * @psalm-method Collection&\Traversable<ChildParticipante> findByEventoId(string|array<string> $evento) Return ChildParticipante objects filtered by the evento column
 * @method     ChildParticipante[]|Collection findByToken(string|array<string> $token) Return ChildParticipante objects filtered by the token column
 * @psalm-method Collection&\Traversable<ChildParticipante> findByToken(string|array<string> $token) Return ChildParticipante objects filtered by the token column
 * @method     ChildParticipante[]|Collection findByCriadoPor(int|array<int> $criado_por) Return ChildParticipante objects filtered by the criado_por column
 * @psalm-method Collection&\Traversable<ChildParticipante> findByCriadoPor(int|array<int> $criado_por) Return ChildParticipante objects filtered by the criado_por column
 * @method     ChildParticipante[]|Collection findByCriadoEm(string|array<string> $criado_em) Return ChildParticipante objects filtered by the criado_em column
 * @psalm-method Collection&\Traversable<ChildParticipante> findByCriadoEm(string|array<string> $criado_em) Return ChildParticipante objects filtered by the criado_em column
 * @method     ChildParticipante[]|Collection findByLoteId(string|array<string> $lote_id) Return ChildParticipante objects filtered by the lote_id column
 * @psalm-method Collection&\Traversable<ChildParticipante> findByLoteId(string|array<string> $lote_id) Return ChildParticipante objects filtered by the lote_id column
 *
 * @method     ChildParticipante[]|\Propel\Runtime\Util\PropelModelPager paginate($page = 1, $maxPerPage = 10, ?ConnectionInterface $con = null) Issue a SELECT query based on the current ModelCriteria and uses a page and a maximum number of results per page to compute an offset and a limit
 * @psalm-method \Propel\Runtime\Util\PropelModelPager&\Traversable<ChildParticipante> paginate($page = 1, $maxPerPage = 10, ?ConnectionInterface $con = null) Issue a SELECT query based on the current ModelCriteria and uses a page and a maximum number of results per page to compute an offset and a limit
 */
abstract class ParticipanteQuery extends ModelCriteria
{
    protected $entityNotFoundExceptionClass = '\\Propel\\Runtime\\Exception\\EntityNotFoundException';

    /**
     * Initializes internal state of \Baja\Model\Base\ParticipanteQuery object.
     *
     * @param string $dbName The database name
     * @param string $modelName The phpName of a model, e.g. 'Book'
     * @param string $modelAlias The alias for the model in this query, e.g. 'b'
     */
    public function __construct($dbName = 'resultados', $modelName = '\\Baja\\Model\\Participante', $modelAlias = null)
    {
        parent::__construct($dbName, $modelName, $modelAlias);
    }

    /**
     * Returns a new ChildParticipanteQuery object.
     *
     * @param string $modelAlias The alias of a model in the query
     * @param Criteria $criteria Optional Criteria to build the query from
     *
     * @return ChildParticipanteQuery
     */
    public static function create(?string $modelAlias = null, ?Criteria $criteria = null): Criteria
    {
        if ($criteria instanceof ChildParticipanteQuery) {
            return $criteria;
        }
        $query = new ChildParticipanteQuery();
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
     * $obj  = $c->findPk(12, $con);
     * </code>
     *
     * @param mixed $key Primary key to use for the query
     * @param ConnectionInterface $con an optional connection object
     *
     * @return ChildParticipante|array|mixed the result, formatted by the current formatter
     */
    public function findPk($key, ?ConnectionInterface $con = null)
    {
        if ($key === null) {
            return null;
        }

        if ($con === null) {
            $con = Propel::getServiceContainer()->getReadConnection(ParticipanteTableMap::DATABASE_NAME);
        }

        $this->basePreSelect($con);

        if (
            $this->formatter || $this->modelAlias || $this->with || $this->select
            || $this->selectColumns || $this->asColumns || $this->selectModifiers
            || $this->map || $this->having || $this->joins
        ) {
            return $this->findPkComplex($key, $con);
        }

        if ((null !== ($obj = ParticipanteTableMap::getInstanceFromPool(null === $key || is_scalar($key) || is_callable([$key, '__toString']) ? (string) $key : $key)))) {
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
     * @return ChildParticipante A model object, or null if the key is not found
     */
    protected function findPkSimple($key, ConnectionInterface $con)
    {
        $sql = 'SELECT nome, funcao, cpf, documento_estrangeiro, evento, token, criado_por, criado_em, lote_id FROM participantes WHERE token = :p0';
        try {
            $stmt = $con->prepare($sql);
            $stmt->bindValue(':p0', $key, PDO::PARAM_STR);
            $stmt->execute();
        } catch (Exception $e) {
            Propel::log($e->getMessage(), Propel::LOG_ERR);
            throw new PropelException(sprintf('Unable to execute SELECT statement [%s]', $sql), 0, $e);
        }
        $obj = null;
        if ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
            /** @var ChildParticipante $obj */
            $obj = new ChildParticipante();
            $obj->hydrate($row);
            ParticipanteTableMap::addInstanceToPool($obj, null === $key || is_scalar($key) || is_callable([$key, '__toString']) ? (string) $key : $key);
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
     * @return ChildParticipante|array|mixed the result, formatted by the current formatter
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
     * $objs = $c->findPks(array(12, 56, 832), $con);
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

        $this->addUsingAlias(ParticipanteTableMap::COL_TOKEN, $key, Criteria::EQUAL);

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

        $this->addUsingAlias(ParticipanteTableMap::COL_TOKEN, $keys, Criteria::IN);

        return $this;
    }

    /**
     * Filter the query on the nome column
     *
     * Example usage:
     * <code>
     * $query->filterByNome('fooValue');   // WHERE nome = 'fooValue'
     * $query->filterByNome('%fooValue%', Criteria::LIKE); // WHERE nome LIKE '%fooValue%'
     * $query->filterByNome(['foo', 'bar']); // WHERE nome IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $nome The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByNome($nome = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($nome)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ParticipanteTableMap::COL_NOME, $nome, $comparison);

        return $this;
    }

    /**
     * Filter the query on the funcao column
     *
     * Example usage:
     * <code>
     * $query->filterByFuncao('fooValue');   // WHERE funcao = 'fooValue'
     * $query->filterByFuncao('%fooValue%', Criteria::LIKE); // WHERE funcao LIKE '%fooValue%'
     * $query->filterByFuncao(['foo', 'bar']); // WHERE funcao IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $funcao The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByFuncao($funcao = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($funcao)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ParticipanteTableMap::COL_FUNCAO, $funcao, $comparison);

        return $this;
    }

    /**
     * Filter the query on the cpf column
     *
     * Example usage:
     * <code>
     * $query->filterByCpf('fooValue');   // WHERE cpf = 'fooValue'
     * $query->filterByCpf('%fooValue%', Criteria::LIKE); // WHERE cpf LIKE '%fooValue%'
     * $query->filterByCpf(['foo', 'bar']); // WHERE cpf IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $cpf The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByCpf($cpf = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($cpf)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ParticipanteTableMap::COL_CPF, $cpf, $comparison);

        return $this;
    }

    /**
     * Filter the query on the documento_estrangeiro column
     *
     * Example usage:
     * <code>
     * $query->filterByDocumentoEstrangeiro('fooValue');   // WHERE documento_estrangeiro = 'fooValue'
     * $query->filterByDocumentoEstrangeiro('%fooValue%', Criteria::LIKE); // WHERE documento_estrangeiro LIKE '%fooValue%'
     * $query->filterByDocumentoEstrangeiro(['foo', 'bar']); // WHERE documento_estrangeiro IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $documentoEstrangeiro The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByDocumentoEstrangeiro($documentoEstrangeiro = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($documentoEstrangeiro)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ParticipanteTableMap::COL_DOCUMENTO_ESTRANGEIRO, $documentoEstrangeiro, $comparison);

        return $this;
    }

    /**
     * Filter the query on the evento column
     *
     * Example usage:
     * <code>
     * $query->filterByEventoId('fooValue');   // WHERE evento = 'fooValue'
     * $query->filterByEventoId('%fooValue%', Criteria::LIKE); // WHERE evento LIKE '%fooValue%'
     * $query->filterByEventoId(['foo', 'bar']); // WHERE evento IN ('foo', 'bar')
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

        $this->addUsingAlias(ParticipanteTableMap::COL_EVENTO, $eventoId, $comparison);

        return $this;
    }

    /**
     * Filter the query on the token column
     *
     * Example usage:
     * <code>
     * $query->filterByToken('fooValue');   // WHERE token = 'fooValue'
     * $query->filterByToken('%fooValue%', Criteria::LIKE); // WHERE token LIKE '%fooValue%'
     * $query->filterByToken(['foo', 'bar']); // WHERE token IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $token The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByToken($token = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($token)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ParticipanteTableMap::COL_TOKEN, $token, $comparison);

        return $this;
    }

    /**
     * Filter the query on the criado_por column
     *
     * Example usage:
     * <code>
     * $query->filterByCriadoPor(1234); // WHERE criado_por = 1234
     * $query->filterByCriadoPor(array(12, 34)); // WHERE criado_por IN (12, 34)
     * $query->filterByCriadoPor(array('min' => 12)); // WHERE criado_por > 12
     * </code>
     *
     * @see       filterByUser()
     *
     * @param mixed $criadoPor The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByCriadoPor($criadoPor = null, ?string $comparison = null)
    {
        if (is_array($criadoPor)) {
            $useMinMax = false;
            if (isset($criadoPor['min'])) {
                $this->addUsingAlias(ParticipanteTableMap::COL_CRIADO_POR, $criadoPor['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($criadoPor['max'])) {
                $this->addUsingAlias(ParticipanteTableMap::COL_CRIADO_POR, $criadoPor['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ParticipanteTableMap::COL_CRIADO_POR, $criadoPor, $comparison);

        return $this;
    }

    /**
     * Filter the query on the criado_em column
     *
     * Example usage:
     * <code>
     * $query->filterByCriadoEm('2011-03-14'); // WHERE criado_em = '2011-03-14'
     * $query->filterByCriadoEm('now'); // WHERE criado_em = '2011-03-14'
     * $query->filterByCriadoEm(array('max' => 'yesterday')); // WHERE criado_em > '2011-03-13'
     * </code>
     *
     * @param mixed $criadoEm The value to use as filter.
     *              Values can be integers (unix timestamps), DateTime objects, or strings.
     *              Empty strings are treated as NULL.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByCriadoEm($criadoEm = null, ?string $comparison = null)
    {
        if (is_array($criadoEm)) {
            $useMinMax = false;
            if (isset($criadoEm['min'])) {
                $this->addUsingAlias(ParticipanteTableMap::COL_CRIADO_EM, $criadoEm['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($criadoEm['max'])) {
                $this->addUsingAlias(ParticipanteTableMap::COL_CRIADO_EM, $criadoEm['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ParticipanteTableMap::COL_CRIADO_EM, $criadoEm, $comparison);

        return $this;
    }

    /**
     * Filter the query on the lote_id column
     *
     * Example usage:
     * <code>
     * $query->filterByLoteId('fooValue');   // WHERE lote_id = 'fooValue'
     * $query->filterByLoteId('%fooValue%', Criteria::LIKE); // WHERE lote_id LIKE '%fooValue%'
     * $query->filterByLoteId(['foo', 'bar']); // WHERE lote_id IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $loteId The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByLoteId($loteId = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($loteId)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ParticipanteTableMap::COL_LOTE_ID, $loteId, $comparison);

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
                ->addUsingAlias(ParticipanteTableMap::COL_EVENTO, $evento->getEventoId(), $comparison);
        } elseif ($evento instanceof ObjectCollection) {
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }

            $this
                ->addUsingAlias(ParticipanteTableMap::COL_EVENTO, $evento->toKeyValue('PrimaryKey', 'EventoId'), $comparison);

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
     * Filter the query by a related \Baja\Model\User object
     *
     * @param \Baja\Model\User|ObjectCollection $user The related object(s) to use as filter
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByUser($user, ?string $comparison = null)
    {
        if ($user instanceof \Baja\Model\User) {
            return $this
                ->addUsingAlias(ParticipanteTableMap::COL_CRIADO_POR, $user->getUserId(), $comparison);
        } elseif ($user instanceof ObjectCollection) {
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }

            $this
                ->addUsingAlias(ParticipanteTableMap::COL_CRIADO_POR, $user->toKeyValue('PrimaryKey', 'UserId'), $comparison);

            return $this;
        } else {
            throw new PropelException('filterByUser() only accepts arguments of type \Baja\Model\User or Collection');
        }
    }

    /**
     * Adds a JOIN clause to the query using the User relation
     *
     * @param string|null $relationAlias Optional alias for the relation
     * @param string|null $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return $this The current query, for fluid interface
     */
    public function joinUser(?string $relationAlias = null, ?string $joinType = Criteria::LEFT_JOIN)
    {
        $tableMap = $this->getTableMap();
        $relationMap = $tableMap->getRelation('User');

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
            $this->addJoinObject($join, 'User');
        }

        return $this;
    }

    /**
     * Use the User relation User object
     *
     * @see useQuery()
     *
     * @param string $relationAlias optional alias for the relation,
     *                                   to be used as main alias in the secondary query
     * @param string $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return \Baja\Model\UserQuery A secondary query class using the current class as primary query
     */
    public function useUserQuery($relationAlias = null, $joinType = Criteria::LEFT_JOIN)
    {
        return $this
            ->joinUser($relationAlias, $joinType)
            ->useQuery($relationAlias ? $relationAlias : 'User', '\Baja\Model\UserQuery');
    }

    /**
     * Use the User relation User object
     *
     * @param callable(\Baja\Model\UserQuery):\Baja\Model\UserQuery $callable A function working on the related query
     *
     * @param string|null $relationAlias optional alias for the relation
     *
     * @param string|null $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return $this
     */
    public function withUserQuery(
        callable $callable,
        string $relationAlias = null,
        ?string $joinType = Criteria::LEFT_JOIN
    ) {
        $relatedQuery = $this->useUserQuery(
            $relationAlias,
            $joinType
        );
        $callable($relatedQuery);
        $relatedQuery->endUse();

        return $this;
    }

    /**
     * Use the relation to User table for an EXISTS query.
     *
     * @see \Propel\Runtime\ActiveQuery\ModelCriteria::useExistsQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the exists query, like ExtendedBookQuery::class
     * @param string $typeOfExists Either ExistsQueryCriterion::TYPE_EXISTS or ExistsQueryCriterion::TYPE_NOT_EXISTS
     *
     * @return \Baja\Model\UserQuery The inner query object of the EXISTS statement
     */
    public function useUserExistsQuery($modelAlias = null, $queryClass = null, $typeOfExists = 'EXISTS')
    {
        /** @var $q \Baja\Model\UserQuery */
        $q = $this->useExistsQuery('User', $modelAlias, $queryClass, $typeOfExists);
        return $q;
    }

    /**
     * Use the relation to User table for a NOT EXISTS query.
     *
     * @see useUserExistsQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the exists query, like ExtendedBookQuery::class
     *
     * @return \Baja\Model\UserQuery The inner query object of the NOT EXISTS statement
     */
    public function useUserNotExistsQuery($modelAlias = null, $queryClass = null)
    {
        /** @var $q \Baja\Model\UserQuery */
        $q = $this->useExistsQuery('User', $modelAlias, $queryClass, 'NOT EXISTS');
        return $q;
    }

    /**
     * Use the relation to User table for an IN query.
     *
     * @see \Propel\Runtime\ActiveQuery\ModelCriteria::useInQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the IN query, like ExtendedBookQuery::class
     * @param string $typeOfIn Criteria::IN or Criteria::NOT_IN
     *
     * @return \Baja\Model\UserQuery The inner query object of the IN statement
     */
    public function useInUserQuery($modelAlias = null, $queryClass = null, $typeOfIn = 'IN')
    {
        /** @var $q \Baja\Model\UserQuery */
        $q = $this->useInQuery('User', $modelAlias, $queryClass, $typeOfIn);
        return $q;
    }

    /**
     * Use the relation to User table for a NOT IN query.
     *
     * @see useUserInQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the NOT IN query, like ExtendedBookQuery::class
     *
     * @return \Baja\Model\UserQuery The inner query object of the NOT IN statement
     */
    public function useNotInUserQuery($modelAlias = null, $queryClass = null)
    {
        /** @var $q \Baja\Model\UserQuery */
        $q = $this->useInQuery('User', $modelAlias, $queryClass, 'NOT IN');
        return $q;
    }

    /**
     * Exclude object from result
     *
     * @param ChildParticipante $participante Object to remove from the list of results
     *
     * @return $this The current query, for fluid interface
     */
    public function prune($participante = null)
    {
        if ($participante) {
            $this->addUsingAlias(ParticipanteTableMap::COL_TOKEN, $participante->getToken(), Criteria::NOT_EQUAL);
        }

        return $this;
    }

    /**
     * Deletes all rows from the participantes table.
     *
     * @param ConnectionInterface $con the connection to use
     * @return int The number of affected rows (if supported by underlying database driver).
     */
    public function doDeleteAll(?ConnectionInterface $con = null): int
    {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(ParticipanteTableMap::DATABASE_NAME);
        }

        // use transaction because $criteria could contain info
        // for more than one table or we could emulating ON DELETE CASCADE, etc.
        return $con->transaction(function () use ($con) {
            $affectedRows = 0; // initialize var to track total num of affected rows
            $affectedRows += parent::doDeleteAll($con);
            // Because this db requires some delete cascade/set null emulation, we have to
            // clear the cached instance *after* the emulation has happened (since
            // instances get re-added by the select statement contained therein).
            ParticipanteTableMap::clearInstancePool();
            ParticipanteTableMap::clearRelatedInstancePool();

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
            $con = Propel::getServiceContainer()->getWriteConnection(ParticipanteTableMap::DATABASE_NAME);
        }

        $criteria = $this;

        // Set the correct dbName
        $criteria->setDbName(ParticipanteTableMap::DATABASE_NAME);

        // use transaction because $criteria could contain info
        // for more than one table or we could emulating ON DELETE CASCADE, etc.
        return $con->transaction(function () use ($con, $criteria) {
            $affectedRows = 0; // initialize var to track total num of affected rows

            ParticipanteTableMap::removeInstanceFromPool($criteria);

            $affectedRows += ModelCriteria::delete($con);
            ParticipanteTableMap::clearRelatedInstancePool();

            return $affectedRows;
        });
    }

}
