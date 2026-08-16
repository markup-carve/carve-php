<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Two adjacent <ol>s import as two lists, not one.
 *
 * With one shared `.` delimiter, `<ol><li>a</li></ol><ol><li>b</li></ol>`
 * imported as `1. a` / `1. b`, which reparses as ONE loose list of two
 * items - the lists merged and the second's numbering was gone
 * (carve-php#1290). Carve separates sibling lists by their DELIM, so the
 * delimiter alternates `.`/`)` across adjacent ordered siblings, the same
 * rule bullet lists already follow with `-`/`*`.
 */
class AdjacentOrderedListsImportSeparateTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function htmlProvider(): array
    {
        return [
            'two decimal lists' => ['<ol><li>a</li></ol><ol><li>b</li></ol>'],
            'roman then decimal' => ['<ol type="i" start="2"><li>two</li></ol><ol start="3"><li>paren</li></ol>'],
            'alpha then roman' => ['<ol type="a"><li>x</li><li>y</li></ol><ol type="i" start="2"><li>two</li></ol>'],
        ];
    }

    /**
     * @param string $html
     */
    #[DataProvider('htmlProvider')]
    public function testTheImportReproducesTheHtml(string $html): void
    {
        $imported = (new HtmlToCarve())->convert($html);
        $htmlBack = (new CarveConverter())->convert($imported);

        // Normalize the item wrapping (<li><p>) the reparse may add.
        $normalize = static fn (string $h): string => preg_replace('/<\/?p>|\s+/', '', $h) ?? $h;
        $this->assertSame(
            $normalize($html),
            $normalize($htmlBack),
            "importing must keep the lists separate; imported source was:\n" . $imported,
        );
    }

    public function testTwoDecimalListsAlternateTheirDelimiter(): void
    {
        $imported = (new HtmlToCarve())->convert('<ol><li>a</li></ol><ol><li>b</li></ol>');

        $this->assertStringContainsString('1. a', $imported);
        $this->assertStringContainsString('1) b', $imported);
    }
}
