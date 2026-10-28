<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InvalidCodeIncludeContainerLayoutTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function containers(): iterable
    {
        yield 'tight list' => ['- {{ x @code @lines:0-2 }}', "<ul>\n  <li>{{ x @code @lines:0-2 }}</li>\n</ul>"];
        yield 'quote' => ['> {{ x @code @lines:0-2 }}', '<blockquote><p>{{ x @code @lines:0-2 }}</p></blockquote>'];
        yield 'nested list' => ["- a\n  - {{ x @code @lines:0-2 }}", '<li>{{ x @code @lines:0-2 }}</li>'];
    }

    #[DataProvider('containers')]
    public function testInvalidSelectorUsesOrdinaryParagraphLayout(string $source, string $expected): void
    {
        self::assertStringContainsString($expected, (new CarveConverter())->convert($source));
    }
}
