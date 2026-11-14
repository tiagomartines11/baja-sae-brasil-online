<?php
declare(strict_types=1);

namespace Baja\Juiz;

use Baja\Auth\Csrf;
use Baja\Model\JSON\Field;
use Baja\Repeat\Repetition;

/**
 * Checks what a judge's form posted before juiz/save_entry.php or
 * juiz/save_rolling_entry.php store it.
 *
 * Until this existed every rule lived only in the browser: the min, max and
 * step of a number box, the option lists, and the scripts of entry.php and
 * rolling_entry.php that lock fields (pass and xor). The save scripts stored
 * whatever arrived, and doubleval() turned any text into 0. A crafted or
 * replayed request, or a tab left open while the prova was reconfigured,
 * could store values the form never allows, and those feed the points.
 *
 * The rules are the browser's, repeated here:
 *
 *   number      what <input type="number"> sends: a plain decimal number
 *               (no comma, no spaces), >= 0, or <= 0 when "negative" is set,
 *               with at most "precision" decimal places (step = 10^-precision)
 *   enum        one of "options", or a list of them when "multiple" is set
 *   enum-assoc  the value (second element) of one of the [label, value] pairs
 *   time        what <input type="time"> sends with its default step of one
 *               minute: hh:mm, or hh:mm:00
 *   hidden      any single text (no config produces this type today)
 *   other       treated as number, as Field::printSelf() and
 *               Prova::getParamsInputs() do
 *
 *   xor         of the fields marked "A" (or "B") at most one has a value;
 *               the page locks the others as soon as one is filled. In a
 *               repeated group the letter is scoped to its repetition
 *               ("A_3", see Repeat\InputGroups): T_3 locks D_3 only
 *   pass 2      a field of group 2 may only have a value when group 1 is
 *               complete; the page locks group 2 until then
 *   repetition  (entry.php only, BAJAAPP-103) a field of repetition n+1 of
 *               a repeated group may only have a value when repetition n
 *               is complete; the page locks it until then
 *   Salvar      at least one group is complete: the page only enables the
 *               button when the group being edited has no empty field
 *
 * "Complete" means every field of the group has a value, except a field
 * that xor locks. Empty is always allowed for a single field: no field has
 * the "required" attribute.
 *
 * The page scripts look at groups 1 to 10 (entry.php) and only 1 and 2
 * (rolling_entry.php); a field in any other group never decides the button.
 * On entry.php the passes of repeated groups count too, whatever their
 * number: a group of 20 repetitions after a pass 1 goes up to pass 21.
 */
final class EntryValidator
{
    /** @var array<string, Field> code => field the form shows as an input, in form order */
    private array $fields = [];

    /**
     * @param Field[]  $fields            the fields the form shows as inputs
     * @param string[] $controlKeys       posted names that are buttons or selectors, not fields
     * @param string[] $groups            the pass values whose fields the page script handles
     * @param string[] $engineOwned       codes the lap counting writes, not shown as inputs
     * @param bool     $repetitionsLock   whether repetition n+1 waits for repetition n (entry.php)
     */
    private function __construct(array $fields, private array $controlKeys, private array $groups, private array $engineOwned, private bool $repetitionsLock = false)
    {
        foreach ($fields as $field) {
            $code = (string) $field->getCode();
            if (!in_array($code, $this->engineOwned, true)) {
                $this->fields[$code] = $field;
            }
        }
    }

    /**
     * The form of juiz/entry.php, posted to juiz/save_entry.php.
     *
     * @param Field[]  $fields      Prova::getParamsInputs()
     * @param string[] $engineOwned ManualScoreFields::engineOwnedFields(): VOLTAS, LAST
     *                              and BEST of a laps prova while the count publishes.
     *                              A value sent for them is not checked and not refused:
     *                              save_entry.php drops it, as it always has, and saves
     *                              the rest (tests/Contagem/Db/HttpReviewPagesTest).
     */
    public static function forEntry(array $fields, array $engineOwned = []): self
    {
        return new self($fields, ['submit', 'delete', Csrf::FIELD], self::entryPasses($fields), $engineOwned, true);
    }

