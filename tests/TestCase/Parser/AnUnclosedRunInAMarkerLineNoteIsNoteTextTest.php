<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An unclosed fence run at or past a marker-line note's body column, inside the
 * note's open paragraph, is that paragraph's text; the line below leaves the list.
 */
class AnUnclosedRunInAMarkerLineNoteIsNoteTextTest extends TestCase
{
    /**
     * @var string
     */
    private const BACKLINK = '<a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a>';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rows(): iterable
    {
        $ref = '<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a>';
        $notes = '<section role="doc-endnotes" aria-label="Footnotes"><hr><ol><li id="fn1">';

        yield 'at the body column, right under the marker line' => [
            "- [^n]: text\n    ```\n    code\nafter [^n]\n",
            '<ul><li></li></ul><p>after ' . $ref . '</p>' . $notes
                . "<p>text\n<code>\ncode</code>" . self::BACKLINK . '</p></li></ol></section>',
        ];

        yield 'after folded marker lines and an empty definition marker' => [
            "- [^a]: text\n    - child\n    [^b]: \n      - c2\n    ~~~\n    code\nafter [^a] [^b]\n",
            '<ul><li></li></ul><p>after ' . $ref . ' [^b]</p>' . $notes
                . "<p>text\n- child\n[^b]:\n- c2\n~~~\ncode" . self::BACKLINK . '</p></li></ol></section>',
        ];
    }

    #[DataProvider('rows')]
    public function testTheRunStaysInTheNoteParagraph(string $source, string $expected): void
    {
        $html = trim((string)preg_replace('/>\s+</', '><', (new CarveConverter())->convert($source)));

        $this->assertSame($expected, $html);
    }
}
