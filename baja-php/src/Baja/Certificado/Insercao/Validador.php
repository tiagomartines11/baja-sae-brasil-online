<?php

namespace Baja\Certificado\Insercao;

use Baja\Certificado\Busca;
use Baja\Certificado\Documento;
use Baja\Certificado\Funcao;
use Baja\Certificado\Nome;
use Baja\Model\Participante;
use Baja\Model\ParticipanteQuery;
use Propel\Runtime\ActiveQuery\Criteria;

/**
 * Everything that has to be true before a certificate row is created.
 *
 * One service, called by both insertion pages. The single-entry page hands it
 * one row and the paste hands it two thousand; nothing below distinguishes
 * them, which is the point. Two code paths that validate "the same way" are
 * two code paths that will one day not.
 */
final class Validador
{
    /**
     * `nome` is VARCHAR(300) and `funcao` VARCHAR(45), both latin1.
     *
     * Checked here because MySQL under STRICT_TRANS_TABLES answers an
     * over-long value by refusing the statement, which inside a batch
     * transaction takes every other row down with it.
     */
    private const NOME_MAX = 300;

    private ?Eventos $eventos = null;

    /**
     * Validate a whole submission.
     *
     * @param array<int, array{evento?: string, nome?: string, funcao?: string, documento?: string}> $brutas
     * @param array<int, array<string, string>> $resolucoes  row number => problem code => resolution,
     *                                                       as chosen on a previous pass over the review screen
     *
     * @return array<int, Linha>
     */
    public function validar(array $brutas, array $resolucoes = []): array
    {
        $linhas = [];
        $numero = 0;

        foreach ($brutas as $bruta) {
            $numero++;
            $linhas[] = new Linha(
                $numero,
                Texto::limpar((string) ($bruta['evento'] ?? '')),
                Texto::limpar((string) ($bruta['nome'] ?? '')),
                Texto::limpar((string) ($bruta['funcao'] ?? '')),
                Texto::limpar((string) ($bruta['documento'] ?? '')),
                (string) ($bruta['documento_coluna'] ?? ClassificacaoDocumento::COLUNA_QUALQUER),
                Texto::limpar((string) ($bruta['documento_secundario'] ?? ''))
            );
        }

        foreach ($linhas as $linha) {
            $this->validarCampos($linha);
        }

        // Both of these need the whole submission in hand, so they run after
        // every row has been classified rather than inside the loop above.
        $this->detectarDuplicatasNoLote($linhas);
        $this->confrontarComOBanco($linhas);

        foreach ($linhas as $linha) {
            foreach ($resolucoes[$linha->numero] ?? [] as $codigo => $escolha) {
                $linha->resolver((string) $codigo, (string) $escolha);
            }
            $this->aplicarResolucoes($linha);
        }

        return $linhas;
    }

    // -------------------------------------------------------------------------
    // Per-row checks
    // -------------------------------------------------------------------------

    private function validarCampos(Linha $linha): void
    {
        $this->validarNome($linha);
        $this->validarEvento($linha);
        $this->validarFuncao($linha);
        $this->validarDocumento($linha);
    }

