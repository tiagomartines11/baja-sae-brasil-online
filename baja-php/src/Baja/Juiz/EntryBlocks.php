<?php
declare(strict_types=1);

namespace Baja\Juiz;

use Baja\Model\JSON\Field;
use Baja\Repeat\Repetition;
use Baja\Site\Html;

/**
 * The repetitions of a repeated input group on the judge's entry page
 * (juiz/entry.php, BAJAAPP-103): one block per repetition, all on the same
 * page, each closed by its own buttons. The page's script shows one block
 * of each group at a time (the others are hidden, still in the form);
 * without the script every block is shown and the buttons scroll.
 *
 *   ┌ Tentativa 3 de 6 ─────────── ○ Vazia ┐
 *   │ Tempo [s] (Tentativa 3)       [     ] │
 *   │ Distância [m] (Tentativa 3)   [     ] │
 *   │ [            Salvar               ]   │
 *   │ [◀ Anterior]          [Próxima ▶]     │
 *   │ [1✔] [2✔] [3] [4🔒] [5🔒] [6🔒]       │
 *   └────────────────────────────────────────┘
 *
 * Each block says at a glance what it is:
 *
 *   preenchida  every field has a value (an xor pair needs only one side)
 *   incompleta  some fields have a value, not all
 *   vazia       no field has a value, and it may be filled
 *   bloqueada   no field has a value, and the repetition before it is not
 *               complete yet: its fields are locked
 *
 * The lock extends the pass rule: repetition n+1 waits for repetition n.
 * Earlier blocks stay editable. A block that already has values is never
 * locked, even when the one before it was emptied afterwards: a locked
 * field is disabled, a disabled field is not sent, and saving would then
 * erase what it held. EntryValidator refuses that save instead, and says why.
 *
 * The page's script (default/js/repeticoes.js) keeps the states and the
 * locks up to date while the judge types, by the same rules; this class
 * draws the page as it is when it opens.
 *
 * The block's Salvar sends its anchor ("repeticao-1-3") as the value of
 * "submit"; save_entry.php then brings the judge back to that block.
 */
final class EntryBlocks
{
    public const FILLED = 'preenchida';
    public const PARTIAL = 'incompleta';
    public const EMPTY = 'vazia';
    public const LOCKED = 'bloqueada';

    /** With more repetitions than this, the jump row is a select instead of buttons. */
    public const MOST_JUMP_BUTTONS = 8;

    private const STATUS_TEXT = [
        self::FILLED => '✔ Preenchida',
        self::PARTIAL => '… Incompleta',
        self::EMPTY => '○ Vazia',
        self::LOCKED => '🔒 Bloqueada',
    ];

    /**
     * The state of every repetition on the page.
     *
     * @param Field[]             $fields the page's fields, Prova::getParamsInputs()
     * @param array<string, bool> $filled code => whether the field has a value
     * @return array<string, string> anchor => FILLED, PARTIAL, EMPTY or LOCKED
     */
    public static function states(array $fields, array $filled): array
    {
        $byCode = [];
        $repetitions = [];
        foreach ($fields as $field) {
            $byCode[(string) $field->getCode()] = $field;
            $repetition = $field->getRepetition();
            if ($repetition !== null) {
                $repetitions[$repetition->anchor()] = $repetition;
            }
        }
        // An xor letter with a value somewhere locks the empty fields of that letter.
        $lettersInUse = [];
        foreach ($byCode as $code => $field) {
            if (($filled[$code] ?? false) && $field->hasActiveXor()) {
                $lettersInUse[(string) $field->getXor()] = true;
            }
        }

        $states = [];
        $complete = [];
        foreach ($repetitions as $anchor => $repetition) {
            $hasValues = false;
            $isComplete = true;
            foreach ($repetition->codes as $code) {
                $field = $byCode[$code] ?? null;
                if ($field === null) {
                    continue;
                }
                if ($filled[$code] ?? false) {
                    $hasValues = true;
                } elseif (!($field->hasActiveXor() && isset($lettersInUse[(string) $field->getXor()]))) {
                    $isComplete = false;
                }
            }
            $complete[$repetition->group][$repetition->number] = $isComplete;
            $previousComplete = $complete[$repetition->group][$repetition->number - 1] ?? true;

            $states[$anchor] = match (true) {
                $hasValues && $isComplete => self::FILLED,
                $hasValues => self::PARTIAL,
                !$previousComplete => self::LOCKED,
                default => self::EMPTY,
            };
        }
        return $states;
    }

