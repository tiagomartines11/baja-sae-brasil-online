<?php
declare(strict_types=1);

namespace Baja\Contagem\Review;

use Baja\Contagem\Engine\Settings;
use Baja\Contagem\Service\ScoringFields;
use InvalidArgumentException;

/**
 * The settings form of the sources page: which fields it has, how each is
 * explained, and how a posted form becomes a Settings object.
 *
 * Settings::fromArray() silently replaces a bad value with the default, which
 * is right when reading stored data during a race. A person typing into a
 * form should be told instead, so this validates first.
 *
 * Every setting of Settings must appear here, with one sentence of help: a
 * setting that can only be changed by editing JSON in the database is a
 * setting nobody will dare to touch on race day.
 */
final class SettingsForm
{
    /** Choice fields: key => [label, hint, [value => label of the option]]. */
    public const CHOICE_FIELDS = [
        'modo_publicacao' => [
            'Quais passagens viram volta oficial',
            'Define quando uma passagem conta e se a contagem é gravada na pontuação.',
            [
                Settings::MODO_CONFIRMADAS => 'Confirmadas: conta a passagem vista por duas fontes ou confirmada pelo revisor',
                Settings::MODO_FONTE_PRINCIPAL => 'Fonte principal: conta o que a fonte principal registrou',
                Settings::MODO_TODAS => 'Todas: conta toda passagem registrada por qualquer fonte',
                Settings::MODO_DESLIGADA => 'Desligada: conta e revisa normalmente, mas NÃO grava nada na pontuação',
            ],
        ],
        'passagem_inicial' => [
            'Passagem zero',
            'Como a linha de contagem fica depois da linha de largada, a primeira passagem de um carro não completa uma volta.',
            [
                Settings::INICIAL_PRIMEIRA_PASSAGEM => 'Primeira passagem: a primeira passagem de cada carro é a passagem zero (voltas = passagens − 1)',
                Settings::INICIAL_LARGADA => 'Largada: a própria largada é a passagem zero e toda passagem contada é uma volta',
            ],
        ],
        'apos_encerramento' => [
            'Depois do encerramento',
            'O que fazer com as passagens registradas depois do horário de encerramento.',
            [
                Settings::APOS_PROXIMA_PASSAGEM => 'Próxima passagem: conta a primeira passagem de cada carro depois do encerramento e ignora as seguintes',
                Settings::APOS_NENHUMA => 'Nenhuma: nada conta depois do encerramento',
            ],
        ],
    ];

    /** Kept for the pages that only need the labels of the publish modes. */
    public const MODE_LABELS = self::CHOICE_FIELDS['modo_publicacao'][2];

    /** Whole-number fields that must be above zero: key => [label, hint]. */
    public const INTEGER_FIELDS = [
        'janela_ms' => ['Janela de agrupamento (ms)', 'Registros do mesmo carro mais próximos que isto são a mesma passagem.'],
        'dispersao_alerta_ms' => ['Dispersão que gera alerta (ms)', 'Registros da mesma passagem mais afastados entre si que isto geram alerta: contadores honestos digitam quase juntos. Com só duas fontes assim afastadas, a passagem fica retida até um revisor decidir.'],
        'duplicata_ms' => ['Janela de toque duplo (ms)', 'Repetição da mesma fonte dentro deste tempo é anulada sozinha.'],
        'janela_ritmo_s' => ['Janela do ritmo (s)', 'Quanto tempo para trás o ritmo da prova é calculado.'],
        'dispersao_max_ms' => ['Dispersão máxima para melhor volta (ms)', 'As fontes precisam concordar dentro disto para a volta valer como melhor volta.'],
        'fonte_offline_s' => ['Fonte offline após (s)', 'Tempo sem contato para uma fonte aparecer como offline.'],
        'recalculo_s' => ['Intervalo do recálculo completo (s)', 'Alertas, tempos de volta e estatísticas são recalculados no máximo uma vez a cada tantos segundos; a contagem de voltas em si é imediata.'],
        'dispositivo_ausente_s' => ['Dispositivo ausente após (s)', 'Depois deste tempo sem contato o sistema deixa de esperar o envio de um dispositivo e segue com as outras fontes.'],
    ];

    /** Whole-number fields where zero is a meaningful value: key => [label, hint]. */
    public const INTEGER_OR_ZERO_FIELDS = [
        'janela_largada_s' => ['Janela de largada (s)', 'Só com passagem zero = "Largada": registros nos primeiros segundos depois da largada são a passagem inicial e não contam volta. Zero desliga.'],
    ];

    /** Decimal fields: key => [label, hint]. */
    public const DECIMAL_FIELDS = [
        'fracao_volta_minima' => ['Fração mínima de volta', 'Volta mais curta que esta fração da volta esperada gera alerta.'],
        'fator_atrasado' => ['Fator "atrasado"', 'Voltas esperadas sem passar para o carro aparecer como atrasado.'],
        'fator_parado' => ['Fator "parado"', 'Voltas esperadas sem passar para o carro aparecer como parado.'],
    ];

    public const PRIMARY_SOURCE_HINT = 'A fonte em que o modo "Fonte principal" confia. Nos outros modos não tem efeito.';

    /** On/off fields: key => [label, hint]. */
    public const BOOLEAN_FIELDS = [
        'abastecimento' => ['Área de abastecimento', 'Liga o registro de entrada e saída do abastecimento, na mesma página do registro de box. Desligado, a escolha de área não aparece.'],
        'permitir_simulacao' => ['Permitir simulação', 'Só para ensaios: aceita passagens geradas pelo simulador. Enquanto estiver ligado, todas as páginas mostram a faixa "SIMULAÇÃO". Desligue antes da corrida de verdade.'],
    ];

