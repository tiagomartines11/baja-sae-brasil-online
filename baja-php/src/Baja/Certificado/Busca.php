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

        $submitted = Nome::normalize($nome);

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
            if (Nome::matches($submitted, Nome::normalize(self::storedName($row)))) {
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
     * Every row filed under this document, in either column.
     *
     * Both are searched, and the person is never asked which kind of document
     * they hold. A selector's failure mode is a user who chooses correctly and
     * still gets nothing, because the row was filed under the other column —
     * and some rows are, since a passport number can pass the CPF check digits
     * by coincidence and a mistyped CPF fails them. Searching both absorbs
     * those misfilings instead of amplifying them, for one extra clause.
     *
     * @return array<int, Participante>
     */
    private static function rowsForDocument(string $documento): array
    {
        $cpf        = Documento::normalizeCpf($documento);
        $candidates = self::foreignCandidates($documento);

        if ($cpf === null && $candidates === []) {
            return [];
        }

        $query = ParticipanteQuery::create();

        if ($cpf !== null) {
            $query->filterByCpf($cpf);
        }

        if ($cpf !== null && $candidates !== []) {
            $query->_or();
        }

        if ($candidates !== []) {
            $query->filterByDocumentoEstrangeiro($candidates);
        }

        return iterator_to_array($query->orderByEventoId(Criteria::DESC)->find());
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
