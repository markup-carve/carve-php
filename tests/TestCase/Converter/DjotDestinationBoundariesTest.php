<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DjotDestinationBoundariesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function destinationProvider(): iterable
    {
        $rows = json_decode(file_get_contents(__DIR__ . '/../../fixtures/djot-destination-boundaries.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($rows as $row) {
            yield $row['name'] => [$row['source'], $row['html']];
        }
    }

    #[DataProvider('destinationProvider')]
    public function testDestinationBoundaries(string $source, string $expected): void
    {
        $html = trim((new CarveConverter())->convert((new DjotToCarve())->convert($source)));
        $html = preg_replace_callback('/(?:href|src)="([^"]*)"/', fn (array $match): string => str_replace($match[1], strtr($match[1], ['(' => '%28', ')' => '%29', '`' => '%60']), $match[0]), $html);
        $html = str_replace([' aria-label="Footnotes"', ' aria-label="Back to reference"'], '', $html);
        $html = str_replace('&nbsp;', "\u{00a0}", $html);
        $html = preg_replace('/<\/?tbody>/', '', $html);
        $html = preg_replace('/>\s+</', '><', $html);
        $html = preg_replace('/\n[ \t]+(<[ou]l>)/', "\n$1", $html);
        $expected = str_replace('&nbsp;', "\u{00a0}", $expected);
        $expected = preg_replace('/<img alt="([^"]*)" src="([^"]*)">/', '<img src="$2" alt="$1">', $expected);
        self::assertSame($expected, $html);
    }

    public function testLinkImmediatelyAfterFootnote(): void
    {
        $html = (new CarveConverter())->convert((new DjotToCarve())->convert("note[^1][t](u~x~y)\n\n[^1]: note"));
        self::assertStringContainsString('<a href="u~x~y">t</a>', $html);
        self::assertStringContainsString('<li id="fn1"><p>note', preg_replace('/>\s+</', '><', $html));
    }

    public function testPercentEscapesStayExact(): void
    {
        $source = '[t](u%28x%29y)';
        $converted = (new DjotToCarve())->convert($source);
        self::assertSame($source, $converted);
        self::assertStringContainsString('href="u%28x%29y"', (new CarveConverter())->convert($converted));
    }

    public function testDestinationMaskKeepsOpaqueParenthesesAndFollowingText(): void
    {
        $converter = new class extends DjotToCarve {
            public function mask(string $source, bool $inlineForms = true): string
            {
                return $this->maskCodeAndDestinations($source, $inlineForms);
            }
        };
        foreach (['u`)`z', 'u{a=")"}x'] as $target) {
            self::assertSame('[t]' . str_repeat(' ', strlen($target) + 2) . ' and ~x~ later', $converter->mask('[t](' . $target . ') and ~x~ later'));
        }
        self::assertSame('[t](u~x~)', $converter->mask('[t](u~x~)', false));
    }

    public function testFootnoteSuffixParenthesesRemainText(): void
    {
        foreach (['', '!'] as $prefix) {
            $html = (new CarveConverter())->convert((new DjotToCarve())->convert($prefix . "[^n](a ~b~ c)\n\n[^n]: note"));
            self::assertStringContainsString('(a <sub>b</sub> c)', $html);
            self::assertStringContainsString('href="#fn1"', $html);
            if ($prefix !== '') {
                self::assertStringContainsString('<p>!<a id="fnref1"', $html);
            }
        }
    }

    public function testOuterDestinationsAfterNestedLinks(): void
    {
        foreach (
            [
                ['[[a](u)](v w)', '[[a](u)](v%20w)'],
                ['[x [a](u) y](v"w)', '[x [a](u) y](v%22w)'],
            ] as [$source, $expected]
        ) {
            self::assertSame($expected, (new DjotToCarve())->convert($source));
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function paddedDestinationProvider(): iterable
    {
        yield 'leading space in absolute link' => ['[x]( https://example.org/x)', '<p><a href="https://example.org/x">x</a></p>'];
        yield 'leading tab in absolute link' => ["[x](\thttps://example.org/x)", '<p><a href="https://example.org/x">x</a></p>'];
        yield 'escaped leading space in absolute link' => ['[x](\ https://example.org/x)', '<p><a href="https://example.org/x">x</a></p>'];
        yield 'trailing space in absolute link' => ['[x](https://example.org/x )', '<p><a href="https://example.org/x">x</a></p>'];
        yield 'escaped trailing space in absolute link' => ['[x](https://example.org/x\ )', '<p><a href="https://example.org/x">x</a></p>'];
        yield 'even backslashes before trailing space' => ['[x](a\\\\ )', '<p><a href="a\\">x</a></p>'];
        yield 'odd backslashes before trailing space' => ['[x](a' . str_repeat('\\', 3) . ' )', '<p><a href="a\\">x</a></p>'];
        yield 'backslash before trailing tab stays literal' => ["[x](a\\\t)", '<p><a href="a\\">x</a></p>'];
        yield 'padding after a line break' => ["[x](\n https://example.org/x )", '<p><a href="https://example.org/x">x</a></p>'];
        yield 'whitespace-only destination' => ['[x]( )', '<p><a href="">x</a></p>'];
        yield 'leading space in image' => ['![x]( /logo.svg)', '<p><img src="/logo.svg" alt="x"></p>'];
        yield 'internal space remains in target' => ['[x]( https://example.org/a b )', '<p><a href="https://example.org/a%20b">x</a></p>'];
    }

    #[DataProvider('paddedDestinationProvider')]
    public function testDestinationPaddingKeepsTheResolvedUrl(string $source, string $expectedHtml): void
    {
        $carve = (new DjotToCarve())->convert($source);

        self::assertSame($expectedHtml, trim((new CarveConverter())->convert($carve)));
    }
}
