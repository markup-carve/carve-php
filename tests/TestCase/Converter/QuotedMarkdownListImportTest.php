<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QuotedMarkdownListImportTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function cases(): array
    {
        return [
            'empty sibling item' => [
                "> -\n> - a\n",
                "> - +\n> - a\n",
                "<blockquote>\n  <ul>\n    <li></li>\n    <li>a</li>\n  </ul>\n</blockquote>",
            ],
            'empty fenced item' => [
                "> - ```\n>   ```\n",
                "> - ```\n>   ```\n",
                "<blockquote>\n  <ul>\n    <li>\n      <pre><code></code></pre>\n    </li>\n  </ul>\n</blockquote>",
            ],
            'fenced item with content' => [
                "> - ```\n>   code\n>   ```\n",
                "> - ```\n>   code\n>   ```\n",
                "<blockquote>\n  <ul>\n    <li>\n      <pre><code>code\n</code></pre>\n    </li>\n  </ul>\n</blockquote>",
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testQuotedListItemsKeepTheirMarkdownMeaning(string $source, string $expected, string $html): void
    {
        $imported = (new MarkdownToCarve())->convert($source);

        self::assertSame($expected, $imported);
        self::assertSame($html, (new CarveConverter())->convert($imported));
        self::assertSame($imported, CarveConverter::toCarve($imported));
    }
}
