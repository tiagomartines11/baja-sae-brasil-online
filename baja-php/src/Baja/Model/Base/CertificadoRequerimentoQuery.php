<?php

namespace Baja\Model\Base;

use \Exception;
use \PDO;
use Baja\Model\CertificadoRequerimento as ChildCertificadoRequerimento;
use Baja\Model\CertificadoRequerimentoQuery as ChildCertificadoRequerimentoQuery;
use Baja\Model\Map\CertificadoRequerimentoTableMap;
use Propel\Runtime\Propel;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\ModelCriteria;
use Propel\Runtime\ActiveQuery\ModelJoin;
use Propel\Runtime\Collection\Collection;
use Propel\Runtime\Collection\ObjectCollection;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Exception\PropelException;

/**
 * Base class that represents a query for the `certificado_requerimento` table.
 *
 * @method     ChildCertificadoRequerimentoQuery orderByRequerimentoId($order = Criteria::ASC) Order by the requerimento_id column
 * @method     ChildCertificadoRequerimentoQuery orderByCaso($order = Criteria::ASC) Order by the caso column
 * @method     ChildCertificadoRequerimentoQuery orderByToken($order = Criteria::ASC) Order by the token column
 * @method     ChildCertificadoRequerimentoQuery orderByEventoId($order = Criteria::ASC) Order by the evento column
 * @method     ChildCertificadoRequerimentoQuery orderByEventoTexto($order = Criteria::ASC) Order by the evento_texto column
 * @method     ChildCertificadoRequerimentoQuery orderByDocumento($order = Criteria::ASC) Order by the documento column
 * @method     ChildCertificadoRequerimentoQuery orderByNome($order = Criteria::ASC) Order by the nome column
 * @method     ChildCertificadoRequerimentoQuery orderByEmail($order = Criteria::ASC) Order by the email column
 * @method     ChildCertificadoRequerimentoQuery orderByTelefone($order = Criteria::ASC) Order by the telefone column
 * @method     ChildCertificadoRequerimentoQuery orderByDescricao($order = Criteria::ASC) Order by the descricao column
 * @method     ChildCertificadoRequerimentoQuery orderByCriadoEm($order = Criteria::ASC) Order by the criado_em column
 * @method     ChildCertificadoRequerimentoQuery orderByAvisadoEm($order = Criteria::ASC) Order by the avisado_em column
 * @method     ChildCertificadoRequerimentoQuery orderByStatus($order = Criteria::ASC) Order by the status column
 * @method     ChildCertificadoRequerimentoQuery orderByResolvidoPor($order = Criteria::ASC) Order by the resolvido_por column
 * @method     ChildCertificadoRequerimentoQuery orderByResolvidoEm($order = Criteria::ASC) Order by the resolvido_em column
 * @method     ChildCertificadoRequerimentoQuery orderByResolucao($order = Criteria::ASC) Order by the resolucao column
 *
 * @method     ChildCertificadoRequerimentoQuery groupByRequerimentoId() Group by the requerimento_id column
 * @method     ChildCertificadoRequerimentoQuery groupByCaso() Group by the caso column
 * @method     ChildCertificadoRequerimentoQuery groupByToken() Group by the token column
 * @method     ChildCertificadoRequerimentoQuery groupByEventoId() Group by the evento column
 * @method     ChildCertificadoRequerimentoQuery groupByEventoTexto() Group by the evento_texto column
 * @method     ChildCertificadoRequerimentoQuery groupByDocumento() Group by the documento column
 * @method     ChildCertificadoRequerimentoQuery groupByNome() Group by the nome column
 * @method     ChildCertificadoRequerimentoQuery groupByEmail() Group by the email column
 * @method     ChildCertificadoRequerimentoQuery groupByTelefone() Group by the telefone column
 * @method     ChildCertificadoRequerimentoQuery groupByDescricao() Group by the descricao column
 * @method     ChildCertificadoRequerimentoQuery groupByCriadoEm() Group by the criado_em column
 * @method     ChildCertificadoRequerimentoQuery groupByAvisadoEm() Group by the avisado_em column
 * @method     ChildCertificadoRequerimentoQuery groupByStatus() Group by the status column
 * @method     ChildCertificadoRequerimentoQuery groupByResolvidoPor() Group by the resolvido_por column
 * @method     ChildCertificadoRequerimentoQuery groupByResolvidoEm() Group by the resolvido_em column
 * @method     ChildCertificadoRequerimentoQuery groupByResolucao() Group by the resolucao column
 *
 * @method     ChildCertificadoRequerimentoQuery leftJoin($relation) Adds a LEFT JOIN clause to the query
 * @method     ChildCertificadoRequerimentoQuery rightJoin($relation) Adds a RIGHT JOIN clause to the query
 * @method     ChildCertificadoRequerimentoQuery innerJoin($relation) Adds a INNER JOIN clause to the query
 *
 * @method     ChildCertificadoRequerimentoQuery leftJoinWith($relation) Adds a LEFT JOIN clause and with to the query
 * @method     ChildCertificadoRequerimentoQuery rightJoinWith($relation) Adds a RIGHT JOIN clause and with to the query
 * @method     ChildCertificadoRequerimentoQuery innerJoinWith($relation) Adds a INNER JOIN clause and with to the query
 *
 * @method     ChildCertificadoRequerimentoQuery leftJoinUser($relationAlias = null) Adds a LEFT JOIN clause to the query using the User relation
 * @method     ChildCertificadoRequerimentoQuery rightJoinUser($relationAlias = null) Adds a RIGHT JOIN clause to the query using the User relation
 * @method     ChildCertificadoRequerimentoQuery innerJoinUser($relationAlias = null) Adds a INNER JOIN clause to the query using the User relation
 *
 * @method     ChildCertificadoRequerimentoQuery joinWithUser($joinType = Criteria::INNER_JOIN) Adds a join clause and with to the query using the User relation
 *
 * @method     ChildCertificadoRequerimentoQuery leftJoinWithUser() Adds a LEFT JOIN clause and with to the query using the User relation
 * @method     ChildCertificadoRequerimentoQuery rightJoinWithUser() Adds a RIGHT JOIN clause and with to the query using the User relation
 * @method     ChildCertificadoRequerimentoQuery innerJoinWithUser() Adds a INNER JOIN clause and with to the query using the User relation
 *
 * @method     \Baja\Model\UserQuery endUse() Finalizes a secondary criteria and merges it with its primary Criteria
 *
 * @method     ChildCertificadoRequerimento|null findOne(?ConnectionInterface $con = null) Return the first ChildCertificadoRequerimento matching the query
 * @method     ChildCertificadoRequerimento findOneOrCreate(?ConnectionInterface $con = null) Return the first ChildCertificadoRequerimento matching the query, or a new ChildCertificadoRequerimento object populated from the query conditions when no match is found
 *
 * @method     ChildCertificadoRequerimento|null findOneByRequerimentoId(string $requerimento_id) Return the first ChildCertificadoRequerimento filtered by the requerimento_id column
 * @method     ChildCertificadoRequerimento|null findOneByCaso(int $caso) Return the first ChildCertificadoRequerimento filtered by the caso column
 * @method     ChildCertificadoRequerimento|null findOneByToken(string $token) Return the first ChildCertificadoRequerimento filtered by the token column
 * @method     ChildCertificadoRequerimento|null findOneByEventoId(string $evento) Return the first ChildCertificadoRequerimento filtered by the evento column
 * @method     ChildCertificadoRequerimento|null findOneByEventoTexto(string $evento_texto) Return the first ChildCertificadoRequerimento filtered by the evento_texto column
 * @method     ChildCertificadoRequerimento|null findOneByDocumento(string $documento) Return the first ChildCertificadoRequerimento filtered by the documento column
 * @method     ChildCertificadoRequerimento|null findOneByNome(string $nome) Return the first ChildCertificadoRequerimento filtered by the nome column
 * @method     ChildCertificadoRequerimento|null findOneByEmail(string $email) Return the first ChildCertificadoRequerimento filtered by the email column
 * @method     ChildCertificadoRequerimento|null findOneByTelefone(string $telefone) Return the first ChildCertificadoRequerimento filtered by the telefone column
 * @method     ChildCertificadoRequerimento|null findOneByDescricao(string $descricao) Return the first ChildCertificadoRequerimento filtered by the descricao column
 * @method     ChildCertificadoRequerimento|null findOneByCriadoEm(string $criado_em) Return the first ChildCertificadoRequerimento filtered by the criado_em column
 * @method     ChildCertificadoRequerimento|null findOneByAvisadoEm(string $avisado_em) Return the first ChildCertificadoRequerimento filtered by the avisado_em column
 * @method     ChildCertificadoRequerimento|null findOneByStatus(int $status) Return the first ChildCertificadoRequerimento filtered by the status column
 * @method     ChildCertificadoRequerimento|null findOneByResolvidoPor(int $resolvido_por) Return the first ChildCertificadoRequerimento filtered by the resolvido_por column
 * @method     ChildCertificadoRequerimento|null findOneByResolvidoEm(string $resolvido_em) Return the first ChildCertificadoRequerimento filtered by the resolvido_em column
 * @method     ChildCertificadoRequerimento|null findOneByResolucao(string $resolucao) Return the first ChildCertificadoRequerimento filtered by the resolucao column
 *
 * @method     ChildCertificadoRequerimento requirePk($key, ?ConnectionInterface $con = null) Return the ChildCertificadoRequerimento by primary key and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildCertificadoRequerimento requireOne(?ConnectionInterface $con = null) Return the first ChildCertificadoRequerimento matching the query and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 *
 * @method     ChildCertificadoRequerimento requireOneByRequerimentoId(string $requerimento_id) Return the first ChildCertificadoRequerimento filtered by the requerimento_id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildCertificadoRequerimento requireOneByCaso(int $caso) Return the first ChildCertificadoRequerimento filtered by the caso column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildCertificadoRequerimento requireOneByToken(string $token) Return the first ChildCertificadoRequerimento filtered by the token column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildCertificadoRequerimento requireOneByEventoId(string $evento) Return the first ChildCertificadoRequerimento filtered by the evento column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildCertificadoRequerimento requireOneByEventoTexto(string $evento_texto) Return the first ChildCertificadoRequerimento filtered by the evento_texto column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildCertificadoRequerimento requireOneByDocumento(string $documento) Return the first ChildCertificadoRequerimento filtered by the documento column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildCertificadoRequerimento requireOneByNome(string $nome) Return the first ChildCertificadoRequerimento filtered by the nome column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildCertificadoRequerimento requireOneByEmail(string $email) Return the first ChildCertificadoRequerimento filtered by the email column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildCertificadoRequerimento requireOneByTelefone(string $telefone) Return the first ChildCertificadoRequerimento filtered by the telefone column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildCertificadoRequerimento requireOneByDescricao(string $descricao) Return the first ChildCertificadoRequerimento filtered by the descricao column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildCertificadoRequerimento requireOneByCriadoEm(string $criado_em) Return the first ChildCertificadoRequerimento filtered by the criado_em column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildCertificadoRequerimento requireOneByAvisadoEm(string $avisado_em) Return the first ChildCertificadoRequerimento filtered by the avisado_em column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildCertificadoRequerimento requireOneByStatus(int $status) Return the first ChildCertificadoRequerimento filtered by the status column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildCertificadoRequerimento requireOneByResolvidoPor(int $resolvido_por) Return the first ChildCertificadoRequerimento filtered by the resolvido_por column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildCertificadoRequerimento requireOneByResolvidoEm(string $resolvido_em) Return the first ChildCertificadoRequerimento filtered by the resolvido_em column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildCertificadoRequerimento requireOneByResolucao(string $resolucao) Return the first ChildCertificadoRequerimento filtered by the resolucao column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 *
 * @method     ChildCertificadoRequerimento[]|Collection find(?ConnectionInterface $con = null) Return ChildCertificadoRequerimento objects based on current ModelCriteria
 * @psalm-method Collection&\Traversable<ChildCertificadoRequerimento> find(?ConnectionInterface $con = null) Return ChildCertificadoRequerimento objects based on current ModelCriteria
 *
 * @method     ChildCertificadoRequerimento[]|Collection findByRequerimentoId(string|array<string> $requerimento_id) Return ChildCertificadoRequerimento objects filtered by the requerimento_id column
 * @psalm-method Collection&\Traversable<ChildCertificadoRequerimento> findByRequerimentoId(string|array<string> $requerimento_id) Return ChildCertificadoRequerimento objects filtered by the requerimento_id column
 * @method     ChildCertificadoRequerimento[]|Collection findByCaso(int|array<int> $caso) Return ChildCertificadoRequerimento objects filtered by the caso column
 * @psalm-method Collection&\Traversable<ChildCertificadoRequerimento> findByCaso(int|array<int> $caso) Return ChildCertificadoRequerimento objects filtered by the caso column
 * @method     ChildCertificadoRequerimento[]|Collection findByToken(string|array<string> $token) Return ChildCertificadoRequerimento objects filtered by the token column
 * @psalm-method Collection&\Traversable<ChildCertificadoRequerimento> findByToken(string|array<string> $token) Return ChildCertificadoRequerimento objects filtered by the token column
 * @method     ChildCertificadoRequerimento[]|Collection findByEventoId(string|array<string> $evento) Return ChildCertificadoRequerimento objects filtered by the evento column
 * @psalm-method Collection&\Traversable<ChildCertificadoRequerimento> findByEventoId(string|array<string> $evento) Return ChildCertificadoRequerimento objects filtered by the evento column
 * @method     ChildCertificadoRequerimento[]|Collection findByEventoTexto(string|array<string> $evento_texto) Return ChildCertificadoRequerimento objects filtered by the evento_texto column
 * @psalm-method Collection&\Traversable<ChildCertificadoRequerimento> findByEventoTexto(string|array<string> $evento_texto) Return ChildCertificadoRequerimento objects filtered by the evento_texto column
 * @method     ChildCertificadoRequerimento[]|Collection findByDocumento(string|array<string> $documento) Return ChildCertificadoRequerimento objects filtered by the documento column
 * @psalm-method Collection&\Traversable<ChildCertificadoRequerimento> findByDocumento(string|array<string> $documento) Return ChildCertificadoRequerimento objects filtered by the documento column
 * @method     ChildCertificadoRequerimento[]|Collection findByNome(string|array<string> $nome) Return ChildCertificadoRequerimento objects filtered by the nome column
 * @psalm-method Collection&\Traversable<ChildCertificadoRequerimento> findByNome(string|array<string> $nome) Return ChildCertificadoRequerimento objects filtered by the nome column
 * @method     ChildCertificadoRequerimento[]|Collection findByEmail(string|array<string> $email) Return ChildCertificadoRequerimento objects filtered by the email column
 * @psalm-method Collection&\Traversable<ChildCertificadoRequerimento> findByEmail(string|array<string> $email) Return ChildCertificadoRequerimento objects filtered by the email column
 * @method     ChildCertificadoRequerimento[]|Collection findByTelefone(string|array<string> $telefone) Return ChildCertificadoRequerimento objects filtered by the telefone column
 * @psalm-method Collection&\Traversable<ChildCertificadoRequerimento> findByTelefone(string|array<string> $telefone) Return ChildCertificadoRequerimento objects filtered by the telefone column
 * @method     ChildCertificadoRequerimento[]|Collection findByDescricao(string|array<string> $descricao) Return ChildCertificadoRequerimento objects filtered by the descricao column
 * @psalm-method Collection&\Traversable<ChildCertificadoRequerimento> findByDescricao(string|array<string> $descricao) Return ChildCertificadoRequerimento objects filtered by the descricao column
 * @method     ChildCertificadoRequerimento[]|Collection findByCriadoEm(string|array<string> $criado_em) Return ChildCertificadoRequerimento objects filtered by the criado_em column
 * @psalm-method Collection&\Traversable<ChildCertificadoRequerimento> findByCriadoEm(string|array<string> $criado_em) Return ChildCertificadoRequerimento objects filtered by the criado_em column
 * @method     ChildCertificadoRequerimento[]|Collection findByAvisadoEm(string|array<string> $avisado_em) Return ChildCertificadoRequerimento objects filtered by the avisado_em column
 * @psalm-method Collection&\Traversable<ChildCertificadoRequerimento> findByAvisadoEm(string|array<string> $avisado_em) Return ChildCertificadoRequerimento objects filtered by the avisado_em column
 * @method     ChildCertificadoRequerimento[]|Collection findByStatus(int|array<int> $status) Return ChildCertificadoRequerimento objects filtered by the status column
 * @psalm-method Collection&\Traversable<ChildCertificadoRequerimento> findByStatus(int|array<int> $status) Return ChildCertificadoRequerimento objects filtered by the status column
 * @method     ChildCertificadoRequerimento[]|Collection findByResolvidoPor(int|array<int> $resolvido_por) Return ChildCertificadoRequerimento objects filtered by the resolvido_por column
 * @psalm-method Collection&\Traversable<ChildCertificadoRequerimento> findByResolvidoPor(int|array<int> $resolvido_por) Return ChildCertificadoRequerimento objects filtered by the resolvido_por column
 * @method     ChildCertificadoRequerimento[]|Collection findByResolvidoEm(string|array<string> $resolvido_em) Return ChildCertificadoRequerimento objects filtered by the resolvido_em column
 * @psalm-method Collection&\Traversable<ChildCertificadoRequerimento> findByResolvidoEm(string|array<string> $resolvido_em) Return ChildCertificadoRequerimento objects filtered by the resolvido_em column
 * @method     ChildCertificadoRequerimento[]|Collection findByResolucao(string|array<string> $resolucao) Return ChildCertificadoRequerimento objects filtered by the resolucao column
 * @psalm-method Collection&\Traversable<ChildCertificadoRequerimento> findByResolucao(string|array<string> $resolucao) Return ChildCertificadoRequerimento objects filtered by the resolucao column
 *
 * @method     ChildCertificadoRequerimento[]|\Propel\Runtime\Util\PropelModelPager paginate($page = 1, $maxPerPage = 10, ?ConnectionInterface $con = null) Issue a SELECT query based on the current ModelCriteria and uses a page and a maximum number of results per page to compute an offset and a limit
 * @psalm-method \Propel\Runtime\Util\PropelModelPager&\Traversable<ChildCertificadoRequerimento> paginate($page = 1, $maxPerPage = 10, ?ConnectionInterface $con = null) Issue a SELECT query based on the current ModelCriteria and uses a page and a maximum number of results per page to compute an offset and a limit
 */