    /**
     * Where the block's buttons lead: the anchors of the repetitions just
     * before and after this one in its group (null at either end), and of
     * every repetition of the group, in order, for the jump row.
     *
     * @return array{previous: ?string, next: ?string, all: list<string>}
     */
    public static function targets(Repetition $repetition): array
    {
        $anchorOf = fn(int $number) => (new Repetition($repetition->group, $repetition->name, $number, $repetition->total, 0, []))->anchor();
        return [
            'previous' => $repetition->isFirst() ? null : $anchorOf($repetition->number - 1),
            'next' => $repetition->isLast() ? null : $anchorOf($repetition->number + 1),
            'all' => array_map($anchorOf, range(1, $repetition->total)),
        ];
    }

    /**
     * The anchor of the block whose Salvar was pressed, from what the form
     * posted; null for the other buttons. Only an anchor's shape is
     * accepted: it goes into a Location header.
     */
    public static function savedBlock(array $post): ?string
    {
        $submit = $post['submit'] ?? null;
        return is_string($submit) && preg_match('/^repeticao-[1-9][0-9]*-[1-9][0-9]*$/D', $submit) === 1 ? $submit : null;
    }

    /**
     * Ends the rows before the block and opens it, with its title row.
     * The fields' own rows follow.
     */
    public static function opening(Repetition $repetition, string $state): string
    {
        return '</tbody><tbody class="repeticao" id="' . Html::e($repetition->anchor()) . '"'
            . ' data-grupo="' . $repetition->group . '" data-numero="' . $repetition->number . '" data-estado="' . Html::e($state) . '">'
            . '<tr class="repeticao-titulo"><td colspan="2">'
            . '<span class="repeticao-nome">' . Html::e($repetition->title()) . ' <small>de ' . $repetition->total . '</small></span> '
            . '<span class="repeticao-estado">' . Html::e(self::STATUS_TEXT[$state] ?? '') . '</span>'
            . '</td></tr>';
    }

    /**
     * The block's buttons, then the end of the block. The fields after it
     * go on in a body of their own.
     *
     * @param array<string, string> $states anchor => state, from states()
     */
    public static function closing(Repetition $repetition, array $states): string
    {
        $targets = self::targets($repetition);
        $html = '<tr class="repeticao-botoes"><td colspan="2">';
        // Disabled until the page script has looked at the fields, like the other Salvar buttons.
        $html .= '<button type="submit" name="submit" value="' . Html::e($repetition->anchor()) . '" class="salvar-bloco" disabled>Salvar</button>';
        $html .= '<div class="repeticao-setas">'
            . self::arrow($targets['previous'], '◀ Anterior', 'anterior')
            . self::arrow($targets['next'], 'Próxima ▶', 'proxima')
            . '</div>';
        $html .= self::jumps($repetition, $targets['all'], $states);
        $html .= '</td></tr></tbody><tbody>';
        return $html;
    }

