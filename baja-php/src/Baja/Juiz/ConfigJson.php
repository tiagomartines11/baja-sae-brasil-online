<?php
namespace Baja\Juiz;

/**
 * Reads the JSON that admins type in the judge pages (prova parameters,
 * resultado columns, event menu) and says whether it can be saved.
 *
 * Before this, the pages saved json_decode() straight away, so a typo such as
 * a trailing comma silently stored null and wiped the configuration.
 *
 * What counts as valid:
 *   - any text json_decode() accepts                 -> saved as decoded
 *   - blank text (empty or only spaces/new lines)    -> saved as null
 *   - the literal null                               -> saved as null
 *   - anything else, e.g. [1, 2,] or {'a': 1}        -> NOT saved, $erro is set
 *
 * Blank text is accepted on purpose: these pages show a stored null as an
 * empty box, so refusing blank text would make it impossible to save the rest
 * of the form for a record that has no configuration yet.
 */
class ConfigJson
{
    /**
     * @param mixed       $valor the decoded value, as json_decode() returns it (objects
     *                           as stdClass); null when blank, "null" or invalid
     * @param string|null $erro  json_last_error_msg() when the text is not valid JSON,
     *                           null when it can be saved
     */
    private function __construct(
        public readonly mixed $valor,
        public readonly ?string $erro
    ) {
    }

    /**
     * @param string|null $texto what was typed in the form
     * @return ConfigJson
     */
    static function decodificar($texto) {
        if ($texto === null || trim($texto) === '') {
            return new self(null, null);
        }

        $valor = json_decode($texto);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new self(null, json_last_error_msg());
        }

        return new self($valor, null);
    }

    /**
     * @return bool true when the text may be saved
     */
    function valido() {
        return $this->erro === null;
    }
}
