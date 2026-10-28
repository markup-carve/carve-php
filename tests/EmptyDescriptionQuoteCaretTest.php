<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmptyDescriptionQuoteCaretTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function quotes(): iterable
    {
        yield 'empty quote' => [":: t\n: >\n^ c\n", "<blockquote>\n\n    </blockquote>\n  </dd>\n</dl>\n<p>^ c</p>"];
        yield 'nested empty quote' => [":: t\n: > >\n^ c\n", "</blockquote>\n    </blockquote>\n  </dd>\n</dl>\n<p>^ c</p>"];
        yield 'nonempty quote keeps paragraph' => [":: t\n: > a\n^ c\n", "<blockquote><p>a\n^ c</p></blockquote>"];
    }

    #[DataProvider('quotes')]
    public function testCaretUsesTheQuoteLeafParagraph(string $source, string $expected): void
    {
        self::assertStringContainsString($expected, (new CarveConverter())->convert($source));
    }
}
