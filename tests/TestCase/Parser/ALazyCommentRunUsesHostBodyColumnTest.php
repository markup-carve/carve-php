<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CARVE-P2-035 measures a lazy block opener's continuation at its host body column.
 */
final class ALazyCommentRunUsesHostBodyColumnTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function shapes(): iterable
    {
        $note = '<p>x<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>' . "\n"
            . '<section role="doc-endnotes" aria-label="Footnotes">' . "\n"
            . "  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>b\n"
            . '<a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>' . "\n"
            . "    </li>\n  </ol>\n</section>";

        yield 'note' => ["[^n]: b\n{% c\n  z %}\n\nx[^n]\n", $note];
        yield 'quoted note' => ["> [^n]: b\n> {% c\n>   z %}\n\nx[^n]\n", "<blockquote>\n\n</blockquote>\n" . $note];
        yield 'quoted description' => [
            "> :: t\n> : pre\n> {% b\n>   b %}\n",
            "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>pre\n</dd>\n  </dl>\n</blockquote>",
        ];
    }

    #[DataProvider('shapes')]
    public function testReachedContinuationHidesTheRun(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
