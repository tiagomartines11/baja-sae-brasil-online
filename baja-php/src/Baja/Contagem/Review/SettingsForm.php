<?php
declare(strict_types=1);

namespace Baja\Contagem\Review;

use Baja\Contagem\Engine\Settings;
use InvalidArgumentException;

/**
 * The settings form of the sources page: which fields it has, how each is
 * explained, and how a posted form becomes a Settings object.
 *
 * Settings::fromArray() silently replaces a bad value with the default, which
 * is right when reading stored data during a race. A person typing into a
 * form should be told instead, so this validates first.
 */
final class SettingsForm
{
    public const MODE_LABELS = [
        Settings::MODO_CONFIRMADAS => 'Confirmadas: conta a passagem vista por duas fontes ou confirmada pelo revisor',
        Settings::MODO_FONTE_PRINCIPAL => 'Fonte principal: conta o que a fonte principal registrou',
        Settings::MODO_TODAS => 'Todas: conta toda passagem registrada por qualquer fonte',
    ];

    /** Whole-number fields: key => [label, hint]. */
    public const INTEGER_FIELDS = [
        'janela_ms' => ['Janela de agrupamento (ms)', 'Registros do mesmo carro mais próximos que isto são a mesma passagem.'],
        'duplicata_ms' => ['Janela de toque duplo (ms)', 'Repetição da mesma fonte dentro deste tempo é anulada sozinha.'],
        'janela_ritmo_s' => ['Janela do ritmo (s)', 'Quanto tempo para trás o ritmo da prova é calculado.'],
        'dispersao_max_ms' => ['Dispersão máxima para melhor volta (ms)', 'As fontes precisam concordar dentro disto para a volta valer como melhor volta.'],
        'fonte_offline_s' => ['Fonte offline após (s)', 'Tempo sem contato para uma fonte aparecer como offline.'],
    ];

    /** Decimal fields: key => [label, hint]. */
    public const DECIMAL_FIELDS = [
        'fracao_volta_minima' => ['Fração mínima de volta', 'Volta mais curta que esta fração da volta esperada gera alerta.'],
        'fator_atrasado' => ['Fator "atrasado"', 'Voltas esperadas sem passar para o carro aparecer como atrasado.'],
        'fator_parado' => ['Fator "parado"', 'Voltas esperadas sem passar para o carro aparecer como parado.'],
    ];

    /**
     * @param array<string, mixed> $post
     * @param int[] $countingSourceIds sources that may be chosen as primary
     * @throws InvalidArgumentException naming the bad field, in Portuguese
     */
    public static function fromPost(array $post, array $countingSourceIds): Settings
    {
        $values = [];

        $mode = $post['modo_publicacao'] ?? null;
        if (!is_string($mode) || !isset(self::MODE_LABELS[$mode])) {
            throw new InvalidArgumentException('Modo de publicação inválido.');
        }
        $values['modo_publicacao'] = $mode;

        $primary = is_string($post['fonte_principal_id'] ?? null) ? trim($post['fonte_principal_id']) : '';
        if ($primary !== '') {
            if (!ctype_digit($primary) || !in_array((int) $primary, $countingSourceIds, true)) {
                throw new InvalidArgumentException('Fonte principal inválida.');
            }
            $values['fonte_principal_id'] = (int) $primary;
        }
        if ($mode === Settings::MODO_FONTE_PRINCIPAL && !isset($values['fonte_principal_id'])) {
            throw new InvalidArgumentException('O modo "fonte principal" exige escolher a fonte principal.');
        }

        foreach (self::INTEGER_FIELDS as $key => [$label]) {
            $raw = is_string($post[$key] ?? null) ? trim($post[$key]) : '';
            if (!ctype_digit($raw) || strlen($raw) > 9 || (int) $raw <= 0) {
                throw new InvalidArgumentException($label . ': informe um número inteiro maior que zero.');
            }
            $values[$key] = (int) $raw;
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
}
