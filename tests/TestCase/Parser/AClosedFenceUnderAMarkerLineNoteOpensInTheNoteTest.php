<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A marker-line note's body column is two past the definition's column; a closed
 * fence at or past it opens in the note, and the line below leaves the list.
 */
class AClosedFenceUnderAMarkerLineNoteOpensInTheNoteTest extends TestCase
{
    /**
     * @var string
     */
    private const NOTE_REF = '<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a>';

    /**
     * @var string
     */
    private const BACKLINK = '<p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rows(): iterable
    {
        $notes = '<section role="doc-endnotes" aria-label="Footnotes"><hr><ol><li id="fn1">';

        yield 'past the column, after a folded marker line' => [
            "- [^n]: text\n    - child\n      ```\n      code\n      ```\nafter [^n]\n",
            '<ul><li></li></ul><p>after ' . self::NOTE_REF . '</p>' . $notes
                . "<p>text\n- child</p><pre><code>code\n</code></pre>" . self::BACKLINK . '</li></ol></section>',
        ];

        yield 'at the column, directly under the marker line' => [
            "- [^n]: text\n    ```\n    code\n    ```\nafter [^n]\n",
            '<ul><li></li></ul><p>after ' . self::NOTE_REF . '</p>' . $notes
                . "<p>text</p><pre><code>code\n</code></pre>" . self::BACKLINK . '</li></ol></section>',
        ];

        yield 'below a child list, past the note column' => [
            "- [^n]: %% c\n    - child\n     ```\n     code\n     ```\nafter [^n]\n",
            '<ul><li></li></ul><p>after ' . self::NOTE_REF . '</p>' . $notes
                . "<ul><li>child</li></ul><pre><code>code\n</code></pre>" . self::BACKLINK . '</li></ol></section>',
        ];

        yield 'at the content column of a list the note opened' => [
            "- [^n]: :: term\n    - child\n      ```\n      code\n      ```\nafter [^n]\n",
            '<ul><li></li></ul><p>after ' . self::NOTE_REF . '</p>' . $notes
                . "<dl><dt>term</dt></dl><ul><li>child\n          <pre><code>code\n</code></pre></li></ul>" . self::BACKLINK . '</li></ol></section>',
        ];

        yield 'at the content column of a list on the marker line' => [
            "- [^n]: - child\n      ```\n      code\n      ```\nafter [^n]\n",
            '<ul><li></li></ul><p>after ' . self::NOTE_REF . '</p>' . $notes
                . "<ul><li>child\n          <pre><code>code\n</code></pre></li></ul>" . self::BACKLINK . '</li></ol></section>',
        ];

        yield 'after a continuation line of the note paragraph' => [
            "- [^n]: text\n    plain\n    ```\n    code\n    ```\nafter [^n]\n",
            '<ul><li></li></ul><p>after ' . self::NOTE_REF . '</p>' . $notes
                . "<p>text\nplain</p><pre><code>code\n</code></pre>" . self::BACKLINK . '</li></ol></section>',
        ];

        yield 'in a description body the note opened after a blank' => [
            "- [^n]: :: term\n\n    : desc\n      ```\n      code\n      ```\nafter [^n]\n",
            '<ul><li></li></ul><p>after ' . self::NOTE_REF . '</p>' . $notes
                . "<dl><dt>term</dt><dd><p>desc</p><pre><code>code\n</code></pre></dd></dl>" . self::BACKLINK . '</li></ol></section>',
        ];

        yield 'inside a quote' => [
            "> - [^n]: text\n>     ```\n>     code\n>     ```\n> after [^n]\n",
            '<blockquote><ul><li></li></ul><p>after ' . self::NOTE_REF . '</p></blockquote>' . $notes
                . "<p>text</p><pre><code>code\n</code></pre>" . self::BACKLINK . '</li></ol></section>',
        ];
    }

    #[DataProvider('rows')]
    public function testTheNoteOwnsTheFence(string $source, string $expected): void
    {
        $html = (new CarveConverter())->convert($source);

        $this->assertSame($expected, trim((string)preg_replace('/>\s+</', '><', $html)));
    }
}
