<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve#4759 (replacing the carve#2369 merge): two adjacent
 * definition lists stay two lists, written with N1a's three-blank boundary.
 */
class AdjacentDefinitionListsImportAsOneTest extends TestCase
{
    public function testAdjacentListsStaySeparate(): void
    {
        $html = "<dl><dt>a</dt><dd>x</dd></dl>\n<dl><dt>b</dt><dd>y</dd></dl>\n<dl><dt>c</dt><dd>z</dd></dl>";
        $result = (new HtmlToCarve())->convertWithReport($html);

        $this->assertSame(":: a\n: x\n\n\n\n:: b\n: y\n\n\n\n:: c\n: z\n", $result->value);
        $this->assertSame($result->value, (new CarveConverter())->toCarve($result->value));
        $this->assertSame([], $result->report()['diagnostics']);
    }

    public function testAnAttributedListOrAParagraphKeepsThemApart(): void
    {
        $attributed = (new HtmlToCarve())->convertWithReport('<dl><dt>a</dt><dd>x</dd></dl><dl class="k"><dt>b</dt><dd>y</dd></dl>');
        // carve#4759: an attributed adjacent description list uses N1a's hard boundary.
        $this->assertSame(":: a\n: x\n\n\n\n{.k}\n:: b\n: y\n", $attributed->value);
        $this->assertSame([], $attributed->report()['diagnostics']);

        $separated = (new HtmlToCarve())->convertWithReport('<dl><dt>a</dt><dd>x</dd></dl><p>p</p><dl><dt>b</dt><dd>y</dd></dl>');
        $this->assertSame(":: a\n: x\n\np\n\n:: b\n: y\n", $separated->value);
        $this->assertSame([], $separated->report()['diagnostics']);
    }
}
