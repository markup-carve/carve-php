<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve#4870.
 */
class ASkippedCommentFenceEndsAtALineThatMissesTheHostTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provider(): array
    {
        return [
            'blank line ends the quote' => [
                "> - +\n> %%%\n\n> %%%\n> text\n",
                "<blockquote>\n  <ul>\n    <li></li>\n  </ul>\n</blockquote>\n<blockquote>\n\n</blockquote>",
            ],
            'unprefixed line ends the quote' => [
                "> - +\n%%%\n> %%%\n> text\n",
                "<blockquote>\n  <ul>\n    <li></li>\n  </ul>\n</blockquote>",
            ],
            'below-column line ends the description' => [
                ":: t\n: +\n  %%%\nq\n  %%%\n  text\n",
                "<dl>\n  <dt>t</dt>\n  <dd>q</dd>\n</dl>",
            ],
            'a fence inside the host still skips' => [
                "> - +\n> %%%\n> x\n> %%%\n> text\n",
                "<blockquote>\n  <ul>\n    <li>text</li>\n  </ul>\n</blockquote>",
            ],
        ];
    }

    #[DataProvider('provider')]
    public function testTheSpan(string $source, string $html): void
    {
        $this->assertSame($html, trim((new CarveConverter())->convert($source)));
    }
}
