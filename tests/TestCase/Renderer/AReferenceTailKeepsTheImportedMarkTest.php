<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AReferenceTailKeepsTheImportedMarkTest extends TestCase
{
    public function testTheSpanAndTailOpenersCarryTheEscapes(): void
    {
        $importer = new HtmlToCarve();
        $this->assertSame("{^\\[^}\\[a][a]\n", $importer->convert('<p><sup>[</sup>[a][a]</p>'));
        $this->assertSame("{,\\[,}\\[a][a]\n", $importer->convert('<p><sub>[</sub>[a][a]</p>'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function shapes(): array
    {
        $shapes = [];
        foreach (['em', 'strong', 'u', 's', 'mark', 'ins', 'del', 'sup', 'sub'] as $mark) {
            foreach (['', '[', '[[', 'x['] as $prefix) {
                foreach (['[', '[a', 'a[', '[a]', 'a]', 'a]['] as $body) {
                    foreach (['[a][a]', '[a][]'] as $tail) {
                        foreach (['', ' ', 'x', 'x '] as $gap) {
                            $html = "<p>$prefix<$mark>$body</$mark>$gap$tail</p>";
                            $shapes[$html] = [$html];
                        }
                    }
                }
            }
        }
        foreach (['<p>[<sup>a]</sup></p>', '<p>[[<sup>a]</sup>]</p>', '<p>[<sup>a]]</sup></p>', '<p>[<sup>[a]</sup>]</p>'] as $html) {
            $shapes[$html] = [$html];
        }

        return $shapes;
    }

    #[DataProvider('shapes')]
    public function testImportIsStableAndKeepsTheHtml(string $html): void
    {
        $source = (new HtmlToCarve())->convert($html);
        $formatter = new CarveConverter(renderer: new CarveRenderer());
        $this->assertSame($source, $formatter->convert($source));
        $this->assertSame($html, trim((new CarveConverter())->convert($source)));
    }
}
