<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `+` line is no blank line: the content after the block it attaches neither
 * loosens the list (PART 9 §17 L1) nor continues an attached term lazily.
 */
class ContentAfterAPlusAttachedBlockTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function shapes(): iterable
    {
        yield 'term then item text' => [
            "- a\n+\n:: term\n  x\n",
            "<ul>\n  <li>a\n    <dl>\n      <dt>term</dt>\n    </dl>\n    x\n  </li>\n</ul>",
        ];

        yield 'heading then item text' => [
            "- a\n+\n# h\n  x\n",
            "<ul>\n  <li>a\n    <h1 id=\"h\">h</h1>\n    x\n  </li>\n</ul>",
        ];

        yield 'fence then item text' => [
            "- a\n+\n```\nc\n```\n  x\n",
            "<ul>\n  <li>a\n    <pre><code>c\n</code></pre>\n    x\n  </li>\n</ul>",
        ];

        yield 'note then item text' => [
            "- a\n+\n::: note\nn\n:::\n  x\n",
            "<ul>\n  <li>a\n    <aside class=\"admonition note\" aria-label=\"Note\">\n      <p>n</p>\n    </aside>\n    x\n  </li>\n</ul>",
        ];

        yield 'table then item text in an ordered item' => [
            "1. a\n+\n| t |\n   x\n",
            "<ol>\n  <li>a\n    <table>\n      <tbody>\n        <tr><td>t</td></tr>\n      </tbody>\n    </table>\n    x\n  </li>\n</ol>",
        ];

        yield 'blank before the text still loosens' => [
            "- a\n+\n# h\n\n  x\n",
            "<ul>\n  <li><p>a</p>\n    <h1 id=\"h\">h</h1>\n    <p>x</p>\n  </li>\n</ul>",
        ];

        yield 'quoted term then an unmarked line' => [
            "> a\n+\n:: term\nx\n",
            "<blockquote>\n  <p>a</p>\n  <dl>\n    <dt>term</dt>\n  </dl>\n</blockquote>\n<p>x</p>",
        ];

        yield 'nested quote term then an unmarked line' => [
            "> > a\n+\n:: term\nx\n",
            "<blockquote>\n  <blockquote><p>a</p></blockquote>\n  <dl>\n    <dt>term</dt>\n  </dl>\n</blockquote>\n<p>x</p>",
        ];
    }

    #[DataProvider('shapes')]
    public function testRendersLikeTheSpecModel(string $source, string $expected): void
    {
        $this->assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