abstract class CertificadoRequerimentoQuery extends ModelCriteria
{
    protected $entityNotFoundExceptionClass = '\\Propel\\Runtime\\Exception\\EntityNotFoundException';

    /**
     * Initializes internal state of \Baja\Model\Base\CertificadoRequerimentoQuery object.
     *
     * @param string $dbName The database name
     * @param string $modelName The phpName of a model, e.g. 'Book'
     * @param string $modelAlias The alias for the model in this query, e.g. 'b'
     */
    public function __construct($dbName = 'resultados', $modelName = '\\Baja\\Model\\CertificadoRequerimento', $modelAlias = null)
    {
        parent::__construct($dbName, $modelName, $modelAlias);
    }

    /**
     * Returns a new ChildCertificadoRequerimentoQuery object.
     *
     * @param string $modelAlias The alias of a model in the query
     * @param Criteria $criteria Optional Criteria to build the query from
     *
     * @return ChildCertificadoRequerimentoQuery
     */
    public static function create(?string $modelAlias = null, ?Criteria $criteria = null): Criteria
    {
        if ($criteria instanceof ChildCertificadoRequerimentoQuery) {
            return $criteria;
        }
        $query = new ChildCertificadoRequerimentoQuery();
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
     * @return ChildCertificadoRequerimento|array|mixed the result, formatted by the current formatter
     */
    public function findPk($key, ?ConnectionInterface $con = null)
    {
        if ($key === null) {
            return null;
        }

        if ($con === null) {
            $con = Propel::getServiceContainer()->getReadConnection(CertificadoRequerimentoTableMap::DATABASE_NAME);
        }

        $this->basePreSelect($con);

        if (
            $this->formatter || $this->modelAlias || $this->with || $this->select
            || $this->selectColumns || $this->asColumns || $this->selectModifiers
            || $this->map || $this->having || $this->joins
        ) {
            return $this->findPkComplex($key, $con);
        }

        if ((null !== ($obj = CertificadoRequerimentoTableMap::getInstanceFromPool(null === $key || is_scalar($key) || is_callable([$key, '__toString']) ? (string) $key : $key)))) {
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
     * @return ChildCertificadoRequerimento A model object, or null if the key is not found
     */
    protected function findPkSimple($key, ConnectionInterface $con)
    {
        $sql = 'SELECT requerimento_id, caso, token, evento, evento_texto, documento, nome, email, telefone, descricao, criado_em, avisado_em, status, resolvido_por, resolvido_em, resolucao FROM certificado_requerimento WHERE requerimento_id = :p0';
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
            /** @var ChildCertificadoRequerimento $obj */
            $obj = new ChildCertificadoRequerimento();
            $obj->hydrate($row);
            CertificadoRequerimentoTableMap::addInstanceToPool($obj, null === $key || is_scalar($key) || is_callable([$key, '__toString']) ? (string) $key : $key);
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
     * @return ChildCertificadoRequerimento|array|mixed the result, formatted by the current formatter
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

        $this->addUsingAlias(CertificadoRequerimentoTableMap::COL_REQUERIMENTO_ID, $key, Criteria::EQUAL);

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

        $this->addUsingAlias(CertificadoRequerimentoTableMap::COL_REQUERIMENTO_ID, $keys, Criteria::IN);

        return $this;
    }

    /**
     * Filter the query on the requerimento_id column
     *
     * Example usage:
     * <code>
     * $query->filterByRequerimentoId('fooValue');   // WHERE requerimento_id = 'fooValue'
     * $query->filterByRequerimentoId('%fooValue%', Criteria::LIKE); // WHERE requerimento_id LIKE '%fooValue%'
     * $query->filterByRequerimentoId(['foo', 'bar']); // WHERE requerimento_id IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $requerimentoId The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByRequerimentoId($requerimentoId = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($requerimentoId)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(CertificadoRequerimentoTableMap::COL_REQUERIMENTO_ID, $requerimentoId, $comparison);

        return $this;
    }

    /**
     * Filter the query on the caso column
     *
     * @param mixed $caso The value to use as filter
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByCaso($caso = null, ?string $comparison = null)
    {
        $valueSet = CertificadoRequerimentoTableMap::getValueSet(CertificadoRequerimentoTableMap::COL_CASO);
        if (is_scalar($caso)) {
            if (!in_array($caso, $valueSet)) {
                throw new PropelException(sprintf('Value "%s" is not accepted in this enumerated column', $caso));
            }
            $caso = array_search($caso, $valueSet);
        } elseif (is_array($caso)) {
            $convertedValues = [];
            foreach ($caso as $value) {
                if (!in_array($value, $valueSet)) {
                    throw new PropelException(sprintf('Value "%s" is not accepted in this enumerated column', $value));
                }
                $convertedValues []= array_search($value, $valueSet);
            }
            $caso = $convertedValues;
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(CertificadoRequerimentoTableMap::COL_CASO, $caso, $comparison);

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

        $this->addUsingAlias(CertificadoRequerimentoTableMap::COL_TOKEN, $token, $comparison);

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

        $this->addUsingAlias(CertificadoRequerimentoTableMap::COL_EVENTO, $eventoId, $comparison);

        return $this;
    }

    /**
     * Filter the query on the evento_texto column
     *
     * Example usage:
     * <code>
     * $query->filterByEventoTexto('fooValue');   // WHERE evento_texto = 'fooValue'
     * $query->filterByEventoTexto('%fooValue%', Criteria::LIKE); // WHERE evento_texto LIKE '%fooValue%'
     * $query->filterByEventoTexto(['foo', 'bar']); // WHERE evento_texto IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $eventoTexto The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByEventoTexto($eventoTexto = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($eventoTexto)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(CertificadoRequerimentoTableMap::COL_EVENTO_TEXTO, $eventoTexto, $comparison);

        return $this;
    }

    /**
     * Filter the query on the documento column
     *
     * Example usage:
     * <code>
     * $query->filterByDocumento('fooValue');   // WHERE documento = 'fooValue'
     * $query->filterByDocumento('%fooValue%', Criteria::LIKE); // WHERE documento LIKE '%fooValue%'
     * $query->filterByDocumento(['foo', 'bar']); // WHERE documento IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $documento The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByDocumento($documento = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($documento)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(CertificadoRequerimentoTableMap::COL_DOCUMENTO, $documento, $comparison);

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

        $this->addUsingAlias(CertificadoRequerimentoTableMap::COL_NOME, $nome, $comparison);

        return $this;
    }

    /**
     * Filter the query on the email column
     *
     * Example usage:
     * <code>
     * $query->filterByEmail('fooValue');   // WHERE email = 'fooValue'
     * $query->filterByEmail('%fooValue%', Criteria::LIKE); // WHERE email LIKE '%fooValue%'
     * $query->filterByEmail(['foo', 'bar']); // WHERE email IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $email The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByEmail($email = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($email)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(CertificadoRequerimentoTableMap::COL_EMAIL, $email, $comparison);

        return $this;
    }

    /**
     * Filter the query on the telefone column
     *
     * Example usage:
     * <code>
     * $query->filterByTelefone('fooValue');   // WHERE telefone = 'fooValue'
     * $query->filterByTelefone('%fooValue%', Criteria::LIKE); // WHERE telefone LIKE '%fooValue%'
     * $query->filterByTelefone(['foo', 'bar']); // WHERE telefone IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $telefone The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByTelefone($telefone = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($telefone)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(CertificadoRequerimentoTableMap::COL_TELEFONE, $telefone, $comparison);

        return $this;
    }

    /**
     * Filter the query on the descricao column
     *
     * Example usage:
     * <code>
     * $query->filterByDescricao('fooValue');   // WHERE descricao = 'fooValue'
     * $query->filterByDescricao('%fooValue%', Criteria::LIKE); // WHERE descricao LIKE '%fooValue%'
     * $query->filterByDescricao(['foo', 'bar']); // WHERE descricao IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $descricao The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByDescricao($descricao = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($descricao)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(CertificadoRequerimentoTableMap::COL_DESCRICAO, $descricao, $comparison);

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
                $this->addUsingAlias(CertificadoRequerimentoTableMap::COL_CRIADO_EM, $criadoEm['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($criadoEm['max'])) {
                $this->addUsingAlias(CertificadoRequerimentoTableMap::COL_CRIADO_EM, $criadoEm['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(CertificadoRequerimentoTableMap::COL_CRIADO_EM, $criadoEm, $comparison);

        return $this;
    }

    /**
     * Filter the query on the avisado_em column
     *
     * Example usage:
     * <code>
     * $query->filterByAvisadoEm('2011-03-14'); // WHERE avisado_em = '2011-03-14'
     * $query->filterByAvisadoEm('now'); // WHERE avisado_em = '2011-03-14'
     * $query->filterByAvisadoEm(array('max' => 'yesterday')); // WHERE avisado_em > '2011-03-13'
     * </code>
     *
     * @param mixed $avisadoEm The value to use as filter.
     *              Values can be integers (unix timestamps), DateTime objects, or strings.
     *              Empty strings are treated as NULL.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByAvisadoEm($avisadoEm = null, ?string $comparison = null)
    {
        if (is_array($avisadoEm)) {
            $useMinMax = false;
            if (isset($avisadoEm['min'])) {
                $this->addUsingAlias(CertificadoRequerimentoTableMap::COL_AVISADO_EM, $avisadoEm['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($avisadoEm['max'])) {
                $this->addUsingAlias(CertificadoRequerimentoTableMap::COL_AVISADO_EM, $avisadoEm['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(CertificadoRequerimentoTableMap::COL_AVISADO_EM, $avisadoEm, $comparison);

        return $this;
    }

    /**
     * Filter the query on the status column
     *
     * @param mixed $status The value to use as filter
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByStatus($status = null, ?string $comparison = null)
    {
        $valueSet = CertificadoRequerimentoTableMap::getValueSet(CertificadoRequerimentoTableMap::COL_STATUS);
        if (is_scalar($status)) {
            if (!in_array($status, $valueSet)) {
                throw new PropelException(sprintf('Value "%s" is not accepted in this enumerated column', $status));
            }
            $status = array_search($status, $valueSet);
        } elseif (is_array($status)) {
            $convertedValues = [];
            foreach ($status as $value) {
                if (!in_array($value, $valueSet)) {
                    throw new PropelException(sprintf('Value "%s" is not accepted in this enumerated column', $value));
                }
                $convertedValues []= array_search($value, $valueSet);
            }
            $status = $convertedValues;
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(CertificadoRequerimentoTableMap::COL_STATUS, $status, $comparison);

        return $this;
    }

    /**
     * Filter the query on the resolvido_por column
     *
     * Example usage:
     * <code>
     * $query->filterByResolvidoPor(1234); // WHERE resolvido_por = 1234
     * $query->filterByResolvidoPor(array(12, 34)); // WHERE resolvido_por IN (12, 34)
     * $query->filterByResolvidoPor(array('min' => 12)); // WHERE resolvido_por > 12
     * </code>
     *
     * @see       filterByUser()
     *
     * @param mixed $resolvidoPor The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByResolvidoPor($resolvidoPor = null, ?string $comparison = null)
    {
        if (is_array($resolvidoPor)) {
            $useMinMax = false;
            if (isset($resolvidoPor['min'])) {
                $this->addUsingAlias(CertificadoRequerimentoTableMap::COL_RESOLVIDO_POR, $resolvidoPor['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($resolvidoPor['max'])) {
                $this->addUsingAlias(CertificadoRequerimentoTableMap::COL_RESOLVIDO_POR, $resolvidoPor['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(CertificadoRequerimentoTableMap::COL_RESOLVIDO_POR, $resolvidoPor, $comparison);

        return $this;
    }

    /**
     * Filter the query on the resolvido_em column
     *
     * Example usage:
     * <code>
     * $query->filterByResolvidoEm('2011-03-14'); // WHERE resolvido_em = '2011-03-14'
     * $query->filterByResolvidoEm('now'); // WHERE resolvido_em = '2011-03-14'
     * $query->filterByResolvidoEm(array('max' => 'yesterday')); // WHERE resolvido_em > '2011-03-13'
     * </code>
     *
     * @param mixed $resolvidoEm The value to use as filter.
     *              Values can be integers (unix timestamps), DateTime objects, or strings.
     *              Empty strings are treated as NULL.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByResolvidoEm($resolvidoEm = null, ?string $comparison = null)
    {
        if (is_array($resolvidoEm)) {
            $useMinMax = false;
            if (isset($resolvidoEm['min'])) {
                $this->addUsingAlias(CertificadoRequerimentoTableMap::COL_RESOLVIDO_EM, $resolvidoEm['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($resolvidoEm['max'])) {
                $this->addUsingAlias(CertificadoRequerimentoTableMap::COL_RESOLVIDO_EM, $resolvidoEm['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(CertificadoRequerimentoTableMap::COL_RESOLVIDO_EM, $resolvidoEm, $comparison);

        return $this;
    }

    /**
     * Filter the query on the resolucao column
     *
     * Example usage:
     * <code>
     * $query->filterByResolucao('fooValue');   // WHERE resolucao = 'fooValue'
     * $query->filterByResolucao('%fooValue%', Criteria::LIKE); // WHERE resolucao LIKE '%fooValue%'
     * $query->filterByResolucao(['foo', 'bar']); // WHERE resolucao IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $resolucao The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByResolucao($resolucao = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($resolucao)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(CertificadoRequerimentoTableMap::COL_RESOLUCAO, $resolucao, $comparison);

        return $this;
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
                ->addUsingAlias(CertificadoRequerimentoTableMap::COL_RESOLVIDO_POR, $user->getUserId(), $comparison);
        } elseif ($user instanceof ObjectCollection) {
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }

            $this
                ->addUsingAlias(CertificadoRequerimentoTableMap::COL_RESOLVIDO_POR, $user->toKeyValue('PrimaryKey', 'UserId'), $comparison);

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
     * @param ChildCertificadoRequerimento $certificadoRequerimento Object to remove from the list of results
     *
     * @return $this The current query, for fluid interface
     */
    public function prune($certificadoRequerimento = null)
    {
        if ($certificadoRequerimento) {
            $this->addUsingAlias(CertificadoRequerimentoTableMap::COL_REQUERIMENTO_ID, $certificadoRequerimento->getRequerimentoId(), Criteria::NOT_EQUAL);
        }

        return $this;
    }

    /**
     * Deletes all rows from the certificado_requerimento table.
     *
     * @param ConnectionInterface $con the connection to use
     * @return int The number of affected rows (if supported by underlying database driver).
     */
    public function doDeleteAll(?ConnectionInterface $con = null): int
    {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(CertificadoRequerimentoTableMap::DATABASE_NAME);
        }

        // use transaction because $criteria could contain info
        // for more than one table or we could emulating ON DELETE CASCADE, etc.
        return $con->transaction(function () use ($con) {
            $affectedRows = 0; // initialize var to track total num of affected rows
            $affectedRows += parent::doDeleteAll($con);
            // Because this db requires some delete cascade/set null emulation, we have to
            // clear the cached instance *after* the emulation has happened (since
            // instances get re-added by the select statement contained therein).
            CertificadoRequerimentoTableMap::clearInstancePool();
            CertificadoRequerimentoTableMap::clearRelatedInstancePool();

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
            $con = Propel::getServiceContainer()->getWriteConnection(CertificadoRequerimentoTableMap::DATABASE_NAME);
        }

        $criteria = $this;

        // Set the correct dbName
        $criteria->setDbName(CertificadoRequerimentoTableMap::DATABASE_NAME);

        // use transaction because $criteria could contain info
        // for more than one table or we could emulating ON DELETE CASCADE, etc.
        return $con->transaction(function () use ($con, $criteria) {
            $affectedRows = 0; // initialize var to track total num of affected rows

            CertificadoRequerimentoTableMap::removeInstanceFromPool($criteria);

            $affectedRows += ModelCriteria::delete($con);
            CertificadoRequerimentoTableMap::clearRelatedInstancePool();

            return $affectedRows;
        });
    }

}
