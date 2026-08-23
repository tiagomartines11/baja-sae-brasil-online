<?php

namespace Baja\Certificado\Insercao;

/**
 * A paste out of a spreadsheet, turned into rows of cells.
 *
 * Excel puts tab-separated text on the clipboard, and it is not simply
 * "split on tabs and newlines": a cell containing a tab, a newline or a
 * double quote is wrapped in double quotes with the internal ones doubled.
 * A naive split turns one such cell into several rows, silently, and the
 * damage shows up as a participant whose name is half a document number.
 *
 * So it goes through a real CSV reader with the delimiter set to tab. The
 * escape character is disabled, because Excel escapes a quote by doubling it
 * and never with a backslash — leaving PHP's default backslash escape in
 * place corrupts any cell ending in one.
 */
final class Planilha
{
    /**
     * The row cap.
     *
     * Generous for the job: an event's whole participant list is a few
     * hundred. It is checked here and reported, rather than left to
     * post_max_size — which is 8M on this image and would answer an
     * over-large paste with an empty $_POST and no message at all. At roughly
     * eighty characters a row, two thousand rows is under 200 KB, so this cap
     * is what bites first and it bites with an explanation.
     */
    public const MAX_LINHAS = 2000;

    private function __construct(
        /** @var array<int, array<int, string>> */
        public readonly array $linhas,
        /** How many rows the paste actually held, before the cap. */
        public readonly int $total
    ) {
    }

    public function truncada(): bool
    {
        return $this->total > count($this->linhas);
    }

    public function vazia(): bool
    {
        return $this->linhas === [];
    }

    public static function analisar(string $texto): self
    {
        $texto = self::semBom($texto);

        if (trim($texto) === '') {
            return new self([], 0);
        }

        $fluxo = fopen('php://memory', 'r+');
        fwrite($fluxo, $texto);
        rewind($fluxo);

        $linhas = [];
        $total  = 0;

        while (($campos = fgetcsv($fluxo, 0, "\t", '"', '')) !== false) {
            // fgetcsv answers a blank line with [null]. A spreadsheet paste
            // ends in one, and may contain them where the user selected past
            // the end of their data.
            if ($campos === [null] || self::todosVazios($campos)) {
                continue;
            }

            $total++;

            if (count($linhas) < self::MAX_LINHAS) {
                $linhas[] = array_map(
                    static fn ($campo): string => Texto::limpar((string) $campo),
                    $campos
                );
            }
        }

        fclose($fluxo);

        return new self($linhas, $total);
    }

    /**
     * The widest row in the paste.
     *
     * Used to offer one mapping selector per column that actually exists,
     * rather than a fixed four.
     */
    public function largura(): int
    {
        $largura = 0;
        foreach ($this->linhas as $linha) {
            $largura = max($largura, count($linha));
        }

        return $largura;
    }

    /** @param array<int, string|null> $campos */
    private static function todosVazios(array $campos): bool
    {
        foreach ($campos as $campo) {
            if (Texto::limpar((string) $campo) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * A byte-order mark on the front of the paste.
     *
     * Windows clipboards produce one, and left in place it becomes part of
     * the first cell — usually the event code, which then matches no event
     * and reports as unknown with nothing visibly wrong about it.
     */
    private static function semBom(string $texto): string
    {
        return str_starts_with($texto, "\u{FEFF}") ? substr($texto, 3) : $texto;
    }
}
