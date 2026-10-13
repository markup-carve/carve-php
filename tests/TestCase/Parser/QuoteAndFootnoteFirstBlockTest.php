<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QuoteAndFootnoteFirstBlockTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'quote takes table' => ["> +\n|= a | < |= b |\n", '<blockquote>'];
        yield 'quote takes paragraph' => ["> +\nflush\n", '<blockquote><p>flush</p></blockquote>'];
        yield 'note takes fence' => ["see[^a]\n\n[^a]: +\n```\nx\n```\n", '<pre><code>x'];
        yield 'attached paragraph ends before next quoted line' => ["> +\nflush\n> more\n", "<p>flush</p>\n  <p>more</p>"];
        yield 'nested quote releases line after fence' => ["> > +\n```\nx\n```\nlazy\n", "</blockquote>\n<p>lazy</p>"];
        yield 'bare quote keeps plus' => ["> +\n", '<blockquote><p>+</p></blockquote>'];
        yield 'quote before blank keeps plus' => ["> +\n\nafter\n", '<blockquote><p>+</p></blockquote>'];
        yield 'bare note keeps plus' => ["see[^a]\n\n[^a]: +\n", '<p>+<a href='];
    }

    #[DataProvider('cases')]
    public function testFirstBlockForm(string $source, string $expected): void
    {
        self::assertStringContainsString($expected, (new CarveConverter())->convert($source));
    }
}
