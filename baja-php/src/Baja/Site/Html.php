<?php
declare(strict_types=1);

namespace Baja\Site;

/**
 * Turns stored text into something safe to print in a page (BAJAAPP-41).
 *
 * Names, labels, options, headers and whatever a judge typed are text, not
 * HTML. Printed as they are, a quote in a name ends the attribute it sits in
 * and breaks the form, and a "<script>" in one runs in the browser of every
 * admin or visitor who opens the page. Public pages are cached at Cloudflare,
 * so there one bad value would be served to everybody.
 *
 * So everything printed goes through e(), in text and inside attributes
 * alike:
 *
 *     <td><?= Html::e($equipe->getEquipe()) ?></td>
 *     <input value="<?= Html::e($prova->getNome()) ?>">
 *
 * Attribute values must be in double or single quotes, as above: e() encodes
 * both kinds of quote, so the value cannot end the attribute. An unquoted
 * attribute (value=<?= ... ?>) is not safe whatever is done to the value.
 *
 * Not for:
 *  - the value of an editable form field: use field(), see there;
 *  - data inside a <script> block: use js(), see there;
 *  - results cells (formula output), which are meant to hold HTML (bold,
 *    colours, icons). Those are printed as they are until the sanitiser
 *    planned in BAJAAPP-35.
 */
final class Html
{
    /**
     * Escapes $value for HTML text or a quoted attribute value.
     *
     * null prints as nothing, so a missing value needs no special case at the
     * call site. Numbers and booleans print the way PHP prints them.
     *
     * Entities already in the text are kept: "Baja SAE&nbsp;BRASIL" stays as
     * it is, not "&amp;nbsp;". Admins have been typing entities into event
     * names and headers for years (the dev seed has a dozen), and those must
     * keep rendering as they did. This is still safe: an entity cannot open a
     * tag or close a quote, it only ever stands for one character of text.
     */
    public static function e(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
            'UTF-8',
            false
        );
    }

    /**
     * Escapes $value for an editable form field: <input value="...">, or the
     * text of a <textarea>.
     *
     * Unlike e(), this encodes every &, entities included. A field must show
     * exactly what is stored, because whatever it shows is what the form
     * sends back on save. With e(), "30&nbsp;m" in a prova's parameters would
     * show as "30 m" and be saved as that, and a formula holding "&lt;" would
     * come back holding a real "<".
     */
    public static function field(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /**
     * Encodes $value as a JavaScript literal, for a <script> block:
     *
     *     const atual = <?= Html::js($prova->getParams()) ?>;
     *
     * Plain json_encode is not enough there: a string holding "</script>"
     * ends the script block, whatever JSON thinks of it. The HEX flags write
     * <, >, &, ' and " as < and so on, which JavaScript reads back as
     * the same characters but the HTML parser never sees as markup.
     */
    public static function js(mixed $value): string
    {
        return (string) json_encode(
            $value,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
                | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }
}