    /** The CSS of the blocks. Phone first: the page must fit 360 px without sideways scrolling. */
    public static function styles(): string
    {
        return <<<'CSS'
<style>
    /* The form is 250 px wide on every entry page; with blocks, use the phone's width (up to 400 px). */
    table.tablesorter-blue { width: 100% !important; max-width: 400px; }
    tbody.repeticao { border: solid 2px black; }
    /* The page's script shows one block per group and hides the others (still in the form, still sent). */
    tbody.repeticao[hidden] { display: none; }
    tbody.repeticao tr.repeticao-titulo td { background: #e6eef7; font-weight: bold; padding: 6px 4px; text-align: left; }
    tbody.repeticao .repeticao-estado { float: right; font-weight: normal; font-size: 13px; }
    tbody.repeticao[data-estado="preenchida"] tr.repeticao-titulo td { background: #d8f0d8; }
    tbody.repeticao[data-estado="incompleta"] tr.repeticao-titulo td { background: #fff1c9; }
    tbody.repeticao[data-estado="bloqueada"] tr.repeticao-titulo td { background: #e2e2e2; color: #666; }
    tbody.repeticao tr.repeticao-botoes td { padding: 6px 4px; }
    .salvar-bloco { display: block; width: 100%; min-height: 44px; font-size: 18px; margin-bottom: 6px; }
    .repeticao-setas { display: flex; gap: 6px; margin-bottom: 6px; }
    .repeticao-seta { flex: 1; display: flex; align-items: center; justify-content: center; min-height: 44px; border: solid 1px #555; border-radius: 4px; background: #f4f4f4; color: black; text-decoration: none; font-size: 15px; }
    .repeticao-seta[aria-disabled="true"] { visibility: hidden; }
    .repeticao-saltos { display: flex; flex-wrap: wrap; gap: 4px; }
    .repeticao-salto { flex: 1 1 0; min-width: 36px; display: flex; align-items: center; justify-content: center; min-height: 44px; border: solid 1px #555; border-radius: 4px; background: white; color: black; text-decoration: none; font-size: 15px; }
    .repeticao-salto[data-estado="preenchida"] { background: #d8f0d8; }
    .repeticao-salto[data-estado="incompleta"] { background: #fff1c9; }
    .repeticao-salto[data-estado="bloqueada"] { background: #e2e2e2; color: #777; }
    .repeticao-salto[aria-current="true"] { border-width: 3px; font-weight: bold; }
    .repeticao-select { width: 100%; min-height: 44px; font-size: 16px; }
</style>
CSS;
    }

    private static function arrow(?string $target, string $text, string $which): string
    {
        if ($target === null) {
            return '<span class="repeticao-seta repeticao-' . $which . '" aria-disabled="true">' . Html::e($text) . '</span>';
        }
        return '<a class="repeticao-seta repeticao-' . $which . '" href="#' . Html::e($target) . '">' . Html::e($text) . '</a>';
    }

    /**
     * A row of numbered buttons, one per repetition, coloured by state; a
     * select instead when there are too many to fit a phone in one or two
     * rows.
     *
     * @param list<string>          $anchors
     * @param array<string, string> $states
     */
    private static function jumps(Repetition $repetition, array $anchors, array $states): string
    {
        if (count($anchors) > self::MOST_JUMP_BUTTONS) {
            $html = '<select class="repeticao-select" aria-label="Ir para">';
            foreach ($anchors as $index => $anchor) {
                $number = $index + 1;
                $state = $states[$anchor] ?? self::EMPTY;
                $html .= '<option value="' . Html::e($anchor) . '" data-alvo="' . Html::e($anchor) . '"' . ($number === $repetition->number ? ' selected' : '') . '>'
                    . Html::e(trim($repetition->name . ' ' . $number) . ' — ' . (self::STATUS_TEXT[$state] ?? '')) . '</option>';
            }
            return $html . '</select>';
        }
        $html = '<div class="repeticao-saltos">';
        foreach ($anchors as $index => $anchor) {
            $number = $index + 1;
            $state = $states[$anchor] ?? self::EMPTY;
            $html .= '<a class="repeticao-salto" href="#' . Html::e($anchor) . '" data-alvo="' . Html::e($anchor) . '" data-estado="' . Html::e($state) . '"'
                . ' title="' . Html::e(trim($repetition->name . ' ' . $number) . ': ' . (self::STATUS_TEXT[$state] ?? '')) . '"'
                . ($number === $repetition->number ? ' aria-current="true"' : '') . '>' . $number . '</a>';
        }
        return $html . '</div>';
    }
}
