<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\Utility\AttributeParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Robustness tests for oversized quoted attribute values.
 *
 * The quoted-value sub-pattern was previously written as the catastrophic
 * alternation-inside-star shape `(?:[^"\]|\.)*`. With the PCRE JIT enabled a
 * quoted value of a few thousand characters made `preg_match*` return `false`
 * with PREG_JIT_STACKLIMIT_ERROR, and the callers treated that engine failure
 * as "no match" - SILENTLY DROPPING every attribute on the element (including
 * security-relevant ones such as rel="noopener" or a CSP nonce) and leaking the
 * literal `{...}` into the output where it mis-parsed as inline markup.
 *
 * The sub-pattern is now the linear unrolled form `[^"\]*(?:\.[^"\]*)*`, which
 * matches in linear time and never trips the JIT stack limit.
 */
class AttributeParserLargeValueTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function largeValueProvider(): array
    {
        return [
            'tiny' => ['a', 1],
            'short' => [str_repeat('b', 64), 64],
            // Around and well past the historical JIT-stacklimit threshold.
            'threshold' => [str_repeat('c', 8000), 8000],
            'large' => [str_repeat('d', 15000), 15000],
            'huge' => [str_repeat('e', 30000), 30000],
        ];
    }

    /**
     * A large valid quoted value is preserved verbatim, never dropped.
     */
    #[DataProvider('largeValueProvider')]
    public function testLargeQuotedValueIsPreserved(string $value, int $expectedLength): void
    {
        $result = AttributeParser::parseOrdered('title="' . $value . '"');

        $this->assertArrayHasKey('title', $result);
        $this->assertSame($expectedLength, strlen($result['title']));
        $this->assertSame($value, $result['title']);
    }

    /**
     * parseOrdered keeps the attributes that follow an oversized value.
     *
     * This is the worst case: when the engine error happened on a single-pass
     * match it dropped ALL attributes, including the trailing security-relevant
     * ones. Here `id` and `rel` must survive the giant `title`.
     */
    #[DataProvider('largeValueProvider')]
    public function testTrailingAttributesSurviveLargeValue(string $value, int $expectedLength): void
    {
        $result = AttributeParser::parseOrdered(
            'title="' . $value . '" id=keep rel="noopener noreferrer"',
        );

        $this->assertSame($expectedLength, strlen($result['title'] ?? ''));
        $this->assertSame('keep', $result['id'] ?? null);
        $this->assertSame('noopener noreferrer', $result['rel'] ?? null);
    }

    /**
     * The single-quoted variant is equally linear and preserves the value.
     */
    #[DataProvider('largeValueProvider')]
    public function testLargeSingleQuotedValueIsPreserved(string $value, int $expectedLength): void
    {
        $result = AttributeParser::parseOrdered("title='" . $value . "' id=keep");

        $this->assertSame($expectedLength, strlen($result['title'] ?? ''));
        $this->assertSame('keep', $result['id'] ?? null);
    }

    /**
     * End to end: a large attribute-shaped block glued to a bare word stays
     * literal and does not become a span.
     */
    public function testLargeAttributeDoesNotLeakBraces(): void
    {
        $value = str_repeat('z', 15000);
        $html = $this->converter->convert('word{.realclass #realid title="' . $value . '"}');

        $this->assertStringContainsString('{.realclass', $html);
        $this->assertStringNotContainsString('class="realclass"', $html);
        $this->assertStringNotContainsString('id="realid"', $html);
        $this->assertStringContainsString('title=“' . $value . '”', $html);
    }

    /**
     * Edge cases: empty value, escaped quotes inside a large value, and several
     * large attributes on one element.
     */
    public function testEmptyQuotedValueIsKept(): void
    {
        $result = AttributeParser::parseOrdered('title="" id=keep');

        $this->assertArrayHasKey('title', $result);
        $this->assertSame('', $result['title']);
        $this->assertSame('keep', $result['id'] ?? null);
    }

    public function testEscapedQuotesInsideLargeValue(): void
    {
        // A long run, an escaped quote, then more text. The escaped quote must
        // not terminate the value, and a following attribute must survive.
        $value = str_repeat('a', 9000) . '\\"' . str_repeat('b', 9000);
        $result = AttributeParser::parseOrdered('title="' . $value . '" id=keep');

        // processEscapes turns \" into a literal ".
        $this->assertSame(str_repeat('a', 9000) . '"' . str_repeat('b', 9000), $result['title'] ?? '');
        $this->assertSame('keep', $result['id'] ?? null);
    }

    public function testMultipleLargeAttributesAllPreserved(): void
    {
        $a = str_repeat('a', 12000);
        $b = str_repeat('b', 12000);
        $result = AttributeParser::parseOrdered('data-a="' . $a . '" data-b="' . $b . '" id=keep');

        $this->assertSame($a, $result['data-a'] ?? '');
        $this->assertSame($b, $result['data-b'] ?? '');
        $this->assertSame('keep', $result['id'] ?? null);
    }

    /**
     * The same robustness holds for the parse() (non-ordered) entry point.
     */
    #[DataProvider('largeValueProvider')]
    public function testParseKeepsTrailingAttributesAfterLargeValue(string $value, int $expectedLength): void
    {
        $result = AttributeParser::parse('title="' . $value . '" id=keep rel="noopener"');

        $this->assertSame($expectedLength, strlen($result['title'] ?? ''));
        $this->assertSame('keep', $result['id'] ?? null);
        $this->assertSame('noopener', $result['rel'] ?? null);
    }
}
