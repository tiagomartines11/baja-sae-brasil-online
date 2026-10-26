<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Site;

use PHPUnit\Framework\TestCase;

/**
 * No page has the text "</script" inside a <script> block, except the
 * block's own closing tag (BAJAAPP-75).
 *
 * Why that matters, for someone who has never had to think about it:
 *
 * A browser reads a page in two steps. First the HTML reader goes through the
 * page from top to bottom and cuts it into pieces: this is a paragraph, this
 * is a form, this is a script. Only then is each script handed to the
 * JavaScript engine to run.
 *
 * The HTML reader knows nothing about JavaScript. When it finds <script>, it
 * does not try to understand what follows; it just looks for the next
 * "</script" and says "the script ends here". It cannot tell a JavaScript
 * comment or a JavaScript string from real code, because comments and
 * strings only mean something to the JavaScript engine, which has not seen
 * the text yet. So in
 *
 *     <script>
 *         // careful: a name holding "</script>" would end this block
 *         const atual = [...];
 *     </script>
 *
 * the script ends in the middle of the comment. The JavaScript engine gets
 * only `// careful: a name holding "`, which does nothing. Everything after
 * it, `const atual = [...];` included, is no longer a script: the browser
 * shows it on the page as text, and none of it runs.
 *
 * That is what happened on juiz/prova.php, juiz/resultado.php and
 * juiz/premiacao_admin.php: a comment explaining this very danger wrote the
 * tag out, so the backup and restore buttons stopped working and their code
 * appeared on the page. The fix is to never write the tag inside a script,
 * not even in a comment: say "a closing script tag" in words, or, in a
 * string, write "<\/script>" (the backslash means nothing to JavaScript but
 * hides the tag from the HTML reader).
 *
 * How this test finds them: it reads every .php file under baja-php/ (not
 * vendor/, not tests/) the way the HTML reader and JavaScript would. For each
 * <script> block it follows the JavaScript, knowing when it is inside a
 * comment, a string or a regular expression, until it meets a "</script" in
 * plain code: that is the end the author meant. A "</script" met before
 * that, inside a comment or a string, is one the HTML reader would have
 * stopped at too early, and the test fails naming its file and line.
 *
 * The HTML of a .php file is the text outside <?php ... ?>; each PHP part is
 * read as if it printed a plain value (a number), because that is all the
 * HTML reader sees of it. HTML that PHP builds in strings (echo '<script
 * src=...></script>') is read too, one string at a time.
 */
