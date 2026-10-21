<?php

namespace Baja\Model\Base;

use \Exception;
use \PDO;
use Baja\Model\ContagemArquivo as ChildContagemArquivo;
use Baja\Model\ContagemArquivoQuery as ChildContagemArquivoQuery;
use Baja\Model\Map\ContagemArquivoTableMap;
use Propel\Runtime\Propel;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\ModelCriteria;
use Propel\Runtime\Collection\Collection;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Exception\PropelException;

/**
 * Base class that represents a query for the `contagem_arquivo` table.
 *
 * @method     ChildContagemArquivoQuery orderByArquivoId($order = Criteria::ASC) Order by the arquivo_id column
 * @method     ChildContagemArquivoQuery orderByEventoId($order = Criteria::ASC) Order by the evento_id column
 * @method     ChildContagemArquivoQuery orderByProvaId($order = Criteria::ASC) Order by the prova_id column
 * @method     ChildContagemArquivoQuery orderByArquivadoEmMs($order = Criteria::ASC) Order by the arquivado_em_ms column
 * @method     ChildContagemArquivoQuery orderByArquivadoPor($order = Criteria::ASC) Order by the arquivado_por column
 * @method     ChildContagemArquivoQuery orderByTabela($order = Criteria::ASC) Order by the tabela column
 * @method     ChildContagemArquivoQuery orderByDados($order = Criteria::ASC) Order by the dados column
 *
 * @method     ChildContagemArquivoQuery groupByArquivoId() Group by the arquivo_id column
 * @method     ChildContagemArquivoQuery groupByEventoId() Group by the evento_id column
 * @method     ChildContagemArquivoQuery groupByProvaId() Group by the prova_id column
 * @method     ChildContagemArquivoQuery groupByArquivadoEmMs() Group by the arquivado_em_ms column
 * @method     ChildContagemArquivoQuery groupByArquivadoPor() Group by the arquivado_por column
 * @method     ChildContagemArquivoQuery groupByTabela() Group by the tabela column
 * @method     ChildContagemArquivoQuery groupByDados() Group by the dados column
 *
 * @method     ChildContagemArquivoQuery leftJoin($relation) Adds a LEFT JOIN clause to the query
 * @method     ChildContagemArquivoQuery rightJoin($relation) Adds a RIGHT JOIN clause to the query
 * @method     ChildContagemArquivoQuery innerJoin($relation) Adds a INNER JOIN clause to the query
 *
 * @method     ChildContagemArquivoQuery leftJoinWith($relation) Adds a LEFT JOIN clause and with to the query
 * @method     ChildContagemArquivoQuery rightJoinWith($relation) Adds a RIGHT JOIN clause and with to the query
 * @method     ChildContagemArquivoQuery innerJoinWith($relation) Adds a INNER JOIN clause and with to the query
 *
 * @method     ChildContagemArquivo|null findOne(?ConnectionInterface $con = null) Return the first ChildContagemArquivo matching the query
 * @method     ChildContagemArquivo findOneOrCreate(?ConnectionInterface $con = null) Return the first ChildContagemArquivo matching the query, or a new ChildContagemArquivo object populated from the query conditions when no match is found
 *
 * @method     ChildContagemArquivo|null findOneByArquivoId(string $arquivo_id) Return the first ChildContagemArquivo filtered by the arquivo_id column
 * @method     ChildContagemArquivo|null findOneByEventoId(string $evento_id) Return the first ChildContagemArquivo filtered by the evento_id column
 * @method     ChildContagemArquivo|null findOneByProvaId(string $prova_id) Return the first ChildContagemArquivo filtered by the prova_id column
 * @method     ChildContagemArquivo|null findOneByArquivadoEmMs(string $arquivado_em_ms) Return the first ChildContagemArquivo filtered by the arquivado_em_ms column
 * @method     ChildContagemArquivo|null findOneByArquivadoPor(string $arquivado_por) Return the first ChildContagemArquivo filtered by the arquivado_por column
 * @method     ChildContagemArquivo|null findOneByTabela(string $tabela) Return the first ChildContagemArquivo filtered by the tabela column
 * @method     ChildContagemArquivo|null findOneByDados(string $dados) Return the first ChildContagemArquivo filtered by the dados column
 *
 * @method     ChildContagemArquivo requirePk($key, ?ConnectionInterface $con = null) Return the ChildContagemArquivo by primary key and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemArquivo requireOne(?ConnectionInterface $con = null) Return the first ChildContagemArquivo matching the query and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 *
 * @method     ChildContagemArquivo requireOneByArquivoId(string $arquivo_id) Return the first ChildContagemArquivo filtered by the arquivo_id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemArquivo requireOneByEventoId(string $evento_id) Return the first ChildContagemArquivo filtered by the evento_id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemArquivo requireOneByProvaId(string $prova_id) Return the first ChildContagemArquivo filtered by the prova_id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemArquivo requireOneByArquivadoEmMs(string $arquivado_em_ms) Return the first ChildContagemArquivo filtered by the arquivado_em_ms column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemArquivo requireOneByArquivadoPor(string $arquivado_por) Return the first ChildContagemArquivo filtered by the arquivado_por column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemArquivo requireOneByTabela(string $tabela) Return the first ChildContagemArquivo filtered by the tabela column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemArquivo requireOneByDados(string $dados) Return the first ChildContagemArquivo filtered by the dados column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 *
 * @method     ChildContagemArquivo[]|Collection find(?ConnectionInterface $con = null) Return ChildContagemArquivo objects based on current ModelCriteria
 * @psalm-method Collection&\Traversable<ChildContagemArquivo> find(?ConnectionInterface $con = null) Return ChildContagemArquivo objects based on current ModelCriteria
 *
 * @method     ChildContagemArquivo[]|Collection findByArquivoId(string|array<string> $arquivo_id) Return ChildContagemArquivo objects filtered by the arquivo_id column
 * @psalm-method Collection&\Traversable<ChildContagemArquivo> findByArquivoId(string|array<string> $arquivo_id) Return ChildContagemArquivo objects filtered by the arquivo_id column
 * @method     ChildContagemArquivo[]|Collection findByEventoId(string|array<string> $evento_id) Return ChildContagemArquivo objects filtered by the evento_id column
 * @psalm-method Collection&\Traversable<ChildContagemArquivo> findByEventoId(string|array<string> $evento_id) Return ChildContagemArquivo objects filtered by the evento_id column
 * @method     ChildContagemArquivo[]|Collection findByProvaId(string|array<string> $prova_id) Return ChildContagemArquivo objects filtered by the prova_id column
 * @psalm-method Collection&\Traversable<ChildContagemArquivo> findByProvaId(string|array<string> $prova_id) Return ChildContagemArquivo objects filtered by the prova_id column
 * @method     ChildContagemArquivo[]|Collection findByArquivadoEmMs(string|array<string> $arquivado_em_ms) Return ChildContagemArquivo objects filtered by the arquivado_em_ms column
 * @psalm-method Collection&\Traversable<ChildContagemArquivo> findByArquivadoEmMs(string|array<string> $arquivado_em_ms) Return ChildContagemArquivo objects filtered by the arquivado_em_ms column
 * @method     ChildContagemArquivo[]|Collection findByArquivadoPor(string|array<string> $arquivado_por) Return ChildContagemArquivo objects filtered by the arquivado_por column
 * @psalm-method Collection&\Traversable<ChildContagemArquivo> findByArquivadoPor(string|array<string> $arquivado_por) Return ChildContagemArquivo objects filtered by the arquivado_por column
 * @method     ChildContagemArquivo[]|Collection findByTabela(string|array<string> $tabela) Return ChildContagemArquivo objects filtered by the tabela column
 * @psalm-method Collection&\Traversable<ChildContagemArquivo> findByTabela(string|array<string> $tabela) Return ChildContagemArquivo objects filtered by the tabela column
 * @method     ChildContagemArquivo[]|Collection findByDados(string|array<string> $dados) Return ChildContagemArquivo objects filtered by the dados column
 * @psalm-method Collection&\Traversable<ChildContagemArquivo> findByDados(string|array<string> $dados) Return ChildContagemArquivo objects filtered by the dados column
 *
 * @method     ChildContagemArquivo[]|\Propel\Runtime\Util\PropelModelPager paginate($page = 1, $maxPerPage = 10, ?ConnectionInterface $con = null) Issue a SELECT query based on the current ModelCriteria and uses a page and a maximum number of results per page to compute an offset and a limit
 * @psalm-method \Propel\Runtime\Util\PropelModelPager&\Traversable<ChildContagemArquivo> paginate($page = 1, $maxPerPage = 10, ?ConnectionInterface $con = null) Issue a SELECT query based on the current ModelCriteria and uses a page and a maximum number of results per page to compute an offset and a limit
 */
