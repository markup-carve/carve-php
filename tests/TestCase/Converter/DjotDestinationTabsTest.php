<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DjotDestinationTabsTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function destinations(): array
    {
        return [
            'leading tab' => ["[x](\thttps://example.org/x)", '<p><a href="https://example.org/x">x</a></p>'],
            'internal tab' => ["[x](a\tb)", '<p><a href="ab">x</a></p>'],
            'trailing tab' => ["[x](a\t)", '<p><a href="a">x</a></p>'],
            'tab-only destination' => ["[x](\t)", '<p><a href="">x</a></p>'],
            'leading backslash and tab' => ["[x](\\\thttps://example.org/x)", '<p><a href="\\https://example.org/x">x</a></p>'],
            'internal backslash and tab' => ["[x](a\\\tb)", '<p><a href="a\\b">x</a></p>'],
            'trailing backslash and tab' => ["[x](a\\\t)", '<p><a href="a\\">x</a></p>'],
            'two backslashes and tab' => ["[x](a\\\\\t!b)", '<p><a href="a\\!b">x</a></p>'],
            'three backslashes and tab' => ["[x](a\\\\\\\t!b)", '<p><a href="a\\\\!b">x</a></p>'],
            'tab before escaped line end' => ["[x](a\\\t\nb)", '<p><a href="ab">x</a></p>'],
            'space after backslash before line end' => ["[x](a\\\t \nb)", '<p><a href="a%20b">x</a></p>'],
            'space before escaped line end' => ["[x](a \\\t\nb)", '<p><a href="ab">x</a></p>'],
            'image destination' => ["![x](a\\\tb)", '<p><img src="a\\b" alt="x"></p>'],
            'tab-only image destination' => ["![x](\t)", '<p><img src="" alt="x"></p>'],
            'escaped internal space' => ['[x](a\\ b)', '<p><a href="a%20b">x</a></p>'],
        ];
    }

    #[DataProvider('destinations')]
    public function testTabsDoNotChangeResolvedDestination(string $source, string $expected): void
    {
        $carve = (new DjotToCarve())->convert($source);
        self::assertSame($expected, trim((new CarveConverter())->convert($carve)));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function imageDestinationsWithLostAltWhitespace(): array
    {
        return [
            'tab-only' => ["![x  \ny](\t)"],
            'empty inline' => ["![x  \ny]()"],
            'line end inline' => ["![x  \ny](\n)"],
            'empty reference' => ["![x  \ny][r]\n\n[r]:"],
        ];
    }

    #[DataProvider('imageDestinationsWithLostAltWhitespace')]
    public function testImageReportsLostAltTextWhitespace(string $source): void
    {
        $result = (new DjotToCarve())->convertWithFidelityReport($source);
        self::assertContains(
            'An image alternative text loses trailing spaces before a line break.',
            array_column($result->report()['diagnostics'], 'message'),
        );
    }

    public function testUnresolvedReferenceDoesNotReportImageAltTextLoss(): void
    {
        $result = (new DjotToCarve())->convertWithFidelityReport("![x  \ny][missing]");
        $messages = array_column($result->report()['diagnostics'], 'message');
        self::assertContains('An unresolved Djot image reference has no src; Carve cannot spell that image.', $messages);
        self::assertNotContains('An image alternative text loses trailing spaces before a line break.', $messages);
    }
}