    /**
     * The passes whose Salvar rule entry.php's script applies, and this
     * class checks: 1 to 10, as always, and every pass of a repeated group,
     * however high (BAJAAPP-103).
     *
     * @param Field[] $fields
     * @return list<string>
     */
    public static function entryPasses(array $fields): array
    {
        $passes = array_map('strval', range(1, 10));
        foreach ($fields as $field) {
            if ($field->getRepetition() !== null) {
                $passes[] = (string) $field->getRepetition()->pass;
            }
        }
        return array_values(array_unique($passes));
    }

    /** The form of juiz/rolling_entry.php, posted to juiz/save_rolling_entry.php. */
    public static function forRolling(array $fields): self
    {
        return new self($fields, ['submit', 'team', Csrf::FIELD], ['1', '2'], []);
    }

    /**
     * @param array<string|int, mixed> $post $_POST as it arrived, before the save
     *                                       script turns "" into null or joins lists
     * @return array<string, string> field code => message for the judge; empty when it may be saved
     */
    public function errors(array $post): array
    {
        $errors = [];
        foreach (array_keys($post) as $key) {
            $key = (string) $key;
            if (isset($this->fields[$key]) || in_array($key, $this->controlKeys, true) || in_array($key, $this->engineOwned, true)) {
                continue;
            }
            $errors[$key] = 'O campo ' . $key . ' não existe nesta prova (a configuração mudou depois que a página foi aberta?). Recarregue a página.';
        }

        foreach ($this->fields as $code => $field) {
            if (array_key_exists($code, $post)) {
                $error = self::valueError($field, $post[$code]);
                if ($error !== null) {
                    $errors[$code] = self::label($field) . ': ' . $error;
                }
            }
        }

        return $errors + $this->flowErrors($post);
    }

    /**
     * What the judge typed in one field, in the form Field::printSelf()
     * takes, for showing the form again after a refused save. Null is what
     * the page script calls empty, $(field).val() == "": for a multiple
     * select that is the list joined with commas, so a list holding only
     * the empty option is empty too.
     */
    public static function typedValue(array $post, string $code): ?string
    {
        $value = $post[$code] ?? null;
        if (is_array($value)) {
            $value = implode(',', array_filter($value, 'is_scalar'));
        }
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /** What is wrong with one posted value, or null when it is fine. */
    private static function valueError(Field $field, mixed $value): ?string
    {
        if (is_array($value)) {
            if ($field->getType() !== 'enum' || !$field->getMultiple()) {
                return 'recebeu uma lista de valores, mas aceita só um.';
            }
            foreach ($value as $item) {
                if (!is_string($item)) {
                    return 'recebeu um valor que não é texto.';
                }
                // The empty option is selectable together with others.
                if ($item !== '' && !in_array($item, self::optionValues($field), true)) {
                    return self::notAnOption($item);
                }
            }
            return null;
        }
        if (!is_scalar($value)) {
            return 'recebeu um valor que não é texto.';
        }

        $value = (string) $value;
        if ($value === '') {
            return null;
        }
        switch ($field->getType()) {
            case 'enum':
            case 'enum-assoc':
                return in_array($value, self::optionValues($field), true) ? null : self::notAnOption($value);
            case 'time':
                return self::timeError($value);
            case 'hidden':
                return null;
            default:
                return self::numberError($field, $value);
        }
    }

    /**
     * The values the select offers, as Field::printSelf() writes them into
     * the page: the text itself for enum, the second element for enum-assoc.
     * The browser sends that text back byte for byte, so the comparison is
     * exact: no trimming, no case folding, no accent folding.
     *
     * @return string[]
     */
    private static function optionValues(Field $field): array
    {
        $values = [];
        foreach ((array) $field->getOptions() as $option) {
            if ($field->getType() === 'enum-assoc') {
                $option = is_array($option) ? ($option[1] ?? null) : null;
            }
            if (is_scalar($option) || $option === null) {
                $values[] = (string) $option;
            }
        }
        return $values;
    }

    private static function notAnOption(string $value): string
    {
        return '"' . $value . '" não é uma das opções da lista.';
    }

    /**
     * <input type="time"> with no step attribute only takes whole minutes
     * (its default step is 60 seconds), so the browser sends "hh:mm", or
     * "hh:mm:00" when the box was loaded with such a value.
     */
    private static function timeError(string $value): ?string
    {
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(?::([0-5]\d)(?:\.(\d{1,3}))?)?$/', $value, $m)) {
            return '"' . $value . '" não é uma hora válida; use hh:mm.';
        }
        if ((int) ($m[2] ?? 0) !== 0 || (int) ($m[3] ?? 0) !== 0) {
            return 'a tela aceita só horas e minutos (hh:mm), sem segundos; recebeu "' . $value . '".';
        }
        return null;
    }