    private function validarNome(Linha $linha): void
    {
        $nome = $linha->nomeBruto;

        if ($nome === '') {
            $linha->adicionar(Problema::erro(
                Problema::CAMPO_OBRIGATORIO,
                'O nome é obrigatório.',
                ['campo' => 'nome']
            ));

            return;
        }

        if (!Texto::utf8Valido($nome)) {
            $linha->adicionar(Problema::erro(
                Problema::CODIFICACAO_INVALIDA,
                'O nome não está em UTF-8 e não pôde ser lido. Copie a coluna novamente.',
                ['campo' => 'nome']
            ));

            return;
        }

        $ruins = Texto::naoArmazenaveis($nome);
        if ($ruins !== []) {
            $linha->adicionar(Problema::erro(
                Problema::CARACTERES_INVALIDOS,
                'O nome contém ' . (count($ruins) === 1 ? 'um caractere que a base' : 'caracteres que a base')
                . ' não armazena: ' . implode(', ', array_map([Texto::class, 'descrever'], $ruins))
                . '. Substitua ' . (count($ruins) === 1 ? 'esse caractere' : 'esses caracteres') . ' e cole de novo.',
                ['campo' => 'nome', 'caracteres' => $ruins]
            ));

            return;
        }

        if (mb_strlen($nome, 'UTF-8') > self::NOME_MAX) {
            $linha->adicionar(Problema::erro(
                Problema::CAMPO_LONGO,
                sprintf('O nome tem %d caracteres; o limite é %d.', mb_strlen($nome, 'UTF-8'), self::NOME_MAX),
                ['campo' => 'nome']
            ));

            return;
        }

        // A name in one uniform case is recased, and nobody is asked.
        //
        // The stored name is what the certificate prints, and sheets arrive
        // ALL CAPS more often than not — so left alone this produces a couple
        // of hundred certificates shouting every time somebody exports from a
        // registration system. That outcome is certain; what the rule costs
        // is occasional and small.
        //
        // It does get some names wrong. A McDonald becomes a Mcdonald, and an
        // ALL CAPS sheet has usually lost its accents, which this does not
        // invent — JOAO becomes Joao, not João. Every adjustment is shown on
        // the review screen, and certificados_nome.php stores a name exactly
        // as it is typed, which is where a name this rule spoils gets fixed.
        //
        // Only uniform case is touched. Anything mixed is somebody's own
        // spelling, including a deliberately capitalised surname, and is left
        // alone.
        if (Texto::caixaUniforme($nome) !== null) {
            $recase = Texto::caixaDeNome($nome);

            if ($recase !== $nome) {
                $nome = $recase;
                $linha->caixaAjustada = true;
            }
        }

        // Assigned after the recasing, so that everything downstream compares
        // and writes the corrected name. That also removes a class of
        // warnings that were never worth raising: "ANA PAULA" against a
        // stored "Ana Paula" is no longer a name conflict.
        $linha->nome = $nome;

        // A single-part name cannot be found again. /buscar requires two name
        // parts in common before it returns anything — that minimum is what
        // stops a first name being a credential — so a row stored under one
        // word mints a certificate whose owner can never reach it, and
        // nothing about the insert looks wrong at the time.
        //
        // A warning, not an error: some people genuinely have one name, and
        // refusing them outright would be the system being wrong about a
        // person rather than about a spreadsheet.
        if (count(Nome::parts($nome)) < 2) {
            $linha->adicionar(Problema::aviso(
                Problema::NOME_UNICO,
                'O nome tem uma parte só. Quem tiver este certificado não vai conseguir '
                . 'encontrá-lo na busca, que exige dois nomes.',
                [Problema::CONFIRMAR]
            ));
        }
    }

    private function validarEvento(Linha $linha): void
    {
        if ($linha->eventoBruto === '') {
            $linha->adicionar(Problema::erro(
                Problema::CAMPO_OBRIGATORIO,
                'O evento é obrigatório.',
                ['campo' => 'evento']
            ));

            return;
        }

        // The code, or either of the two names the event goes by. A sheet
        // exported from this system carries codes; one built by hand carries
        // the formal `nome` a certificate prints or the short `titulo` with
        // the year in it, depending on where it came from.
        $codigo = $this->eventos()->resolve($linha->eventoBruto);

        if ($codigo === null) {
            $ambiguos = $this->eventos()->ambiguos($linha->eventoBruto);

            if ($ambiguos !== []) {
                // Event names are free text and nothing stops two of them
                // being identical. Choosing one would file a batch of
                // certificates under whichever sorted first.
                $linha->adicionar(Problema::erro(
                    Problema::EVENTO_AMBIGUO,
                    sprintf(
                        '"%s" serve para mais de um evento (%s). Use o código do evento.',
                        $linha->eventoBruto,
                        implode(', ', $ambiguos)
                    ),
                    ['campo' => 'evento', 'eventos' => $ambiguos]
                ));

                return;
            }

            $linha->adicionar(Problema::erro(
                Problema::EVENTO_DESCONHECIDO,
                sprintf(
                    'Não existe evento com código, nome ou título "%s".',
                    $linha->eventoBruto
                ),
                ['campo' => 'evento']
            ));

            return;
        }

        $linha->eventoId = $codigo;
    }

