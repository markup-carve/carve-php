<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve#2369: Carve source has no boundary between two
 * definition lists, so an attribute-less one joins the list before it.
 */
class AdjacentDefinitionListsImportAsOneTest extends TestCase
{
    public function testAdjacentListsMerge(): void
    {
        $html = "<dl><dt>a</dt><dd>x</dd></dl>\n<dl><dt>b</dt><dd>y</dd></dl>\n<dl><dt>c</dt><dd>z</dd></dl>";
        $result = (new HtmlToCarve())->convertWithReport($html);

        $this->assertSame(":: a\n: x\n:: b\n: y\n:: c\n: z\n", $result->value);
        $this->assertSame($result->value, (new CarveConverter())->toCarve($result->value));
        $rows = $result->report()['diagnostics'];
        $this->assertSame(['element-unwrapped', 'element-unwrapped'], array_column($rows, 'code'));
        $this->assertSame(['/dl[3]', '/dl[5]'], array_column($rows, 'path'));
        $this->assertSame(['info', 'info'], array_column($rows, 'severity'));
    }

    public function testAnAttributedListOrAParagraphKeepsThemApart(): void
    {
        $attributed = (new HtmlToCarve())->convertWithReport('<dl><dt>a</dt><dd>x</dd></dl><dl class="k"><dt>b</dt><dd>y</dd></dl>');
        $this->assertSame(":: a\n: x\n\n{.k}\n:: b\n: y\n", $attributed->value);
        $this->assertSame([], $attributed->report()['diagnostics']);

        $separated = (new HtmlToCarve())->convertWithReport('<dl><dt>a</dt><dd>x</dd></dl><p>p</p><dl><dt>b</dt><dd>y</dd></dl>');
        $this->assertSame(":: a\n: x\n\np\n\n:: b\n: y\n", $separated->value);
        $this->assertSame([], $separated->report()['diagnostics']);
    }
}