    /**
     * Field::printSelf() writes <input type="number" min="0" step="...">, or
     * max="0" when the field is "negative", with step = 1 / 10^precision.
     * The browser refuses to submit anything else, and what it does submit
     * is always written with a dot: "1,5" typed in a Brazilian browser
     * arrives as "1.5".
     */
    private static function numberError(Field $field, string $value): ?string
    {
        // The HTML "valid floating-point number": -1, 1.5, .5, 1e3. Not 1., +1, 1,5 or " 1".
        if (!preg_match('/^-?(\d*)(?:\.(\d+))?(?:[eE]([-+]?\d+))?$/', $value, $m) || ($m[1] === '' && ($m[2] ?? '') === '')) {
            return '"' . $value . '" não é um número' . (str_contains($value, ',') ? '; use ponto, não vírgula, para as casas decimais.' : '.');
        }
        $number = (float) $value;
        if (!is_finite($number)) {
            return '"' . $value . '" é grande demais.';
        }

        if ($field->getNegative()) {
            if ($number > 0) {
                return 'aceita só zero ou valores negativos (é uma penalidade); recebeu "' . $value . '".';
            }
        } elseif ($number < 0) {
            return 'não aceita valores negativos; recebeu "' . $value . '".';
        }

        // Compared on the digits, not on floats, so that 0.1 with precision 1
        // is not refused over a rounding error. The value is
        // digits * 10^-scale; it fits the step when scale <= precision.
        $precision = (int) ($field->getPrecision() ?? 0);
        $digits = ltrim($m[1] . ($m[2] ?? ''), '0');
        $scale = strlen($m[2] ?? '') - (int) ($m[3] ?? 0);
        $significant = rtrim($digits, '0');
        $scale -= strlen($digits) - strlen($significant);
        if ($significant === '' || $scale <= $precision) {
            return null;
        }
        if ($precision === 0) {
            return 'aceita só números inteiros; recebeu "' . $value . '".';
        }
        if ($precision > 0) {
            return 'aceita no máximo ' . $precision . ($precision === 1 ? ' casa decimal' : ' casas decimais') . '; recebeu "' . $value . '".';
        }
        return 'aceita só múltiplos de ' . (10 ** -$precision) . '; recebeu "' . $value . '".';
    }

