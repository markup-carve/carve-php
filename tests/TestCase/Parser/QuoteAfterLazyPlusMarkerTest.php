<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QuoteAfterLazyPlusMarkerTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function attachedQuotes(): array
    {
        return [
            'empty' => ["> a\n  x\n+\n>\n", "<blockquote><p>a\nx\n+</p></blockquote>"],
            'nonempty' => ["> a\n  x\n+\n> q\n", "<blockquote>\n  <p>a\nx</p>\n  <blockquote><p>q</p></blockquote>\n</blockquote>"],
        ];
    }

    #[DataProvider('attachedQuotes')]
    public function testQuoteAttachesAfterLazyParagraph(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
