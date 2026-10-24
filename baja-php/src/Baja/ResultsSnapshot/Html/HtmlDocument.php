<?php
declare(strict_types=1);

namespace Baja\ResultsSnapshot\Html;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * A results page, parsed, with the few questions the readers ask of it.
 *
 * Uses PHP's built-in DOM, which reads HTML the forgiving way a browser
 * does: the pages have unclosed tags and stray quotes (prova.php prints
 * `<th ">Equipe`), and a page that died half way stops in the middle of a
 * table. None of that is an error here.
 */
final class HtmlDocument
{
    private DOMDocument $document;
    private DOMXPath $xpath;

    public function __construct(string $html)
    {
        $this->document = new DOMDocument();
        // The XML declaration is the documented way to make DOMDocument read
        // the bytes as UTF-8; without it, accented names come out garbled.
        $previous = libxml_use_internal_errors(true);
        $this->document->loadHTML(
            '<?xml encoding="UTF-8">' . ($html === '' ? '<html></html>' : $html),
            LIBXML_NONET | LIBXML_COMPACT
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $this->xpath = new DOMXPath($this->document);
    }

    /**
     * @return list<DOMElement> the elements matching an XPath expression, in page order
     */
    public function query(string $expression, ?DOMNode $context = null): array
    {
        $found = [];
        foreach ($this->xpath->query($expression, $context) ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $found[] = $node;
            }
        }
        return $found;
    }

    /**
     * What is inside an element, as HTML, with whitespace normalised.
     *
     * The HTML is re-serialised by the parser, so `<br />` and `<br>`, or
     * single and double quotes around an attribute, come out the same. That
     * is deliberate: they look the same in a browser.
     */
    public function innerHtml(DOMNode $element): string
    {
        $html = '';
        foreach ($element->childNodes as $child) {
            $html .= $this->document->saveHTML($child);
        }
        return Text::normaliseWhitespace($html);
    }

    /**
     * The visible text of an element, whitespace normalised. A line break
     * or a paragraph counts as a space: the team header is printed as
     * `Equipe<br /><p>Escola</p>` and reads "Equipe Escola", not
     * "EquipeEscola".
     */
    public function text(DOMNode $element): string
    {
        $html = (string)preg_replace('~<(br|p|div)\b~i', ' $0', $this->innerHtml($element));
        return Text::normaliseWhitespace(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** A class attribute with its classes separated by single spaces. */
    public static function classOf(DOMElement $element): string
    {
        return Text::normaliseWhitespace($element->getAttribute('class'));
    }
}
