<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Corpus section 580 (markup-carve/carve#3056): a quote, term, heading or other
 * block at a marker-line note's body column opens in the note, and the note
 * keeps the lines that follow at that column.
 */
class ABlockAtAMarkerLineNotesBodyColumnOpensInTheNoteTest extends TestCase
{
    use RuleATodo;

    /**
     * @var string
     */
    private const BACKLINK = '<p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rows(): iterable
    {
        $ref = '<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a>';
        $notes = '<section role="doc-endnotes" aria-label="Footnotes"><hr><ol><li id="fn1">';

        yield 'a quote, with a fence in its lazy paragraph' => [
            "- [^n]: text\n    > quote\n      ```\n      code\n      ```\nafter [^n]\n",
            '<ul><li></li></ul><p>after ' . $ref . '</p>' . $notes
                . "<p>text</p><blockquote><p>quote\n<code>\ncode\n</code></p></blockquote>" . self::BACKLINK . '</li></ol></section>',
        ];

        yield 'a heading after a term, then a fence past the column' => [
            "- [^n]: :: term\n    # h\n        ```\n        code\n        ```\nafter [^n]\n",
            '<ul><li></li></ul><p>after ' . $ref . '</p>' . $notes
                . "<dl><dt>term</dt></dl><h1 id=\"h\">h</h1><pre><code>code\n</code></pre>" . self::BACKLINK . '</li></ol></section>',
        ];

        yield 'an unclosed fence after a term opens and runs to the note end' => [
            "- [^a]: text\n    more\n    [^b]: \n      :: t\n      ~~~\n      code\nafter [^a] [^b]\n",
            '<ul><li></li></ul><p>after ' . $ref . ' [^b]</p>' . $notes
                . "<p>text\nmore\n[^b]:</p><dl><dt>t</dt></dl><pre><code>code\n</code></pre>" . self::BACKLINK . '</li></ol></section>',
        ];
    }

    #[DataProvider('rows')]
    public function testTheBlockOpensInTheNote(string $source, string $expected): void
    {
        $html = trim((string)preg_replace('/>\s+</', '><', (new CarveConverter())->convert($source)));

        $this->assertSame($expected, $html);
    }
}
