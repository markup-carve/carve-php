<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarkdownQuoteBlankRunResetsAcrossRunsTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function separatedRuns(): array
    {
        return [
            'bare second quote' => ["> a\n\n>\n", "> a\n\n>\n"],
            'plain line after second quote' => ["> a\n\n>\nplain\n", "> a\n\n>\n\nplain\n"],
        ];
    }

    #[DataProvider('separatedRuns')]
    public function testASecondRunStartsWithoutContentAtItsPrefix(string $markdown, string $expected): void
    {
        self::assertSame($expected, (new MarkdownToCarve())->convert($markdown));
    }
}