    /**
     * The pass and xor rules of the page scripts. They depend on which
     * fields have a value, so they are checked on the whole form.
     *
     * @return array<string, string>
     */
    private function flowErrors(array $post): array
    {
        $filled = [];
        $filledByLetter = [];
        foreach ($this->fields as $code => $field) {
            $filled[$code] = self::typedValue($post, $code) !== null;
            if ($filled[$code] && $field->hasActiveXor()) {
                $filledByLetter[(string) $field->getXor()][] = $code;
            }
        }

        $errors = [];
        foreach ($filledByLetter as $codes) {
            if (count($codes) > 1) {
                $names = implode(', ', array_map(fn($c) => self::label($this->fields[$c]), $codes));
                foreach ($codes as $code) {
                    $errors[$code] = self::label($this->fields[$code]) . ': só um destes campos pode ser preenchido: ' . $names . '.';
                }
            }
        }

        // Per group, the fields still to fill. An empty field is locked by
        // xor when another field of its letter has a value.
        $members = [];
        $missing = [];
        foreach ($this->groups as $group) {
            $members[$group] = [];
            $missing[$group] = [];
        }
        foreach ($this->fields as $code => $field) {
            $group = (string) $field->getPass();
            if (!isset($members[$group])) {
                continue;
            }
            $members[$group][] = $code;
            $lockedByXor = isset($filledByLetter[(string) $field->getXor()]);
            if (!$filled[$code] && !$lockedByXor) {
                $missing[$group][] = $code;
            }
        }

        $group2HasValues = isset($members['2']) && array_filter($members['2'], fn($c) => $filled[$c]) !== [];
        if ($group2HasValues && isset($missing['1'])) {
            foreach ($missing['1'] as $code) {
                $errors[$code] ??= self::label($this->fields[$code]) . ': precisa ser preenchido antes dos campos do grupo 2.';
            }
        }

        if ($this->repetitionsLock) {
            $errors += $this->repetitionErrors($filled, $filledByLetter);
        }

        $groups = array_values(array_filter($this->groups, fn($g) => $members[$g] !== []));
        foreach ($groups as $group) {
            if ($missing[$group] === []) {
                return $errors;
            }
        }
        if ($groups === []) {
            $errors[''] = 'Esta prova não tem campos que a tela do juiz deixe salvar.';
            return $errors;
        }
        // No group is complete: point at the first one the judge started, or else the first one.
        $started = array_values(array_filter($groups, fn($g) => array_filter($members[$g], fn($c) => $filled[$c]) !== []));
        $target = $started[0] ?? $groups[0];
        foreach ($missing[$target] as $code) {
            $errors[$code] ??= self::label($this->fields[$code]) . ': precisa ser preenchido; o botão Salvar só funciona com todos os campos de um grupo preenchidos.';
        }
        return $errors;
    }

    /**
     * A repetition of a repeated group with a value in it while the one
     * before it is not complete: the page keeps it locked, so the value
     * did not come from the page as it is now. Earlier repetitions stay
     * editable, so emptying T_2 after T_3 was saved is refused here too
     * rather than leaving a gap the judge did not see.
     *
     * @param array<string, bool>          $filled         code => has a value
     * @param array<string, list<string>>  $filledByLetter xor letter => filled codes
     * @return array<string, string>
     */
    private function repetitionErrors(array $filled, array $filledByLetter): array
    {
        $byPlace = [];
        foreach ($this->fields as $field) {
            $repetition = $field->getRepetition();
            if ($repetition !== null) {
                $byPlace[$repetition->group][$repetition->number] = $repetition;
            }
        }

        $errors = [];
        foreach ($byPlace as $repetitions) {
            foreach ($repetitions as $number => $repetition) {
                $previous = $repetitions[$number - 1] ?? null;
                if ($previous === null || $this->isComplete($previous, $filled, $filledByLetter)) {
                    continue;
                }
                foreach ($repetition->codes as $code) {
                    if ($filled[$code] ?? false) {
                        $errors[$code] = self::label($this->fields[$code]) . ': "' . $repetition->title() . '" está bloqueada até "'
                            . $previous->title() . '" estar completa. Preencha "' . $previous->title() . '" primeiro.';
                    }
                }
            }
        }
        return $errors;
    }

    /**
     * Every field of the repetition has a value, or is locked by xor
     * because another field of its letter has one.
     *
     * @param array<string, bool>         $filled
     * @param array<string, list<string>> $filledByLetter
     */
    private function isComplete(Repetition $repetition, array $filled, array $filledByLetter): bool
    {
        foreach ($repetition->codes as $code) {
            $field = $this->fields[$code] ?? null;
            if ($field === null || ($filled[$code] ?? false)) {
                continue;
            }
            if ($field->hasActiveXor() && isset($filledByLetter[(string) $field->getXor()])) {
                continue;
            }
            return false;
        }
        return true;
    }

    private static function label(Field $field): string
    {
        $name = (string) $field->getName();
        return $name !== '' ? '"' . $name . '" (' . $field->getCode() . ')' : (string) $field->getCode();
    }
}
