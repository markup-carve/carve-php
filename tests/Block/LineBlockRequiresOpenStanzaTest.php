<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Block;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LineBlockRequiresOpenStanzaTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function containers(): array
    {
        return [
            'quote' => ["> ::: |\na\n", "<blockquote>\n  <div class=\"line-block\">\n\n  </div>\n</blockquote>\n<p>a</p>"],
            'list item' => ["- ::: |\na\n", "<ul>\n  <li>\n    <div class=\"line-block\">\n\n    </div>\n  </li>\n</ul>\n<p>a</p>"],
        ];
    }

    #[DataProvider('containers')]
    public function testUnownedLineNeedsAnOpenStanza(string $source, string $expected): void
    {
        $this->assertSame($expected, Carve::toHtml($source));
    }

    public function testStanzaWithOneLineStillTakesLazyContinuation(): void
    {
        $this->assertSame(
            "<blockquote>\n  <div class=\"line-block\">\n    <p>x<br>\na</p>\n  </div>\n</blockquote>",
            Carve::toHtml("> ::: |\n> x\na\n"),
        );
    }
}