    /** The one field whose choices are the prova's own input fields. */
    public const CLASSIFICATION_FIELD = 'campo_classificacao';
    public const CLASSIFICATION_LABEL = 'Campo de classificação';
    public const CLASSIFICATION_HINT = 'Um campo da própria prova criado só para isso (por exemplo APTO, com SIM/NÃO), preenchido por carro na tela de campos manuais; '
        . 'nunca um campo que a fórmula de pontuação lê, como DESCLAS. '
        . 'Com ele, só participa da corrida o carro presente E marcado SIM; em branco conta como não classificado. '
        . 'Sem campo escolhido, todo carro presente participa.';
    /** The value of that field that means "this car takes part". */
    public const CLASSIFIED_VALUE = 'SIM';

    /**
     * Every number field in the order the form shows them.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function numberFields(): array
    {
        return self::INTEGER_OR_ZERO_FIELDS + self::INTEGER_FIELDS + self::DECIMAL_FIELDS;
    }

    /**
     * @param array<string, mixed> $post
     * @param int[] $countingSourceIds sources that may be chosen as primary
     * @param int[]|null $activeSourceIds the ones among them that are switched on; null when all are
     * @param string[] $inputFieldCodes codes of the prova's input fields that may hold the classification
     * @param string[] $readByScoring the ones among them a scoring formula reads, which are refused
     * @throws InvalidArgumentException naming the bad field, in Portuguese
     */
    public static function fromPost(
        array $post,
        array $countingSourceIds,
        ?array $activeSourceIds = null,
        array $inputFieldCodes = [],
        array $readByScoring = []
    ): Settings {
        $values = [];

        // An unticked checkbox is simply absent from the form.
        foreach (array_keys(self::BOOLEAN_FIELDS) as $key) {
            $values[$key] = ($post[$key] ?? '') === '1';
        }
        $classification = is_string($post[self::CLASSIFICATION_FIELD] ?? null) ? trim($post[self::CLASSIFICATION_FIELD]) : '';
        if ($classification !== '' && !in_array($classification, $inputFieldCodes, true)) {
            throw new InvalidArgumentException(self::CLASSIFICATION_LABEL . ': escolha um dos campos desta prova.');
        }
        if (in_array($classification, $readByScoring, true)) {
            // The 26SE case: DESCLAS chosen, every car marked SIM, every
            // car scored 0 because SIM there means disqualified.
            throw new InvalidArgumentException(self::CLASSIFICATION_LABEL . ': ' . ScoringFields::whyNot($classification) . ' Nada foi alterado.');
        }
        $values[self::CLASSIFICATION_FIELD] = $classification === '' ? null : $classification;

        foreach (self::CHOICE_FIELDS as $key => [$label, , $options]) {
            $chosen = $post[$key] ?? null;
            if (!is_string($chosen) || !isset($options[$chosen])) {
                throw new InvalidArgumentException($label . ': opção inválida.');
            }
            $values[$key] = $chosen;
        }

        $primary = is_string($post['fonte_principal_id'] ?? null) ? trim($post['fonte_principal_id']) : '';
        if ($primary !== '') {
            if (!ctype_digit($primary) || !in_array((int) $primary, $countingSourceIds, true)) {
                throw new InvalidArgumentException('Fonte principal inválida.');
            }
            $values['fonte_principal_id'] = (int) $primary;
        }
        if ($values['modo_publicacao'] === Settings::MODO_FONTE_PRINCIPAL) {
            if (!isset($values['fonte_principal_id'])) {
                throw new InvalidArgumentException('O modo "fonte principal" exige escolher a fonte principal.');
            }
            if ($activeSourceIds !== null && !in_array($values['fonte_principal_id'], $activeSourceIds, true)) {
                throw new InvalidArgumentException('A fonte principal escolhida está desativada: nenhuma passagem nova contaria. Ative-a ou escolha outra.');
            }
        }

        foreach (self::INTEGER_FIELDS as $key => [$label]) {
            $values[$key] = self::wholeNumber($post, $key, $label, 1);
        }
        foreach (self::INTEGER_OR_ZERO_FIELDS as $key => [$label]) {
            $values[$key] = self::wholeNumber($post, $key, $label, 0);
        }

        foreach (self::DECIMAL_FIELDS as $key => [$label]) {
            // People here type decimals with a comma.
            $raw = is_string($post[$key] ?? null) ? str_replace(',', '.', trim($post[$key])) : '';
            if (!is_numeric($raw) || (float) $raw <= 0 || (float) $raw > 100) {
                throw new InvalidArgumentException($label . ': informe um número maior que zero.');
            }
            $values[$key] = (float) $raw;
        }

        if ($values['fator_parado'] < $values['fator_atrasado']) {
            throw new InvalidArgumentException('O fator "parado" não pode ser menor que o fator "atrasado".');
        }

        return Settings::fromArray($values);
    }

    /**
     * @param array<string, mixed> $post
     */
    private static function wholeNumber(array $post, string $key, string $label, int $minimum): int
    {
        $raw = is_string($post[$key] ?? null) ? trim($post[$key]) : '';
        if (!ctype_digit($raw) || strlen($raw) > 9 || (int) $raw < $minimum) {
            throw new InvalidArgumentException(
                $label . ($minimum > 0 ? ': informe um número inteiro maior que zero.' : ': informe um número inteiro, zero ou maior.')
            );
        }
        return (int) $raw;
    }
}
