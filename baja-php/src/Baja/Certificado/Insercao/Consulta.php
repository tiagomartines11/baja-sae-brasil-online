<?php

namespace Baja\Certificado\Insercao;

use Baja\Model\Participante;
use Baja\Model\ParticipanteQuery;
use Propel\Runtime\ActiveQuery\Criteria;

/**
 * Looking up certificates that already exist.
 *
 * Deliberately the opposite of /buscar. That page is public, so it demands a
 * document and a name together, compares against decoys to keep its failures
 * indistinguishable, and is rate-limited — because answering "did this person
 * compete?" to anyone who asks is itself a disclosure. This one is behind
 * `certificados`, and its whole purpose is to let somebody who is allowed to
 * see the table browse it. Any filter, or none.
 *
 * The one habit it keeps from that work: a document number never travels in a
 * URL. The page posts, so nothing here reaches an access log.
 */
final class Consulta
{
    /**
     * Rows per page.
     *
     * The table has no index on any of the searchable columns, so every
     * search is a scan whatever this is set to; the cap is about the size of
     * the page rendered, not the cost of the query.
     */
    public const POR_PAGINA = 100;

    /**
     * `nome` with apostrophes removed, matching what Padrao does to the term.
     *
     * CHAR(39) is the straight apostrophe. CHAR(146) is the curly one: the
     * column is latin1, MySQL's latin1 is cp1252, and U+2019 lives at 0x92
     * there. Written as a code point rather than a literal so that the file's
     * own encoding cannot change what this means.
     *
     * Accents and case are absent from this expression on purpose — the
     * collation already ignores both, and adding a fold here would be a
     * second rule that has to agree with it.
     */
    private const NOME_NORMALIZADO = 'REPLACE(REPLACE(participantes.nome, CHAR(39), ""), CHAR(146), "")';

    /**
     * `documento_estrangeiro` with the separators people actually use removed.
     *
     * Not a general strip — SQL has no such function without a REPLACE per
     * character — but these four cover every stored form seen so far. A
     * passport punctuated some other way is still findable by searching a
     * fragment of it, which is what the wildcard is for.
     */
    private const ESTRANGEIRO_NORMALIZADO =
        'REPLACE(REPLACE(REPLACE(REPLACE(participantes.documento_estrangeiro, "-", ""), ".", ""), " ", ""), "/", "")';

    public const DOC_CPF        = 'cpf';
    public const DOC_PASSAPORTE = 'passaporte';
    public const DOC_AMBOS      = 'ambos';

    public function __construct(
        /** @var array<int, string> event codes; empty means every event */
        private array $eventos = [],
        /** @var array<int, string> funcao codes; empty means every role */
        private array $funcoes = [],
        private string $nome = '',
        private string $documento = '',
        private string $tipoDocumento = self::DOC_AMBOS
    ) {
    }

    /**
     * A document was typed, and the chosen column cannot use it.
     *
     * "AB" restricted to CPF, or "12345" restricted to passport once the
     * digits are all that is left of a term with no letters — the pattern
     * comes out empty for that column. Dropping the filter and searching on
     * whatever else was given would answer a question nobody asked, and would
     * do it by returning *more* rows than the operator expected, which is the
     * worst direction for a mistake like this to go.
     */
    public function documentoImpossivel(): bool
    {
        return Texto::limpar($this->documento) !== ''
            && Padrao::paraNome($this->documento) !== null
            && $this->padraoDocumento() === [];
    }

    /**
     * Whether anything was actually asked.
     *
     * An unfiltered search is legitimate — browsing is the point — but the
     * page says so rather than presenting the first hundred rows of the table
     * as though they were results.
     */
    public function temFiltro(): bool
    {
        return $this->eventos !== []
            || $this->funcoes !== []
            || Padrao::paraNome($this->nome) !== null
            || $this->padraoDocumento() !== []
            || $this->documentoImpossivel();
    }

    /** The document term as typed, for a message that quotes it back. */
    public function documento(): string
    {
        return Texto::limpar($this->documento);
    }

    public function tipoDocumento(): string
    {
        return $this->tipoDocumento;
    }

    public function total(): int
    {
        return $this->query()->count();
    }

    /**
     * @return array<int, Participante>
     */
    public function pagina(int $pagina): array
    {
        $pagina = max(1, $pagina);

        return iterator_to_array(
            $this->query()
                ->orderByEventoId(Criteria::DESC)
                ->orderByNome()
                ->offset(($pagina - 1) * self::POR_PAGINA)
                ->limit(self::POR_PAGINA)
                ->find(),
            false
        );
    }

    public function paginas(int $total): int
    {
        return max(1, (int) ceil($total / self::POR_PAGINA));
    }

    private function query(): ParticipanteQuery
    {
        $query = ParticipanteQuery::create();

        // An unusable document term matches nothing, rather than quietly
        // becoming no filter at all. The page says why.
        if ($this->documentoImpossivel()) {
            return $query->where('1 = 0');
        }

        if ($this->eventos !== []) {
            $query->filterByEventoId($this->eventos, Criteria::IN);
        }

        if ($this->funcoes !== []) {
            $query->filterByFuncao($this->funcoes, Criteria::IN);
        }

        $nome = Padrao::paraNome($this->nome);
        if ($nome !== null) {
            $query->where(self::NOME_NORMALIZADO . ' LIKE ?', $nome);
        }

        $documento = $this->padraoDocumento();

        if (count($documento) === 2) {
            // One term, two columns, and a row matching either is a hit. The
            // two patterns differ: the CPF side is digits only and the
            // passport side keeps its letters, so "AB123" searches the
            // passport column for AB123 and the CPF column for 123.
            $query->where(
                '(participantes.cpf LIKE ? OR ' . self::ESTRANGEIRO_NORMALIZADO . ' LIKE ?)',
                [$documento[self::DOC_CPF], $documento[self::DOC_PASSAPORTE]]
            );
        } elseif (isset($documento[self::DOC_CPF])) {
            $query->where('participantes.cpf LIKE ?', $documento[self::DOC_CPF]);
        } elseif (isset($documento[self::DOC_PASSAPORTE])) {
            $query->where(self::ESTRANGEIRO_NORMALIZADO . ' LIKE ?', $documento[self::DOC_PASSAPORTE]);
        }

        return $query;
    }

    /**
     * The document patterns this search should apply, keyed by column.
     *
     * A term can produce a pattern for one column and not the other — "AB123"
     * has no digits-only reading worth searching once the letters are gone,
     * and "5299" has no letters to look for. Whichever survives is used.
     *
     * @return array<string, string>
     */
    private function padraoDocumento(): array
    {
        $padroes = [];

        if ($this->tipoDocumento !== self::DOC_PASSAPORTE) {
            $cpf = Padrao::paraCpf($this->documento);
            if ($cpf !== null) {
                $padroes[self::DOC_CPF] = $cpf;
            }
        }

        if ($this->tipoDocumento !== self::DOC_CPF) {
            $passaporte = Padrao::paraPassaporte($this->documento);
            if ($passaporte !== null) {
                $padroes[self::DOC_PASSAPORTE] = $passaporte;
            }
        }

        return $padroes;
    }
}