    private function validarFuncao(Linha $linha): void
    {
        if ($linha->funcaoBruta === '') {
            $linha->adicionar(Problema::erro(
                Problema::CAMPO_OBRIGATORIO,
                'A função é obrigatória.',
                ['campo' => 'funcao']
            ));

            return;
        }

        $codigo = Funcao::resolve($linha->funcaoBruta);

        if ($codigo === null) {
            // Deliberately not a nearest match. The alternative was tried in
            // the specification and rejected: `comissario` and
            // `comissao tecnica` share seven characters, and a certificate
            // whose printed role is not the role its record holds is worse
            // than a row the user has to fix.
            $linha->adicionar(Problema::erro(
                Problema::FUNCAO_DESCONHECIDA,
                sprintf(
                    'Não existe a função "%s". Use uma de: %s.',
                    $linha->funcaoBruta,
                    implode(', ', Funcao::selectable())
                ),
                ['campo' => 'funcao']
            ));

            return;
        }

        $linha->funcao = $codigo;

        if (Funcao::isDeprecated($codigo)) {
            // Not rejected: a sheet from an older event legitimately carries
            // these, and the certificates already issued under them are
            // valid. Not accepted silently either, since they are not offered
            // anywhere and a new one is much more likely to be a stale
            // template than a deliberate choice.
            $linha->adicionar(Problema::aviso(
                Problema::FUNCAO_OBSOLETA,
                sprintf(
                    'A função "%s" não é mais usada em registros novos.',
                    Funcao::label($codigo)
                ),
                [Problema::CONFIRMAR],
                ['funcao' => $codigo]
            ));
        }
    }

    private function validarDocumento(Linha $linha): void
    {
        $classificacao = ClassificacaoDocumento::de($linha->documentoBruto, $linha->colunaDocumento);
        $linha->documento = $classificacao;

        switch ($classificacao->tipo) {
            case ClassificacaoDocumento::CPF:
                $linha->cpf = $classificacao->cpf;

                return;

            case ClassificacaoDocumento::ESTRANGEIRO:
                $linha->documentoEstrangeiro = $classificacao->estrangeiro;

                return;

            case ClassificacaoDocumento::VAZIO:
                $linha->adicionar(Problema::erro(
                    Problema::DOCUMENTO_AUSENTE,
                    $linha->documentoBruto === ''
                        ? 'O documento é obrigatório.'
                        : sprintf('"%s" não contém nenhum número de documento.', $linha->documentoBruto),
                    ['campo' => 'documento']
                ));

                return;

            case ClassificacaoDocumento::NOTACAO_CIENTIFICA:
                $linha->adicionar(Problema::erro(
                    Problema::NOTACAO_CIENTIFICA,
                    sprintf(
                        'O documento chegou como "%s", que é o que o Excel faz com um número '
                        . 'longo. Os dígitos não estão mais aí e não dá para recuperá-los daqui: '
                        . 'formate a coluna como texto na planilha e copie de novo.',
                        $classificacao->original
                    ),
                    ['campo' => 'documento']
                ));

                return;

            case ClassificacaoDocumento::DOIS_DOCUMENTOS:
                $linha->adicionar(Problema::erro(
                    Problema::DOIS_DOCUMENTOS,
                    sprintf(
                        'Esta linha tem CPF (%s) e passaporte (%s) preenchidos. Uma pessoa '
                        . 'tem um documento de identidade: apague o que não valer.',
                        $classificacao->original,
                        $linha->documentoSecundario
                    ),
                    ['campo' => 'documento']
                ));

                return;

            case ClassificacaoDocumento::CONTRADIZ_COLUNA:
                $linha->adicionar(Problema::erro(
                    Problema::DOCUMENTO_CONTRADIZ,
                    sprintf(
                        '"%s" está na coluna de CPF e tem letras. Se for um passaporte, '
                        . 'ele vai na coluna de passaporte.',
                        $classificacao->original
                    ),
                    ['campo' => 'documento']
                ));

                return;

            case ClassificacaoDocumento::AMBIGUO:
                // One resolution, never two. A CPF failing its check digits is
                // not recorded as a CPF — see leiturasPossiveis(). Either it is
                // a passport kept as digits, which this confirms, or it is a
                // typo, which is fixed in the sheet.
                $linha->adicionar(Problema::aviso(
                    Problema::DOCUMENTO_AMBIGUO,
                    $classificacao->cpf === null
                        ? sprintf(
                            '"%s" tem dígitos demais para ser um CPF. Se for um passaporte '
                            . 'ou documento estrangeiro, confirme abaixo.',
                            $classificacao->original
                        )
                        : ($classificacao->coluna === ClassificacaoDocumento::COLUNA_CPF
                        ? sprintf(
                            '"%s" está na coluna de CPF e não passa na verificação dos '
                            . 'dígitos verificadores, então não pode ser gravado como CPF. '
                            . 'Se estiver digitado errado, corrija na planilha. Se for um '
                            . 'passaporte que foi parar nesta coluna, confirme abaixo.',
                            $classificacao->original
                        )
                        : sprintf(
                            '"%s" não passa na verificação dos dígitos verificadores de um '
                            . 'CPF, então não pode ser gravado como CPF — é quase sempre '
                            . 'erro de digitação, e nesse caso precisa ser corrigido na '
                            . 'planilha. Se for um passaporte registrado só com números, '
                            . 'confirme abaixo.',
                            $classificacao->original
                        )),
                    [Problema::LER_COMO_ESTRANGEIRO],
                    ['documento' => $classificacao->original]
                ));

                return;
        }
    }

