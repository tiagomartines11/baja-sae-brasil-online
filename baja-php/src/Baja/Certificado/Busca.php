<?php

namespace Baja\Certificado;

use Baja\Model\Participante;
use Baja\Model\ParticipanteQuery;
use Propel\Runtime\ActiveQuery\Criteria;

/**
 * The /buscar lookup: a document number plus a name, in, a list of that
 * person's certificates out.
 */
final class Busca
{
    /**
     * Names used when the document matched nothing.
     *
     * The comparison runs against these so that "no such document" and
     * "document found, no name matched" execute the same code and take
     * comparable time. Without it the form answers "did this person compete?"
     * to anyone who asks, which is itself personal data — and is most of what
     * the CPF-in-the-URL scheme was giving away.
     */
    private const DUMMY_NAMES = [
        'Nome Improvavel Para Comparacao',
        'Outro Nome Improvavel Comparacao',
        'Terceiro Nome Improvavel Comparacao',
    ];

    /**
     * @return array<int, Certificado> every certificate the person may have,
     *                                 empty when nothing matched
     */
    public static function run(string $documento, string $nome): array
    {
        $rows      = self::rowsForDocument($documento);
        $isDecoy   = $rows === [];
        $storedSet = $isDecoy ? self::decoyRows() : $rows;


        /*
         * Match each row on its own, then keep everything if any one matched.
         *
         * Pooling the rows' tokens into one set would let a token from one
         * event combine with a token from another to match a name that neither
         * row carries. Filtering to only the rows that matched would be worse
         * in the other direction: a participant whose name was typed wrong for
         * one event silently loses a certificate they are entitled to, and
         * files a support ticket nobody can reproduce.
         *
         * The cost is that where a document genuinely carries two different
         * people's names, either can see the other's certificates. That is why
         * the multi-name documents are reviewed by hand before the backfill.
         */
        $matched = false;
        foreach ($storedSet as $row) {
            if (Nome::matches($nome, self::storedName($row))) {
                $matched = true;
            }
        }

        if ($isDecoy || !$matched) {
            return [];
        }

        $certificados = [];
        foreach ($rows as $row) {
            $certificado = Certificado::fromParticipante($row);
            if ($certificado !== null) {
                $certificados[] = $certificado;
            }
        }

        return $certificados;
    }

    /**
     * A wait, in words a person can act on.
     *
     * Rounded up, so the page never tells somebody to come back sooner than
     * it will actually let them.
     */
    public static function describeWait(int $seconds): string
    {
        if ($seconds < 60) {
            return 'alguns segundos';
        }

        $minutes = (int) ceil($seconds / 60);

        return $minutes === 1 ? '1 minuto' : $minutes . ' minutos';
    }

    /**
     * Every row filed under this document, in either column.
     *
     * Both are searched, and the person is never asked which kind of document
     * they hold. A selector's failure mode is a user who chooses correctly and
     * still gets nothing, because the row was filed under the other column —
     * and some rows are, since a passport number can pass the CPF check digits
     * by coincidence and a mistyped CPF fails them. Searching both absorbs
     * those misfilings instead of amplifying them, for one extra clause.
     *
     * Public because the insertion pages have to ask the same question — a
     * row they are about to create is a duplicate, or the name on it
     * disagrees with what is on file, only relative to the rows this document
     * already resolves to. Answering it a second time somewhere else would be
     * two rules that must agree and eventually would not.
     *
     * One query per document, so a caller holding a whole pasted sheet should
     * use rowMatches() against rows it fetched in bulk rather than calling
     * this in a loop — the foreign-side clause is a suffix match and cannot
     * use an index.
     *
     * @return array<int, Participante>
     */
    public static function rowsForDocument(string $documento): array
    {
        $cpf        = Documento::normalizeCpf($documento);
        $candidates = self::foreignCandidates($documento);

        if ($cpf === null && $candidates === []) {
            return [];
        }

        $digits = Documento::comparableEstrangeiro($documento);

        $query  = ParticipanteQuery::create();
        $needOr = false;

        if ($cpf !== null) {
            $query->filterByCpf($cpf);
            $needOr = true;
        }

        if ($candidates !== []) {
            if ($needOr) {
                $query->_or();
            }
            $query->filterByDocumentoEstrangeiro($candidates);
            $needOr = true;
        }

        /*
         * Suffix match, to reach a stored passport whose letters this
         * submission does not have (or vice versa). It cannot use an index,
         * which is why it is a prefilter rather than the answer: it is
         * deliberately wider than the rule, and rowMatchesDocument() below
         * applies the real comparison to what comes back. Foreign
         * participants are a small slice of the table.
         */
        if ($digits !== '') {
            if ($needOr) {
                $query->_or();
            }
            $query->filterByDocumentoEstrangeiro('%' . $digits, Criteria::LIKE);
        }

        // Voided certificates are not returned to anybody: not to /buscar, and
        // not to the insertion pages that ask this the same question. Leaving
        // them out of duplicate detection is deliberate — issuing the
        // certificate again is usually the reason one was voided.
        $query->filterByAnuladoEm(null, Criteria::ISNULL);

        $rows = iterator_to_array($query->orderByEventoId(Criteria::DESC)->find());

        return array_values(array_filter(
            $rows,
            static fn (Participante $row): bool => self::rowMatchesDocument($row, $cpf, $digits)
        ));
    }

