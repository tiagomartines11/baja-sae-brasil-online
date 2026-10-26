<?php
declare(strict_types=1);

namespace Baja\Api\Resultados;

/**
 * Reads an endpoint's query string, strictly.
 *
 * WHY STRICT
 *
 * These endpoints are cached by Cloudflare, and Cloudflare stores one copy
 * per URL. If "?evt=26BR&x=1", "?evt=26BR&x=2"... were all answered, anybody
 * could make the cache miss on purpose and reach PHP on every request. So a
 * query string is answered only when it is exactly what the endpoint
 * documents:
 *
 *   - every parameter is one the endpoint knows   (else 400 unknown_parameter)
 *   - none is given twice, none is empty          (else 400 invalid_parameter)
 *   - each value matches its pattern, as sent:    (else 400 invalid_parameter)
 *     the patterns only allow letters, digits and "_", which never need
 *     percent-encoding, so "26%42R" is refused rather than read as "26BR"
 *   - every required parameter is there           (else 400 missing_parameter)
 *
 * The patterns for ids are the ones resultados/contagem_feed.php uses.
 * Whether an id names something is the endpoint's job (404), not this one's.
 */
final class Query
{
    /** An event id: 26BR. */
    public const EVENTO = '/^[0-9A-Za-z]{4}$/';

    /** A prova id inside its event: END. */
    public const PROVA = '/^[0-9A-Za-z]{1,3}$/';

    /** A resultado id, which starts with its event's id: 26BR_GER. */
    public const RESULTADO = '/^[0-9A-Za-z]{4}_[0-9A-Za-z]{1,3}$/';

    /** A switch: present as "=1", or absent. "display=0" is refused. */
    public const FLAG = '/^1$/';

    /**
     * @param string $queryString the raw query string ($_SERVER['QUERY_STRING']), without "?"
     * @param array<string, array{0: string, 1: bool}> $spec name => [pattern, required]
     * @return array<string, string> name => value, only the parameters given
     * @throws ApiError 400
     */
    public static function parse(string $queryString, array $spec): array
    {
        $values = [];
        if ($queryString !== '') {
            foreach (explode('&', $queryString) as $pair) {
                [$name, $value] = array_pad(explode('=', $pair, 2), 2, null);
                if (!array_key_exists($name, $spec)) {
                    throw new ApiError(400, ApiError::UNKNOWN_PARAMETER, self::unknownMessage($name, $spec));
                }
                if (array_key_exists($name, $values)) {
                    throw new ApiError(400, ApiError::INVALID_PARAMETER, "Parâmetro \"$name\" repetido.");
                }
                if ($value === null || preg_match($spec[$name][0], $value) !== 1) {
                    throw new ApiError(400, ApiError::INVALID_PARAMETER, "Valor inválido para \"$name\".");
                }
                $values[$name] = $value;
            }
        }

        foreach ($spec as $name => [, $required]) {
            if ($required && !array_key_exists($name, $values)) {
                throw new ApiError(400, ApiError::MISSING_PARAMETER, "Falta o parâmetro \"$name\".");
            }
        }
        return $values;
    }

    /** @param array<string, mixed> $spec */
    private static function unknownMessage(string $name, array $spec): string
    {
        // An empty name is "&&", a leading "&" or a trailing one.
        $what = $name === '' ? 'Parâmetro vazio' : 'Parâmetro desconhecido "' . mb_substr($name, 0, 40) . '"';
        return $what . '. Aceitos: ' . implode(', ', array_keys($spec)) . '.';
    }
}
