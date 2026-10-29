<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A note's `+` the walk refuses is framed lazy in the note, as the spec model's
 * `ownLines()` frames it (carve-php#4242). Expectations are the spec model's
 * HTML at carve main `dd86f2eb`; the last row guards a blank follower.
 */
class ARefusedNotePlusIsFramedLazyInTheNoteTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function rows(): array
    {
        return [
            'a refused plus keeps the line after it outside the note' => [
                "[^f]: a\n+\n [r]:\n.\n",
                '<p>.</p>',
            ],
            'a refused plus before an indented quote' => [
                "[^f]: a\n+\n > q\n.\n",
                "<blockquote><p>q\n.</p></blockquote>",
            ],
            'a refused plus before an indented fence' => [
                "[^f]: a\n+\n ```\n c\n ```\n",
                "<pre><code>c\n</code></pre>",
            ],
            'a refused plus before an indented heading' => [
                "[^f]: a\n+\n # h\nsee [^f]\n",
                "<section id=\"h\"><h1>h</h1><p>see <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p></section><section role=\"doc-endnotes\" aria-label=\"Footnotes\"><hr><ol><li id=\"fn1\"><p>a\n+<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p></li></ol></section>",
            ],
            'a refused plus after a two-line note' => [
                "[^f]: a\n  b\n+\n [r]:\n.\n",
                '<p>.</p>',
            ],
            'a refused plus after a note term' => [
                "[^f]: :: t\n+\n > q\n.\n",
                "<blockquote><p>q\n.</p></blockquote>",
            ],
            'a refused plus in a quoted note' => [
                "> [^f]: a\n> +\n>  > q\n> .\n",
                "<blockquote><blockquote><p>q\n.</p></blockquote></blockquote>",
            ],
            'a refused plus before a blank stays a refused marker' => [
                "x[^a]\n\n[^a]: note\n+\n\npara\n",
                "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p><p>para</p><section role=\"doc-endnotes\" aria-label=\"Footnotes\"><hr><ol><li id=\"fn1\"><p>note\n+<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p></li></ol></section>",
            ],
        ];
    }

    #[DataProvider('rows')]
    public function testHtml(string $source, string $expected): void
    {
        $html = CarveConverter::create()->convert($source);

        $this->assertSame($expected, trim((string)preg_replace('/>\s+</', '><', $html)));
    }
}