    /**
     * Stored forms a foreign document could plausibly take.
     *
     * Comparison is meant to ignore leading zeros on both sides. Doing that in
     * SQL means a function on the column, which is neither indexable nor
     * expressible through the query API, so the variants are enumerated
     * instead: the value with every number of leading zeros that still fits
     * the column. The list is bounded by the column width, so nothing is
     * silently dropped, and every candidate hits the index.
     *
     * @return array<int, string>
     */
    private static function foreignCandidates(string $documento): array
    {
        $core = Documento::normalizeEstrangeiro($documento);

        if ($core === '') {
            return [];
        }

        $candidates = [];
        for ($zeros = 0; strlen($core) + $zeros <= 32; $zeros++) {
            $candidates[] = str_repeat('0', $zeros) . $core;
        }

        // Also as typed, in case a stored value carries punctuation the
        // normalization above removes.
        $asTyped = trim($documento);
        if ($asTyped !== '' && !in_array($asTyped, $candidates, true)) {
            $candidates[] = $asTyped;
        }

        return $candidates;
    }

    /**
     * Whether a row already in hand is filed under this document.
     *
     * The same rule rowsForDocument() applies to what its query returns,
     * for a caller that fetched its candidate rows some other way — which
     * the paste flow has to, since one query per row does not scale to a
     * sheet of two thousand.
     */
    public static function rowMatches(Participante $row, string $documento): bool
    {
        return self::rowMatchesDocument(
            $row,
            Documento::normalizeCpf($documento),
            Documento::comparableEstrangeiro($documento)
        );
    }

    /**
     * Whether a row really is filed under the submitted document.
     *
     * The query above is wider than the rule on the foreign side, because a
     * SQL suffix match is the only cheap way to reach a value whose letters
     * differ. This narrows it back: the digits must be equal, not merely
     * trailing, so a submitted 123456 does not resolve somebody recorded as
     * AB999123456.
     */
    private static function rowMatchesDocument(Participante $row, ?string $cpf, string $digits): bool
    {
        if ($cpf !== null && $row->getCpf() === $cpf) {
            return true;
        }

        $estrangeiro = (string) $row->getDocumentoEstrangeiro();

        return $digits !== ''
            && $estrangeiro !== ''
            && Documento::comparableEstrangeiro($estrangeiro) === $digits;
    }

    /**
     * The name on a row, as stored.
     *
     * trim() because the column has trailing-space contamination. Auditing how
     * much is awkward — `WHERE nome <> TRIM(nome)` matches zero rows under
     * this collation's PADSPACE comparison, so LENGTH() is the way — but for
     * matching it does not matter, since the tokenizer drops empty tokens.
     */
    private static function storedName(Participante $row): string
    {
        return trim((string) $row->getNome());
    }

    /** @return array<int, Participante> */
    private static function decoyRows(): array
    {
        $rows = [];
        foreach (self::DUMMY_NAMES as $name) {
            $row = new Participante();
            $row->setNome($name);
            $rows[] = $row;
        }

        return $rows;
    }
}
