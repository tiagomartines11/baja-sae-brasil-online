<?php

namespace Baja\Certificado\Insercao;

use Baja\Model\ParticipanteQuery;
use Baja\Model\UserQuery;
use Propel\Runtime\ActiveQuery\Criteria;

/**
 * Every batch, so that one can be found without having kept its identifier.
 *
 * The batch id was already what makes a bad paste identifiable afterwards,
 * but until now it was identifiable only to somebody who had copied it off
 * the success page. An audit trail nobody can navigate to is one in name
 * only.
 */
final class Lotes
{
    public const POR_PAGINA = 50;

    public function __construct(
        /** @var array<int, string> event codes; a batch matches if any of its rows is in one */
        private array $eventos = [],
        /** User id, or 0 for anybody. */
        private int $autor = 0,
        private string $id = ''
    ) {
    }

    public function temFiltro(): bool
    {
        return $this->eventos !== [] || $this->autor > 0 || Padrao::paraTexto($this->id) !== null;
    }

    public function total(): int
    {
        $ids = $this->idsFiltrados();

        if ($ids !== null) {
            return count($ids);
        }

        return ParticipanteQuery::create()
            ->filterByLoteId(null, Criteria::ISNOTNULL)
            ->select(['LoteId'])
            ->groupBy('LoteId')
            ->count();
    }

    public function paginas(int $total): int
    {
        return max(1, (int) ceil($total / self::POR_PAGINA));
    }

    /**
     * Rows belonging to no batch at all.
     *
     * Shown so the numbers reconcile. These predate the audit columns and have
     * nothing to backfill from — a fact about the table rather than something
     * to fix.
     */
    public static function semLote(): int
    {
        return ParticipanteQuery::create()->filterByLoteId(null, Criteria::ISNULL)->count();
    }

    /**
     * One page of batches, newest first.
     *
     * @return array<int, array{id: string, linhas: int, anuladas: int, criado_em: ?string, eventos: array<int,string>, autores: array<int,int>}>
     */
    public function pagina(int $pagina): array
    {
        $ids = $this->idsFiltrados();

        if ($ids === []) {
            return [];
        }

        $query = ParticipanteQuery::create()->filterByLoteId(null, Criteria::ISNOTNULL);

        if ($ids !== null) {
            $query->filterByLoteId($ids, Criteria::IN);
        }

        $linhas = $query
            ->select(['LoteId'])
            ->withColumn('COUNT(*)', 'linhas')
            // The earliest stamp in the batch. criado_em is restamped on a row
            // whose name is corrected or whose voiding is undone, so the
            // minimum stays closest to when the batch was actually created.
            // Aliased `primeiro` rather than `criado_em`: Propel resolves an
            // alias that matches a real column back to that column, so
            // ordering by it would sort on an ungrouped criado_em and MySQL
            // refuses it outright under ONLY_FULL_GROUP_BY.
            ->withColumn('MIN(participantes.criado_em)', 'primeiro')
            // COUNT over a nullable column counts the non-nulls, which is
            // exactly how many rows of the batch are void.
            ->withColumn('COUNT(participantes.anulado_em)', 'anuladas')
            ->withColumn('GROUP_CONCAT(DISTINCT participantes.evento ORDER BY participantes.evento)', 'eventos')
            ->withColumn('GROUP_CONCAT(DISTINCT participantes.criado_por)', 'autores')
            ->groupBy('LoteId')
            ->orderBy('primeiro', Criteria::DESC)
            // A tiebreaker, and not a cosmetic one. criado_em is a DATETIME,
            // so two batches created in the same second sort equally and MySQL
            // may return them in either order — between one page request and
            // the next, which is how a batch gets shown twice or skipped
            // entirely. The id is unique, so this makes the order total.
            ->orderBy('LoteId', Criteria::DESC)
            ->offset(max(0, $pagina - 1) * self::POR_PAGINA)
            ->limit(self::POR_PAGINA)
            ->find();

        $saida = [];
        foreach ($linhas as $linha) {
            $saida[] = [
                'id'        => (string) $linha['LoteId'],
                'linhas'    => (int) $linha['linhas'],
                'anuladas'  => (int) $linha['anuladas'],
                'criado_em' => $linha['primeiro'] !== null ? (string) $linha['primeiro'] : null,
                'eventos'   => self::separar((string) ($linha['eventos'] ?? '')),
                'autores'   => array_map('intval', self::separar((string) ($linha['autores'] ?? ''))),
            ];
        }

        return $saida;
    }

    /**
     * The batches the filters admit, or null for "no filter, every batch".
     *
     * Two queries rather than a subquery, for a correctness reason and not a
     * stylistic one: filtering rows and then grouping them would count only
     * the matching rows, so a batch of forty-nine narrowed to one event would
     * report as forty-eight. The filter chooses which batches to show; the
     * counts are always of the whole batch.
     *
     * @return array<int, string>|null
     */
    private function idsFiltrados(): ?array
    {
        if (!$this->temFiltro()) {
            return null;
        }

        $query = ParticipanteQuery::create()->filterByLoteId(null, Criteria::ISNOTNULL);

        if ($this->eventos !== []) {
            $query->filterByEventoId($this->eventos, Criteria::IN);
        }

        if ($this->autor > 0) {
            $query->filterByCriadoPor($this->autor);
        }

        $padrao = Padrao::paraTexto($this->id);
        if ($padrao !== null) {
            $query->where('participantes.lote_id LIKE ?', $padrao);
        }

        $ids = [];
        foreach ($query->select(['LoteId'])->groupBy('LoteId')->find() as $valor) {
            $ids[] = (string) (is_array($valor) ? $valor['LoteId'] : $valor);
        }

        return $ids;
    }

    /**
     * Users who have created at least one batch, for the author filter.
     *
     * Built from the batches rather than from the users table: offering every
     * account in the system, almost none of which has issued anything, is a
     * longer list and a less useful one.
     *
     * @return array<int, string> user id => username
     */
    public static function autores(): array
    {
        $ids = [];
        foreach (
            ParticipanteQuery::create()
                ->filterByLoteId(null, Criteria::ISNOTNULL)
                ->filterByCriadoPor(null, Criteria::ISNOTNULL)
                ->select(['CriadoPor'])
                ->groupBy('CriadoPor')
                ->find() as $valor
        ) {
            $ids[] = (int) (is_array($valor) ? $valor['CriadoPor'] : $valor);
        }

        return self::nomesDeUsuario($ids);
    }

    /**
     * @param array<int, int> $ids
     * @return array<int, string> user id => username
     */
    public static function nomesDeUsuario(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));

        if ($ids === []) {
            return [];
        }

        $nomes = [];
        foreach (UserQuery::create()->filterByUserId($ids, Criteria::IN)->find() as $user) {
            $nomes[(int) $user->getUserId()] = (string) $user->getUsername();
        }

        return $nomes;
    }

    /** @return array<int, string> */
    private static function separar(string $concatenado): array
    {
        return $concatenado === '' ? [] : explode(',', $concatenado);
    }
}
