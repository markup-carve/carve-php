<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\TestCase;

/**
 * PART 10 section 12 / carve#2796. `1)` and `1.` rendered the same bytes, so a
 * document numbering its steps `1)` printed them `1.`.
 */
class AnOrderedListNamesItsDelimiterInHtmlTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testTheListNamesItsDelimiter(): void
    {
        $this->assertSame(
            "<ol data-delim=\")\">\n  <li>first</li>\n  <li>second</li>\n</ol>\n",
            $this->converter->convert("1) first\n2) second\n"),
        );
    }

    public function testTheDefaultDelimiterCarriesNothing(): void
    {
        $this->assertStringNotContainsString('data-delim', $this->converter->convert("1. first\n2. second\n"));
        $this->assertStringNotContainsString('data-delim', $this->converter->convert("- a\n"));
    }

    public function testItTrailsTypeAndStart(): void
    {
        $this->assertStringContainsString(
            '<ol type="a" start="3" data-delim=")">',
            $this->converter->convert("c) gamma\nd) delta\n"),
        );
    }

    public function testItLeadsTheAuthoredAttributes(): void
    {
        $this->assertStringContainsString(
            '<ol data-delim=")" k="v" class="attr">',
            $this->converter->convert("{k=v .attr}\n1) first\n"),
        );
    }

    public function testANestedListDerivesItsOwn(): void
    {
        $this->assertSame(
            "<ol>\n  <li>outer\n    <ol data-delim=\")\">\n      <li>inner</li>\n    </ol>\n  </li>\n</ol>\n",
            $this->converter->convert("1. outer\n\n   1) inner\n"),
        );
    }

    public function testTheDelimiterSurvivesARenderAndImportCycle(): void
    {
        $renderer = new CarveRenderer();
        $source = $renderer->render($this->converter->parse("1) one\n2) two\n"));
        $imported = (new HtmlToCarve())->convert($this->converter->convert($source));

        $this->assertSame($source, $renderer->render($this->converter->parse($imported)));
    }
}
