<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A line CommonMark does not read as a link reference definition stays text.
 */
class MarkdownImportRejectedDefinitionsTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function provideImports(): array
    {
        return [
            'escaped closer leaves the label open' => ["[\\]: test\n", "\\[\\]: test\n", '<p>[]: test</p>'],
            'escaped closer in a quote' => ["> [\\]: t\n", "> \\[\\]: t\n", '<blockquote><p>[]: t</p></blockquote>'],
            'escaped closer in an item' => ["- [\\]: t\n", "- \\[\\]: t\n", "<ul>\n  <li>[]: t</li>\n</ul>"],
            'escaped closer on a continuation line' => ["para\n[a\\]:\n", "para\n\\[a\\]:\n", "<p>para\n[a]:</p>"],
            'escaped backslash still closes the label' => ["[a\\\\]: b\n\n[a\\\\]\n", "[a\\\\][a\\\\]\n\n[a\\\\]: b\n", '<p><a href="b">a\\</a></p>'],
        ];
    }

    #[DataProvider('provideImports')]
    public function testImport(string $markdown, string $carve, string $html): void
    {
        $converter = new MarkdownToCarve();
        self::assertSame($carve, $converter->convert($markdown));
        self::assertSame($html, trim((new CarveConverter())->convert($carve)));
    }
}
