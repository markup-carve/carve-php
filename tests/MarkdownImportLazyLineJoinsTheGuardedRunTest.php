<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A lazy line continues the paragraph of a quote or item, so the import
 * guards the paragraph as one run (cmark-gfm 0.29.0.gfm.13).
 */
class MarkdownImportLazyLineJoinsTheGuardedRunTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function provideShapes(): array
    {
        return [
            'slash in a quote, emphasis on the lazy line' => [
                "> /u\nlazy *e*\n",
                "> \\/u\n> lazy /e/\n",
                "<blockquote><p>/u\nlazy <em>e</em></p></blockquote>",
            ],
            'slash in an item, emphasis on the lazy line' => [
                "- a\n- /u\nlazy *e*\n",
                "- a\n- \\/u\n  lazy /e/\n",
                "<ul>\n  <li>a</li>\n  <li>/u\nlazy <em>e</em></li>\n</ul>",
            ],
        ];
    }

    #[DataProvider('provideShapes')]
    public function testImportsAsCmarkGfmReadsIt(string $markdown, string $carve, string $html): void
    {
        $imported = (new MarkdownToCarve())->convert($markdown);

        $this->assertSame($carve, $imported);
        $this->assertSame($html, trim((new CarveConverter())->convert($imported)));
    }
}
