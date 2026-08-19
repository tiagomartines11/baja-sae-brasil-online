<?php

namespace Baja\Certificado\Insercao;

/**
 * The rows that did not go in, as a sheet again.
 *
 * When a batch is committed in part, the rest have to go somewhere. This is
 * that somewhere, and it is deliberately not storage: the leftovers come back
 * out as tab-separated text, which is the same thing the operator pasted in.
 *
 * That choice is the whole design. A pasted-back sheet needs no expiry policy,
 * no cleanup job and no second copy of anybody's CPF sitting in a database
 * table or a browser's local storage waiting to be forgotten about. It also
 * goes in an email to whoever knows the answer, which is usually the actual
 * next step — the question "is this the same Maria?" is rarely one the person
 * pasting can settle alone.
 */
final class Exportacao
{
    /**
     * The column order of an exported sheet.
     *
     * Five columns, with the two document columns separate, because that is
     * the only shape that survives a round trip without losing anything: a
     * digits-only passport put in a generic "documento" column would come back
     * ambiguous, and a row that filled both columns would come back with one
     * of its values gone.
     *
     * @var array<int, string>
     */
    public const COLUNAS = ['evento', 'nome', 'funcao', 'cpf', 'passaporte'];

    /**
     * @param array<int, Linha> $linhas
     */
    public static function tsv(array $linhas): string
    {
        $saida = '';

        foreach ($linhas as $linha) {
            $saida .= implode("\t", array_map(
                [self::class, 'celula'],
                self::valores($linha)
            )) . "\n";
        }

        return $saida;
    }

    /**
     * One row's five cells.
     *
     * The document goes to the column its classification says it belongs in.
     * An ambiguous value goes to the CPF column rather than the passport one,
     * and the direction matters: coming back through the CPF column it is
     * asked about again, which is correct for a value nobody has decided
     * about. In the passport column it would be accepted silently, which
     * would answer a question on the operator's behalf that they left open on
     * purpose.
     *
     * @return array<int, string>
     */
    private static function valores(Linha $linha): array
    {
        $cpf        = '';
        $passaporte = '';

        $tipo = $linha->documento?->tipo;

        if ($tipo === ClassificacaoDocumento::ESTRANGEIRO) {
            $passaporte = $linha->documentoBruto;
        } elseif ($tipo === ClassificacaoDocumento::DOIS_DOCUMENTOS) {
            $cpf        = $linha->documentoBruto;
            $passaporte = $linha->documentoSecundario;
        } else {
            $cpf = $linha->documentoBruto;
        }

        return [
            $linha->eventoBruto,
            // The name as pasted, not as recased. The operator is going to
            // look at this next to their original sheet, and a value that
            // differs from what they typed is one more thing to reconcile.
            $linha->nomeBruto,
            $linha->funcaoBruta,
            $cpf,
            $passaporte,
        ];
    }

    /**
     * One cell, quoted if it has to be.
     *
     * Same convention Excel uses and Planilha reads, so an exported sheet
     * parses back to the rows it came from even when a name contains a tab.
     */
    private static function celula(string $valor): string
    {
        if (strpbrk($valor, "\t\n\r\"") === false) {
            return $valor;
        }

        return '"' . str_replace('"', '""', $valor) . '"';
    }

    /**
     * The mapping that reads an exported sheet.
     *
     * Handed back to the paste page as hidden fields so that continuing with
     * the leftovers is one button rather than a re-mapping exercise.
     *
     * @return array<int, string>
     */
    public static function mapeamento(): array
    {
        return self::COLUNAS;
    }
}