abstract class ContagemArquivoQuery extends ModelCriteria
{
    protected $entityNotFoundExceptionClass = '\\Propel\\Runtime\\Exception\\EntityNotFoundException';

    /**
     * Initializes internal state of \Baja\Model\Base\ContagemArquivoQuery object.
     *
     * @param string $dbName The database name
     * @param string $modelName The phpName of a model, e.g. 'Book'
     * @param string $modelAlias The alias for the model in this query, e.g. 'b'
     */
    public function __construct($dbName = 'resultados', $modelName = '\\Baja\\Model\\ContagemArquivo', $modelAlias = null)
    {
        parent::__construct($dbName, $modelName, $modelAlias);
    }

    /**
     * Returns a new ChildContagemArquivoQuery object.
     *
     * @param string $modelAlias The alias of a model in the query
     * @param Criteria $criteria Optional Criteria to build the query from
     *
     * @return ChildContagemArquivoQuery
     */
    public static function create(?string $modelAlias = null, ?Criteria $criteria = null): Criteria
    {
        if ($criteria instanceof ChildContagemArquivoQuery) {
            return $criteria;
        }
        $query = new ChildContagemArquivoQuery();
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
     * @return ChildContagemArquivo|array|mixed the result, formatted by the current formatter
     */
    public function findPk($key, ?ConnectionInterface $con = null)
    {
        if ($key === null) {
            return null;
        }

        if ($con === null) {
            $con = Propel::getServiceContainer()->getReadConnection(ContagemArquivoTableMap::DATABASE_NAME);
        }

        $this->basePreSelect($con);

        if (
            $this->formatter || $this->modelAlias || $this->with || $this->select
            || $this->selectColumns || $this->asColumns || $this->selectModifiers
            || $this->map || $this->having || $this->joins
        ) {
            return $this->findPkComplex($key, $con);
        }

        if ((null !== ($obj = ContagemArquivoTableMap::getInstanceFromPool(null === $key || is_scalar($key) || is_callable([$key, '__toString']) ? (string) $key : $key)))) {
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
     * @return ChildContagemArquivo A model object, or null if the key is not found
     */
    protected function findPkSimple($key, ConnectionInterface $con)
    {
        $sql = 'SELECT arquivo_id, evento_id, prova_id, arquivado_em_ms, arquivado_por, tabela, dados FROM contagem_arquivo WHERE arquivo_id = :p0';
        try {
            $stmt = $con->prepare($sql);
            $stmt->bindValue(':p0', $key, PDO::PARAM_INT);
            $stmt->execute();
        } catch (Exception $e) {
            Propel::log($e->getMessage(), Propel::LOG_ERR);
            throw new PropelException(sprintf('Unable to execute SELECT statement [%s]', $sql), 0, $e);
        }
        $obj = null;
        if ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
            /** @var ChildContagemArquivo $obj */
            $obj = new ChildContagemArquivo();
            $obj->hydrate($row);
            ContagemArquivoTableMap::addInstanceToPool($obj, null === $key || is_scalar($key) || is_callable([$key, '__toString']) ? (string) $key : $key);
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
     * @return ChildContagemArquivo|array|mixed the result, formatted by the current formatter
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

        $this->addUsingAlias(ContagemArquivoTableMap::COL_ARQUIVO_ID, $key, Criteria::EQUAL);

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

        $this->addUsingAlias(ContagemArquivoTableMap::COL_ARQUIVO_ID, $keys, Criteria::IN);

        return $this;
    }

    /**
     * Filter the query on the arquivo_id column
     *
     * Example usage:
     * <code>
     * $query->filterByArquivoId(1234); // WHERE arquivo_id = 1234
     * $query->filterByArquivoId(array(12, 34)); // WHERE arquivo_id IN (12, 34)
     * $query->filterByArquivoId(array('min' => 12)); // WHERE arquivo_id > 12
     * </code>
     *
     * @param mixed $arquivoId The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByArquivoId($arquivoId = null, ?string $comparison = null)
    {
        if (is_array($arquivoId)) {
            $useMinMax = false;
            if (isset($arquivoId['min'])) {
                $this->addUsingAlias(ContagemArquivoTableMap::COL_ARQUIVO_ID, $arquivoId['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($arquivoId['max'])) {
                $this->addUsingAlias(ContagemArquivoTableMap::COL_ARQUIVO_ID, $arquivoId['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemArquivoTableMap::COL_ARQUIVO_ID, $arquivoId, $comparison);

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

        $this->addUsingAlias(ContagemArquivoTableMap::COL_EVENTO_ID, $eventoId, $comparison);

        return $this;
    }

    /**
     * Filter the query on the prova_id column
     *
     * Example usage:
     * <code>
     * $query->filterByProvaId('fooValue');   // WHERE prova_id = 'fooValue'
     * $query->filterByProvaId('%fooValue%', Criteria::LIKE); // WHERE prova_id LIKE '%fooValue%'
     * $query->filterByProvaId(['foo', 'bar']); // WHERE prova_id IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $provaId The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByProvaId($provaId = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($provaId)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemArquivoTableMap::COL_PROVA_ID, $provaId, $comparison);

        return $this;
    }

    /**
     * Filter the query on the arquivado_em_ms column
     *
     * Example usage:
     * <code>
     * $query->filterByArquivadoEmMs(1234); // WHERE arquivado_em_ms = 1234
     * $query->filterByArquivadoEmMs(array(12, 34)); // WHERE arquivado_em_ms IN (12, 34)
     * $query->filterByArquivadoEmMs(array('min' => 12)); // WHERE arquivado_em_ms > 12
     * </code>
     *
     * @param mixed $arquivadoEmMs The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByArquivadoEmMs($arquivadoEmMs = null, ?string $comparison = null)
    {
        if (is_array($arquivadoEmMs)) {
            $useMinMax = false;
            if (isset($arquivadoEmMs['min'])) {
                $this->addUsingAlias(ContagemArquivoTableMap::COL_ARQUIVADO_EM_MS, $arquivadoEmMs['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($arquivadoEmMs['max'])) {
                $this->addUsingAlias(ContagemArquivoTableMap::COL_ARQUIVADO_EM_MS, $arquivadoEmMs['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemArquivoTableMap::COL_ARQUIVADO_EM_MS, $arquivadoEmMs, $comparison);

        return $this;
    }

    /**
     * Filter the query on the arquivado_por column
     *
     * Example usage:
     * <code>
     * $query->filterByArquivadoPor('fooValue');   // WHERE arquivado_por = 'fooValue'
     * $query->filterByArquivadoPor('%fooValue%', Criteria::LIKE); // WHERE arquivado_por LIKE '%fooValue%'
     * $query->filterByArquivadoPor(['foo', 'bar']); // WHERE arquivado_por IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $arquivadoPor The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByArquivadoPor($arquivadoPor = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($arquivadoPor)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemArquivoTableMap::COL_ARQUIVADO_POR, $arquivadoPor, $comparison);

        return $this;
    }

    /**
     * Filter the query on the tabela column
     *
     * Example usage:
     * <code>
     * $query->filterByTabela('fooValue');   // WHERE tabela = 'fooValue'
     * $query->filterByTabela('%fooValue%', Criteria::LIKE); // WHERE tabela LIKE '%fooValue%'
     * $query->filterByTabela(['foo', 'bar']); // WHERE tabela IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $tabela The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByTabela($tabela = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($tabela)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemArquivoTableMap::COL_TABELA, $tabela, $comparison);

        return $this;
    }

    /**
     * Filter the query on the dados column
     *
     * Example usage:
     * <code>
     * $query->filterByDados('fooValue');   // WHERE dados = 'fooValue'
     * $query->filterByDados('%fooValue%', Criteria::LIKE); // WHERE dados LIKE '%fooValue%'
     * $query->filterByDados(['foo', 'bar']); // WHERE dados IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $dados The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByDados($dados = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($dados)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemArquivoTableMap::COL_DADOS, $dados, $comparison);

        return $this;
    }

    /**
     * Exclude object from result
     *
     * @param ChildContagemArquivo $contagemArquivo Object to remove from the list of results
     *
     * @return $this The current query, for fluid interface
     */
    public function prune($contagemArquivo = null)
    {
        if ($contagemArquivo) {
            $this->addUsingAlias(ContagemArquivoTableMap::COL_ARQUIVO_ID, $contagemArquivo->getArquivoId(), Criteria::NOT_EQUAL);
        }

        return $this;
    }

    /**
     * Deletes all rows from the contagem_arquivo table.
     *
     * @param ConnectionInterface $con the connection to use
     * @return int The number of affected rows (if supported by underlying database driver).
     */
    public function doDeleteAll(?ConnectionInterface $con = null): int
    {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(ContagemArquivoTableMap::DATABASE_NAME);
        }

        // use transaction because $criteria could contain info
        // for more than one table or we could emulating ON DELETE CASCADE, etc.
        return $con->transaction(function () use ($con) {
            $affectedRows = 0; // initialize var to track total num of affected rows
            $affectedRows += parent::doDeleteAll($con);
            // Because this db requires some delete cascade/set null emulation, we have to
            // clear the cached instance *after* the emulation has happened (since
            // instances get re-added by the select statement contained therein).
            ContagemArquivoTableMap::clearInstancePool();
            ContagemArquivoTableMap::clearRelatedInstancePool();

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
            $con = Propel::getServiceContainer()->getWriteConnection(ContagemArquivoTableMap::DATABASE_NAME);
        }

        $criteria = $this;

        // Set the correct dbName
        $criteria->setDbName(ContagemArquivoTableMap::DATABASE_NAME);

        // use transaction because $criteria could contain info
        // for more than one table or we could emulating ON DELETE CASCADE, etc.
        return $con->transaction(function () use ($con, $criteria) {
            $affectedRows = 0; // initialize var to track total num of affected rows

            ContagemArquivoTableMap::removeInstanceFromPool($criteria);

            $affectedRows += ModelCriteria::delete($con);
            ContagemArquivoTableMap::clearRelatedInstancePool();

            return $affectedRows;
        });
    }

}
