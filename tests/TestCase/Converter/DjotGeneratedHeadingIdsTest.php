<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use MarkupCarve\Carve\Converter\HeadingId\HtmlHeadingIds;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DjotGeneratedHeadingIdsTest extends TestCase
{
    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function headings(): array
    {
        return [
            'punctuation' => ["### PHP\n\ntext\n\n#### PHP:\n\ntext\n", ['PHP', 'PHP:']],
            'duplicate text' => ["# Foo\n\n# Foo", ['Foo', 'Foo-1']],
            'duplicate plus explicit id' => ["# Foo\n\n# Foo\n\n{#custom}\n# Foo\n\n# Foo", ['Foo', 'Foo-1', 'custom', 'Foo-2']],
            'explicit nonheading id reserves name' => ["{#Foo}\nparagraph\n\n# Foo", ['Foo-1']],
            'unicode and quotation mark' => ["# Café\n\n# a\"b", ['Café', 'a"b']],
            'punctuation only' => ['# !', ['s-1']],
            'list heading before duplicate' => ["- # A\n\n# A", ['A', 'A-1']],
        ];
    }

    #[DataProvider('headings')]
    public function testGeneratedIdsMatchDjot(string $djot, array $expected): void
    {
        $carve = (new DjotToCarve())->convert($djot);
        $html = (new CarveConverter())->convert($carve);

        self::assertSame($expected, HtmlHeadingIds::extract($html));
    }

    public function testColonIdUsesQuotedAttributeSyntax(): void
    {
        $carve = (new DjotToCarve())->convert("### PHP\n\n#### PHP:\n");

        self::assertStringContainsString('{id="PHP:"}', $carve);
    }

    public function testExplicitOptOutKeepsCarveIds(): void
    {
        $carve = (new DjotToCarve())->preserveHeadingIds(null)->convert("### PHP\n\n#### PHP:\n");
        $html = (new CarveConverter())->convert($carve);

        self::assertSame(['PHP', 'PHP-2'], HtmlHeadingIds::extract($html));
    }
}