final class ScriptBlockEndTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    public function testNoScriptBlockHasAClosingTagBeforeItsEnd(): void
    {
        $found = [];
        foreach ($this->phpFiles() as $file) {
            foreach (self::leaksInPhpFile((string) file_get_contents($file)) as [$line, $where]) {
                $found[] = substr($file, strlen(self::ROOT) + 1) . ":$line: \"</script\" inside a JavaScript $where";
            }
        }

        $this->assertSame([], $found,
            "The HTML reader ends a <script> block at the first \"</script\", even inside a comment or a string,\n"
            . "and the rest of the script is printed on the page instead of run. Write \"a closing script tag\"\n"
            . "in comments and \"<\\/script>\" in strings (see the comment at the top of this test).\n"
            . implode("\n", $found));
    }

    // ------------------------------------------------------------------
    // The scanner on its own, so that a scanner that finds nothing is not
    // mistaken for pages that have nothing to find.
    // ------------------------------------------------------------------

    public function testTheBugAsItWasIsFound(): void
    {
        $page = "<?php \$x = 1; ?>\n<form></form>\n<script type=\"text/javascript\">\n"
            . "    // Html::js, not plain json_encode: a name holding \"</script>\" would end\n"
            . "    const atual = <?= Html::js(\$x) ?>;\n"
            . "</script>\n";
        $this->assertSame([[4, 'comment']], self::leaksInPhpFile($page));
    }

    public function testATagInAStringOrABlockCommentIsFound(): void
    {
        $this->assertSame([[1, 'string']], self::leaksIn("<script>var s = '</script>';</script>"));
        $this->assertSame([[1, 'string']], self::leaksIn('<script>var s = `a </SCRIPT> b`;</script>'));
        $this->assertSame([[2, 'comment']], self::leaksIn("<script>/* one\n </script> */ go();</script>"));
    }

    public function testTheRightWaysToWriteItAreAccepted(): void
    {
        // Escaped in a string, said in words, or a block that really ends.
        $this->assertSame([], self::leaksIn("<script>var s = '<\\/script>';</script>"));
        $this->assertSame([], self::leaksIn("<script>// a closing script tag would end it\ngo();</script>"));
        $this->assertSame([], self::leaksIn('<script src="a.js"></script><p>it\'s "fine"</p><script>b();</script>'));
    }

    public function testQuotesInRegularExpressionsAndPhpDoNotConfuseIt(): void
    {
        // A quote inside /.../ or inside PHP code starts no string.
        $this->assertSame([], self::leaksIn("<script>var r = /['\"]/g; x = a / b;</script>"));
        $this->assertSame([], self::leaksInPhpFile("<script>var n = <?= \$a['x'] ?>;</script>"));
        // HTML built in a PHP string is read too.
        $this->assertSame([[1, 'comment']], self::leaksInPhpFile("<?php echo '<script>/* </script> */ go();</script>';"));
    }

    // ------------------------------------------------------------------

    /** @return string[] */
    private function phpFiles(): array
    {
        $files = [];
        $all = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT, \FilesystemIterator::SKIP_DOTS));
        foreach ($all as $file) {
            $relative = substr($file->getPathname(), strlen(self::ROOT) + 1);
            if ($file->getExtension() === 'php' && !preg_match('#^(vendor|tests)/#', $relative)) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        $this->assertNotEmpty($files, 'No .php file found under ' . self::ROOT);
        return $files;
    }

    /**
     * The leaks of one .php file: in the HTML it prints, and in every PHP
     * string (where some pages build their <script> tags).
     *
     * @return list<array{int, string}> [line, "comment" or "string"]
     */
    private static function leaksInPhpFile(string $source): array
    {
        $html = '';
        $leaks = [];
        $line = 1;
        foreach (token_get_all($source) as $token) {
            [$type, $text] = is_array($token) ? [$token[0], $token[1]] : [null, $token];
            if ($type === T_INLINE_HTML) {
                $html .= $text;
            } else {
                // PHP code: the HTML reader sees only what it prints. Stand in
                // a number, and keep the line breaks so line numbers still match.
                if (!str_ends_with($html, '0')) {
                    $html .= '0';
                }
                $html .= str_repeat("\n", substr_count($text, "\n"));
                if ($type === T_CONSTANT_ENCAPSED_STRING || $type === T_ENCAPSED_AND_WHITESPACE) {
                    foreach (self::leaksIn($text) as [$at, $where]) {
                        $leaks[] = [$line + $at - 1, $where];
                    }
                }
            }
            $line += substr_count($text, "\n");
        }
        return array_merge(self::leaksIn($html), $leaks);
    }

    /**
     * Every "</script" that comes inside a JavaScript comment or string of a
     * <script> block, with its line. This is the HTML reader's rule (end at
     * the first "</script", whatever it is in) set against the JavaScript
     * one (a comment or string is not code), and every place they disagree.
     *
     * @return list<array{int, string}> [line, "comment" or "string"]
     */
    private static function leaksIn(string $html): array
    {
        $leaks = [];
        $length = strlen($html);
        $i = 0;
        while (preg_match('#<script(?=[\s>/])[^>]*>#i', $html, $open, PREG_OFFSET_CAPTURE, $i)) {
            $i = $open[0][1] + strlen($open[0][0]);

            // Inside the block. $state is what the JavaScript at $i is in.
            $state = 'code';
            $before = '';       // last character of code that was not a space
            $inClass = false;   // inside [...] of a regular expression
            while ($i < $length) {
                if (strncasecmp(substr($html, $i, 8), '</script', 8) === 0) {
                    if ($state === 'code') {
                        $i += 8;
                        break;   // the end the author meant
                    }
                    $where = in_array($state, ['line', 'block'], true) ? 'comment' : 'string';
                    $leaks[] = [substr_count($html, "\n", 0, $i) + 1, $where];
                    $i += 8;
                    continue;
                }

                $c = $html[$i];
                $next = $html[$i + 1] ?? '';
                switch ($state) {
                    case 'code':
                        if ($c === '/' && $next === '/') {
                            $state = 'line';
                        } elseif ($c === '/' && $next === '*') {
                            $state = 'block';
                            $i++;
                        } elseif ($c === '/' && ($before === '' || str_contains('(,=:[!&|?{};', $before))) {
                            // A "/" where a value is expected starts a regular
                            // expression; anywhere else it divides.
                            $state = 'regex';
                        } elseif ($c === "'" || $c === '"' || $c === '`') {
                            $state = $c;
                        } elseif ($c === '\\') {
                            $i++;   // an escaped character (in a PHP string: \")
                        }
                        if (!ctype_space($c)) {
                            $before = $c;
                        }
                        break;
                    case 'line':
                        if ($c === "\n") {
                            $state = 'code';
                        }
                        break;
                    case 'block':
                        if ($c === '*' && $next === '/') {
                            $state = 'code';
                            $i++;
                        }
                        break;
                    case 'regex':
                        if ($c === '\\') {
                            $i++;
                        } elseif ($c === '[') {
                            $inClass = true;
                        } elseif ($c === ']') {
                            $inClass = false;
                        } elseif (($c === '/' && !$inClass) || $c === "\n") {
                            $state = 'code';
                            $inClass = false;
                            $before = 'x';   // a regular expression is a value
                        }
                        break;
                    default:   // a string; $state is its quote
                        if ($c === '\\') {
                            $i++;
                        } elseif ($c === $state || ($c === "\n" && $state !== '`')) {
                            $state = 'code';
                            $before = 'x';   // a string is a value
                        }
                }
                $i++;
            }
        }
        return $leaks;
    }
}
