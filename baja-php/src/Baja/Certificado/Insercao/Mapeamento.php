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
    /**
     * Every field a column can be mapped to.
     *
     * Three of them are documents, because a real sheet is as likely to have
     * separate CPF and Passaporte columns — one filled per person — as a
     * single mixed one. Mapping the specific columns is not just convenience:
     * it states which kind a value is, which is the one thing the value
     * cannot always state for itself.
     */
    public const CAMPOS = ['evento', 'nome', 'funcao', 'documento', 'cpf', 'passaporte'];

    /** The three that all answer "which document", of which at least one is needed. */
    public const CAMPOS_DOCUMENTO = ['documento', 'cpf', 'passaporte'];

    /** Fields that may be supplied once for the whole paste instead of per row. */
    public const FIXAVEIS = ['evento', 'funcao'];

    /** Fields every row needs, one way or another. */
    private const OBRIGATORIOS = ['evento', 'nome', 'funcao'];

    /**
     * The order the page starts on, which is not the same list as CAMPOS.
     *
     * CAMPOS gained cpf and passaporte, and defaulting a six-column sheet's
     * last two columns to them would map a document three ways at once and
     * open on an error. The starting guess stays the four-column shape a
     * sheet exported from here has; anything wider starts ignored.
     */
    private const PADRAO = ['evento', 'nome', 'funcao', 'documento'];

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
            $colunas[$indice] = self::PADRAO[$indice] ?? '';
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
     * A document counts as covered by any of the three columns that can carry
     * one, so a sheet with only a Passaporte column is complete.
     *
     * @return array<int, string>
     */
    public function faltando(): array
    {
        $cobertos = array_merge(array_values($this->colunas), array_keys($this->fixos));

        $faltando = array_values(array_diff(self::OBRIGATORIOS, $cobertos));

        if (array_intersect(self::CAMPOS_DOCUMENTO, $cobertos) === []) {
            $faltando[] = 'documento';
        }

        return $faltando;
    }

    /**
     * A generic document column mapped alongside a specific one.
     *
     * Refused rather than resolved. "Documento" says "work out what this is"
     * and "CPF" says "this is a CPF"; a sheet asserting both about the same
     * person is a mapping mistake, and picking one would quietly ignore a
     * column of real values.
     */
    public function documentosConflitantes(): bool
    {
        $usados = array_values($this->colunas);

        return in_array('documento', $usados, true)
            && array_intersect(['cpf', 'passaporte'], $usados) !== [];
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
        return $this->faltando() === []
            && $this->duplicados() === []
            && !$this->documentosConflitantes();
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
     * The three document columns collapse into one value plus a note saying
     * which column it came from, because a participante has one document and
     * the question downstream is only ever "which column does it belong in".
     *
     * @param array<int, string> $celulas
     * @return array{evento: string, nome: string, funcao: string, documento: string, documento_secundario: string, documento_coluna: string}
     */
    public function aplicar(array $celulas): array
    {
        $valores = [
            'evento'           => '',
            'nome'             => '',
            'funcao'           => '',
            'documento'            => '',
            'cpf'                  => '',
            'passaporte'           => '',
            'documento_secundario' => '',
            'documento_coluna'     => ClassificacaoDocumento::COLUNA_QUALQUER,
        ];

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

        $cpf        = Texto::limpar($valores['cpf']);
        $passaporte = Texto::limpar($valores['passaporte']);

        if ($cpf !== '' && $passaporte !== '') {
            // A person has one identity document. Two filled cells is a row
            // to look at, not a preference to resolve — and choosing either
            // would discard the other silently.
            //
            // The second value is carried rather than dropped. This row gets
            // left behind for the operator to sort out, and the sheet they get
            // back has to hold everything they pasted, or fixing it means
            // going to find the original again.
            $valores['documento']            = $cpf;
            $valores['documento_secundario'] = $passaporte;
            $valores['documento_coluna']     = ClassificacaoDocumento::COLUNA_AMBAS;
        } elseif ($cpf !== '') {
            $valores['documento']        = $cpf;
            $valores['documento_coluna'] = ClassificacaoDocumento::COLUNA_CPF;
        } elseif ($passaporte !== '') {
            $valores['documento']        = $passaporte;
            $valores['documento_coluna'] = ClassificacaoDocumento::COLUNA_ESTRANGEIRA;
        }

        unset($valores['cpf'], $valores['passaporte']);

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
            'documento'  => 'Documento (CPF ou passaporte)',
            'cpf'        => 'CPF',
            'passaporte' => 'Passaporte / documento estrangeiro',
            default      => 'Ignorar',
        };
    }
}
