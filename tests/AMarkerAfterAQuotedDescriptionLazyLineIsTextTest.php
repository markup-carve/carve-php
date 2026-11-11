<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Once a quoted line misses a description's body inside its quote, a later marker that misses
 * the quote too continues that paragraph as text (PART 0 S4, carve-php#5161).
 */
final class AMarkerAfterAQuotedDescriptionLazyLineIsTextTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function sources(): iterable
    {
        yield 'lazy line at the quote column' => ["> :: t\n> : a\n> z\n- b\n", '<blockquote><dl><dt>t</dt><dd>a z - b</dd></dl></blockquote>'];
        yield 'lazy line one column right' => ["> :: t\n> : a\n>  z\n1. b\n", '<blockquote><dl><dt>t</dt><dd>a z 1. b</dd></dl></blockquote>'];
        yield 'a reaching line after it' => ["> :: t\n> : a\n> z\n>   q\n- b\n", '<blockquote><dl><dt>t</dt><dd>a z q - b</dd></dl></blockquote>'];
        yield 'unquoted lines after it' => ["> :: t\n> : a\n> z\nw\n- b\n", '<blockquote><dl><dt>t</dt><dd>a z w - b</dd></dl></blockquote>'];
        yield 'a quote in the description' => ["> :: t\n> : > a\n> z\n- b\n", '<blockquote><dl><dt>t</dt><dd><blockquote><p>a z - b</p></blockquote></dd></dl></blockquote>'];
        yield 'a nested quote' => ["> > :: t\n> > : a\n> > z\n> w\n- b\n", '<blockquote><blockquote><dl><dt>t</dt><dd>a z w - b</dd></dl></blockquote></blockquote>'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function lists(): iterable
    {
        yield 'no lazy line' => ["> :: t\n> : a\n- b\n", '</blockquote><ul><li>b</li></ul>'];
        yield 'a quoted marker' => ["> :: t\n> : a\n> z\n> - b\n", '</dl><ul><li>b</li></ul></blockquote>'];
        yield 'a sibling description' => ["> :: t\n> : a\n> z\n> : c\n- b\n", '</blockquote><ul><li>b</li></ul>'];
        yield 'an item host' => ["> - :: t\n>   : a\n>   z\n- b\n", '</blockquote><ul><li>b</li></ul>'];
        yield 'a lazy line past an inner quote' => ["> > :: t\n> > : a\n> z\n- b\n", '</blockquote></blockquote><ul><li>b</li></ul>'];
    }

    #[DataProvider('sources')]
    public function testTheMarkerIsText(string $source, string $expected): void
    {
        self::assertStringContainsString($expected, self::html($source), $source);
    }

    #[DataProvider('lists')]
    public function testTheMarkerStillOpensAList(string $source, string $expected): void
    {
        self::assertStringContainsString($expected, self::html($source), $source);
    }

    private static function html(string $source): string
    {
        return trim((string)preg_replace(['/\s*(<[^>]+>)\s*/', '/\s+/'], ['$1', ' '], (new CarveConverter())->convert($source)));
    }
}