    // -------------------------------------------------------------------------
    // Checks that need the whole submission
    // -------------------------------------------------------------------------

    /**
     * The same person, event and role twice in one paste.
     *
     * The commonest way to produce this is a sheet with a repeated block, and
     * without the check it becomes two rows, two tokens and two entries in
     * /buscar for one participation.
     *
     * @param array<int, Linha> $linhas
     */
    private function detectarDuplicatasNoLote(array $linhas): void
    {
        $vistas = [];

        foreach ($linhas as $linha) {
            $chave = $this->chaveNatural($linha);
            if ($chave === null) {
                continue;
            }

            if (isset($vistas[$chave])) {
                $linha->adicionar(Problema::aviso(
                    Problema::DUPLICADO_NO_LOTE,
                    sprintf(
                        'Esta linha repete a linha %d — mesma pessoa, mesmo evento, mesma função.',
                        $vistas[$chave]
                    ),
                    [Problema::IGNORAR, Problema::CONFIRMAR],
                    ['linha' => $vistas[$chave]]
                ));

                continue;
            }

            $vistas[$chave] = $linha->numero;
        }
    }

    /**
     * Duplicates and name conflicts, against what is already on file.
     *
     * Two queries for the whole submission rather than two per row. The
     * foreign-document clause in Busca cannot use an index — it has to reach
     * a stored passport whose letters differ from the submitted one — so a
     * per-row lookup would be one full scan per row, and a sheet of two
     * thousand would not finish.
     *
     * @param array<int, Linha> $linhas
     */
    private function confrontarComOBanco(array $linhas): void
    {
        $candidatas = $this->candidatas($linhas);

        if ($candidatas['porCpf'] === [] && $candidatas['estrangeiras'] === []) {
            return;
        }

        foreach ($linhas as $linha) {
            if ($linha->documentoBruto === '' || $linha->documento === null) {
                continue;
            }

            $existentes = $this->existentesPara($linha, $candidatas);
            $linha->definirExistentes($existentes);

            if ($existentes === []) {
                continue;
            }

            $this->detectarDuplicata($linha, $existentes);
            $this->detectarConflitoDeNome($linha, $existentes);
        }
    }

    /**
     * Every row on file that could belong to any document in the submission.
     *
     * The CPF side is keyed exactly, so a row is found in constant time. The
     * foreign side cannot be: the comparison ignores letters and leading
     * zeros on both sides, which is not something a WHERE clause can express
     * against an index. Those rows are loaded whole and matched in PHP —
     * affordable because participants with a foreign document are a small
     * slice of the table, and it is one scan for the whole submission rather
     * than one per row.
     *
     * @param array<int, Linha> $linhas
     * @return array{porCpf: array<string, array<int, Participante>>, estrangeiras: array<int, Participante>}
     */
    private function candidatas(array $linhas): array
    {
        $cpfs = [];
        $precisaEstrangeiras = false;

        foreach ($linhas as $linha) {
            if ($linha->documentoBruto === '') {
                continue;
            }

            // Computed from the raw value, not from the classification. A row
            // filed under the other column than the one the value looks like
            // is exactly the misfiling /buscar exists to absorb, and the
            // duplicate check has to see it too.
            $cpf = Documento::normalizeCpf($linha->documentoBruto);
            if ($cpf !== null) {
                $cpfs[$cpf] = true;
            }

            if (Documento::normalizeEstrangeiro($linha->documentoBruto) !== '') {
                $precisaEstrangeiras = true;
            }
        }

        $porCpf = [];
        if ($cpfs !== []) {
            $rows = ParticipanteQuery::create()
                ->filterByCpf(array_keys($cpfs))
                // Same exclusion Busca applies: a voided certificate is not a
                // duplicate of the one being created, and its name is not a
                // conflict with it.
                ->filterByAnuladoEm(null, Criteria::ISNULL)
                ->find();

            foreach ($rows as $row) {
                $porCpf[(string) $row->getCpf()][] = $row;
            }
        }

        $estrangeiras = [];
        if ($precisaEstrangeiras) {
            $rows = ParticipanteQuery::create()
                ->filterByDocumentoEstrangeiro(null, Criteria::ISNOTNULL)
                ->filterByDocumentoEstrangeiro('', Criteria::NOT_EQUAL)
                ->filterByAnuladoEm(null, Criteria::ISNULL)
                ->find();
            $estrangeiras = iterator_to_array($rows, false);
        }

        return ['porCpf' => $porCpf, 'estrangeiras' => $estrangeiras];
    }

