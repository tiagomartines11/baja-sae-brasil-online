<?php

namespace Baja\Certificado\Insercao;

/**
 * Which column of the paste is which field.
 *
 * A sheet is almost never in the order this system wants, and asking people
 * to rearrange their spreadsheet before pasting it is asking them to edit the
 * thing they are copying from. So the mapping moves instead.
 *
 * Two fields can be supplied for the whole paste rather than per row, and
 * that is the common case rather than a convenience: a sheet is usually one
 * event, and often one role.
 */
final class Mapeamento
{
    public const CAMPOS = ['evento', 'nome', 'funcao', 'documento'];

    /** Fields that may be supplied once for the whole paste instead of per row. */
    public const FIXAVEIS = ['evento', 'funcao'];

    /** @var array<int, string> column index => field name, '' meaning ignore */
    private array $colunas;

    /** @var array<string, string> field name => value for every row */
    private array $fixos;

    /**
     * @param array<int, string> $colunas
     * @param array<string, string> $fixos
     */
    public function __construct(array $colunas, array $fixos = [])
    {
        $this->colunas = [];
        foreach ($colunas as $indice => $campo) {
            $campo = (string) $campo;
            $this->colunas[(int) $indice] = in_array($campo, self::CAMPOS, true) ? $campo : '';
        }

        $this->fixos = [];
        foreach ($fixos as $campo => $valor) {
            if (in_array((string) $campo, self::FIXAVEIS, true) && (string) $valor !== '') {
                $this->fixos[(string) $campo] = (string) $valor;
            }
        }
    }

    /**
     * The order a sheet exported from this system would be in, and the one
     * the page starts on.
     */
    public static function padrao(int $largura): self
    {
        $colunas = [];
        foreach (range(0, max(0, $largura - 1)) as $indice) {
            $colunas[$indice] = self::CAMPOS[$indice] ?? '';
        }

        return new self($colunas);
    }

    /** @return array<int, string> */
    public function colunas(): array
    {
        return $this->colunas;
    }

    public function campoDaColuna(int $indice): string
    {
        return $this->colunas[$indice] ?? '';
    }

    /** @return array<string, string> */
    public function fixos(): array
    {
        return $this->fixos;
    }

    public function fixo(string $campo): string
    {
        return $this->fixos[$campo] ?? '';
    }

    /**
     * Fields that no column supplies and no page-level value covers.
     *
     * @return array<int, string>
     */
    public function faltando(): array
    {
        $cobertos = array_merge(array_values($this->colunas), array_keys($this->fixos));

        return array_values(array_diff(self::CAMPOS, $cobertos));
    }

    /**
     * Fields mapped to more than one column.
     *
     * Not a thing to resolve silently — whichever one won would be a guess,
     * and the other column's values would vanish without a word.
     *
     * @return array<int, string>
     */
    public function duplicados(): array
    {
        $contagem = array_count_values(array_filter(array_values($this->colunas)));

        return array_keys(array_filter($contagem, static fn (int $n): bool => $n > 1));
    }

    public function valido(): bool
    {
        return $this->faltando() === [] && $this->duplicados() === [];
    }

    /** How many columns a row is expected to have. */
    public function colunasEsperadas(): int
    {
        $indices = array_keys(array_filter($this->colunas));

        return $indices === [] ? 0 : max($indices) + 1;
    }

    /**
     * One parsed row, as the validator wants it.
     *
     * @param array<int, string> $celulas
     * @return array{evento: string, nome: string, funcao: string, documento: string}
     */
    public function aplicar(array $celulas): array
    {
        $valores = ['evento' => '', 'nome' => '', 'funcao' => '', 'documento' => ''];

        foreach ($this->colunas as $indice => $campo) {
            if ($campo !== '' && isset($celulas[$indice])) {
                $valores[$campo] = $celulas[$indice];
            }
        }

        // A page-level value fills a field no column supplies. It does not
        // override one that a column does supply — a mapped column is an
        // explicit statement about this sheet, and silently discarding it
        // would make the two settings disagree with no way to see which won.
        foreach ($this->fixos as $campo => $valor) {
            if (!in_array($campo, $this->colunas, true)) {
                $valores[$campo] = $valor;
            }
        }

        return $valores;
    }

    /**
     * Whether a row has the number of cells the mapping expects.
     *
     * Reported rather than padded. A short row usually means a cell contained
     * a tab or a line break, which shifts every field after it — so padding
     * it produces a row that validates cleanly and is about the wrong person.
     *
     * @param array<int, string> $celulas
     */
    public function ehIrregular(array $celulas): bool
    {
        return count($celulas) < $this->colunasEsperadas();
    }

    /** How this field is named to the user. */
    public static function rotulo(string $campo): string
    {
        return match ($campo) {
            'evento'    => 'Evento',
            'nome'      => 'Nome',
            'funcao'    => 'Função',
            'documento' => 'Documento',
            default     => 'Ignorar',
        };
    }
}
