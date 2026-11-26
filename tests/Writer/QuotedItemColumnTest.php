<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#6427, C2-B-003: a body opened behind a quote mark on a footnote marker line counts its columns from behind
 * that mark, so the width of the footnote marker in front of the quote does not indent its continuation lines.
 */
final class QuotedItemColumnTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function sources(): array
    {
        return [
            'fence in an item' => ["[^n]: > - ```\n  >   y\n  >   ```\n"],
            'paragraphs in an item' => ["[^n]: > - a\n  >\n  >   b\n"],
            'fence in a nested item' => ["[^n]: > - - ```\n  >     y\n  >     ```\n"],
            'fence in an item of a nested quote' => ["[^n]: > > - ```\n  > >   y\n  > >   ```\n"],
            'fence in a bare-dot item' => ["[^n]: > . ```\n  >   y\n  >   ```\n"],
            'fence in a quote in an item' => ["[^n]: > - > ```\n  >   > y\n  >   > ```\n"],
            'task item' => ["[^n]: > - [x] a\n  >\n  >   b\n"],
            // C2-B-229: without a quote between them, the item keeps the column of its marker on the footnote line.
            'item on the footnote line' => ["[^n]: - a\n\n        b\n"],
            'quote in an item on the footnote line' => ["[^n]: - > - a\n        >\n        >   b\n"],
        ];
    }

    /**
     * @param string $source
     *
     * @return void
     */
    #[DataProvider('sources')]
    public function testWritesSourceBackUnchanged(string $source): void
    {
        $this->assertSame($source, Carve::formatWithDiagnostics($source)->source);
        $this->assertSame([], Carve::formatWithDiagnostics($source)->diagnostics);
        $this->assertSame($source, Carve::formatWithDiagnostics($source, ['normalize' => true])->source);
        $this->assertSame($source, Carve::write(Carve::parse($source)));
    }

    /**
     * carve-php#5665: the four spaces past the item's content column belong to the code line.
     *
     * @return void
     */
    public function testReadsCodeLinesFromTheQuotedItemColumn(): void
    {
        $tree = json_decode(Carve::toJson("[^n]: > - ```\n  >       y\n  >       ```\n"), true);

        $this->assertSame("    y\n", $tree['children'][0]['children'][0]['children'][0]['children'][0]['children'][0]['value']);
    }
}