    /**
     * @param array{porCpf: array<string, array<int, Participante>>, estrangeiras: array<int, Participante>} $candidatas
     * @return array<int, Participante>
     */
    private function existentesPara(Linha $linha, array $candidatas): array
    {
        $documento = $linha->documentoBruto;
        $existentes = [];

        $cpf = Documento::normalizeCpf($documento);
        if ($cpf !== null) {
            foreach ($candidatas['porCpf'][$cpf] ?? [] as $row) {
                $existentes[(string) $row->getToken()] = $row;
            }
        }

        foreach ($candidatas['estrangeiras'] as $row) {
            // The rule itself, from Busca. Not restated here: it absorbs
            // missing letters and leading zeros for rows written before the
            // document columns were split, and a second version of it would
            // eventually disagree with the one /buscar uses.
            if (Busca::rowMatches($row, $documento)) {
                $existentes[(string) $row->getToken()] = $row;
            }
        }

        $existentes = array_values($existentes);

        // Newest event first. It decides which name "use the existing name"
        // means when a document carries more than one, and the most recently
        // recorded spelling is the best available guess at the current one.
        usort(
            $existentes,
            static fn (Participante $a, Participante $b) => strcmp(
                (string) $b->getEventoId(),
                (string) $a->getEventoId()
            )
        );

        return $existentes;
    }

    /**
     * The natural key: document, event, role.
     *
     * Not document plus event. A person can legitimately hold two roles at
     * one event — a competitor who is also an orientador — and is entitled to
     * a certificate for each.
     *
     * @param array<int, Participante> $existentes
     */
    private function detectarDuplicata(Linha $linha, array $existentes): void
    {
        if ($linha->eventoId === null || $linha->funcao === null) {
            return;
        }

        foreach ($existentes as $row) {
            if ((string) $row->getEventoId() === $linha->eventoId
                && (string) $row->getFuncao() === $linha->funcao) {
                $linha->duplicado = $row;
                $linha->adicionar(Problema::aviso(
                    Problema::DUPLICADO,
                    sprintf(
                        'Já existe um certificado para este documento em %s como %s, no nome de "%s".',
                        $linha->eventoId,
                        Funcao::label($linha->funcao),
                        trim((string) $row->getNome())
                    ),
                    [Problema::IGNORAR, Problema::ATUALIZAR],
                    ['token' => (string) $row->getToken()]
                ));

                return;
            }
        }
    }

