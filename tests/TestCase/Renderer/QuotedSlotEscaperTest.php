<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Parser\Utility\AttributeParser;
use MarkupCarve\Carve\Renderer\Utility\QuotedSlotEscaper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every expectation here is spelled out per value rather than derived, so the
 * test cannot echo whichever rule the writer happens to hold.
 */
class QuotedSlotEscaperTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function valueProvider(): array
    {
        return [
            'a backslash before a letter stays bare' => ['t\zu', 't\zu'],
            'a backslash before a digit stays bare' => ['t\1u', 't\1u'],
            'a backslash before a space stays bare' => ['t\ u', 't\ u'],
            'a backslash before punctuation doubles' => ['t\}u', 't\\\\}u'],
            'a backslash pair doubles only the paired half' => ['t\\\\u', 't\\\\\\u'],
            'a trailing backslash doubles' => ['t\\', 't\\\\'],
            'a lone backslash doubles' => ['\\', '\\\\'],
            'a quote is escaped' => ['a"b', 'a\"b'],
            'a backslash before a quote doubles, and the quote escapes' => ['a\"b', 'a\\\\\"b'],
            'plain text is untouched' => ['abc', 'abc'],
        ];
    }

    #[DataProvider('valueProvider')]
    public function testEscapesOnlyWhatTheReParseNeeds(string $value, string $expected): void
    {
        $this->assertSame($expected, QuotedSlotEscaper::escape($value));
    }

    #[DataProvider('valueProvider')]
    public function testTheReaderRecoversTheValue(string $value, string $expected): void
    {
        $this->assertSame($value, AttributeParser::processEscapes($expected));
    }

    public function testAPipeEscapesWhereTheSlotAsksForIt(): void
    {
        $this->assertSame('a\|b', QuotedSlotEscaper::escape('a|b', '"|'));
        $this->assertSame('a|b', QuotedSlotEscaper::escape('a|b'));
    }
}