    /**
     * The submitted name against every name already on file for this document.
     *
     * Three outcomes, and the middle one is the reason this is not a simple
     * equality test: names were entered per event, by hand, over years, and
     * differ in accents and middle names far more often than they differ in
     * whose name they are.
     *
     * @param array<int, Participante> $existentes
     */
    private function detectarConflitoDeNome(Linha $linha, array $existentes): void
    {
        if ($linha->nome === null) {
            return;
        }

        $nomes = [];
        $identico = false;
        $parecido = false;

        // What "correct the stored name" would actually rewrite. Collected
        // here so the warning can say so where the choice is offered: the
        // resolution is the one an operator reaches for constantly, and a
        // name correction that silently rewrites six certificates across four
        // years is not what they thought they were clicking.
        $eventosAfetados = [];
        $linhasAfetadas  = 0;

        foreach ($existentes as $row) {
            $armazenado = trim((string) $row->getNome());
            if ($armazenado === '') {
                continue;
            }

            $nomes[$armazenado] = true;

            if ($armazenado !== $linha->nome) {
                $linhasAfetadas++;
                $eventosAfetados[(string) $row->getEventoId()] = true;
            }

            if ($armazenado === $linha->nome) {
                $identico = true;
            }

            // The matcher from the lookup branch, as a component. Not
            // restated, not simplified for this path: it tokenizes each name
            // twice, punctuation removed and punctuation split on, and tests
            // membership against the union, because either reading alone
            // fails in one direction.
            if (Nome::matches($linha->nome, $armazenado)) {
                $parecido = true;
            }
        }

        if ($nomes === [] || $identico) {
            return;
        }

        $lista = implode('", "', array_keys($nomes));

        if ($parecido) {
            $linha->adicionar(Problema::aviso(
                Problema::NOME_DIVERGENTE_LEVE,
                sprintf(
                    'Este documento já está registrado como "%s". É a mesma pessoa, escrita '
                    . 'de outro jeito.',
                    $lista
                ),
                [Problema::USAR_EXISTENTE, Problema::ATUALIZAR_NOME, Problema::MANTER_AMBOS],
                [
                    'nomes'            => array_keys($nomes),
                    'eventos_afetados' => array_keys($eventosAfetados),
                    'linhas_afetadas'  => $linhasAfetadas,
                ]
            ));

            return;
        }

        // Reads as what it most often is. A name that does not match at all
        // against a document that exists is far more likely to be the wrong
        // document number than a person nobody recognises.
        $linha->adicionar(Problema::aviso(
            Problema::NOME_DIVERGENTE,
            sprintf(
                'Este documento já está registrado como "%s", que não parece a mesma pessoa. '
                . 'Confira o número do documento antes de continuar.',
                $lista
            ),
            [Problema::USAR_EXISTENTE, Problema::ATUALIZAR_NOME, Problema::MANTER_AMBOS],
            [
                'nomes'            => array_keys($nomes),
                'eventos_afetados' => array_keys($eventosAfetados),
                'linhas_afetadas'  => $linhasAfetadas,
            ]
        ));
    }

    /**
     * Fold the user's answers back into the values that will be written.
     *
     * Done here rather than at commit time so that a row which reports
     * podeGravar() carries exactly what committing it would write, and the
     * review screen can show it. A resolution that only takes effect inside
     * the writer is a resolution nobody can check before it happens.
     */
    private function aplicarResolucoes(Linha $linha): void
    {
        // The only reading on offer is the foreign one, so there is no branch
        // here: confirming sends the value to documento_estrangeiro, never to
        // cpf.
        if ($linha->resolucao(Problema::DOCUMENTO_AMBIGUO) === Problema::LER_COMO_ESTRANGEIRO
            && $linha->documento !== null) {
            $linha->cpf = null;
            $linha->documentoEstrangeiro = $linha->documento->estrangeiro;
        }

        foreach ([Problema::NOME_DIVERGENTE_LEVE, Problema::NOME_DIVERGENTE] as $codigo) {
            if ($linha->resolucao($codigo) !== Problema::USAR_EXISTENTE) {
                continue;
            }

            foreach ($linha->existentes() as $row) {
                $armazenado = trim((string) $row->getNome());
                if ($armazenado !== '') {
                    $linha->nome = $armazenado;
                    break;
                }
            }
        }
    }

    /** @return string|null null when the row is too broken to have a key */
    private function chaveNatural(Linha $linha): ?string
    {
        if ($linha->eventoId === null || $linha->funcao === null || $linha->documento === null) {
            return null;
        }

        // Keyed on the comparable form so that 1234567890 and 01234567890 —
        // the same CPF, one of them through a number-formatted column — count
        // as the same person within one paste.
        $documento = Documento::normalizeCpf($linha->documentoBruto)
            ?? Documento::normalizeEstrangeiro($linha->documentoBruto);

        if ($documento === '') {
            return null;
        }

        return $documento . '|' . $linha->eventoId . '|' . $linha->funcao;
    }

    private function eventos(): Eventos
    {
        return $this->eventos ??= new Eventos();
    }
}
