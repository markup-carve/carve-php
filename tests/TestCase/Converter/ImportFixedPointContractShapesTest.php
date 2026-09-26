<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Import shapes whose written source was not a `fmt` fixed point
 * (markup-carve/carve#2383, #2384, #2385, #2396).
 */
class ImportFixedPointContractShapesTest extends TestCase
{
    /**
     * @return array<string, array{string, string, list<array{string, string}>}>
     */
    public static function shapes(): array
    {
        return [
            'a pipe in an attribute value' => [
                '<table><tr><td id="c" data-x="a|b">t</td><td><span data-y="p|q">u</span></td></tr></table>',
                "|{#c data-x=\"a\\|b\"} t | [u]{data-y=\"p\\|q\"} |\n",
                [],
            ],
            'a description before the first term' => [
                '<dl class="k"><dd><div id="p" class="noprint"><i>x</i></div></dd></dl>',
                "{#p}\n::: noprint\n/x/\n:::\n",
                [['attribute-dropped', '/dl[1]'], ['element-unwrapped', '/dl[1]/dd[1]']],
            ],
            'an attribute value with a line break' => [
                "<div class=\"h\" data-copy=\"a\nb\"><pre><code>x</code></pre></div>",
                "::: h\n```\nx\n```\n:::\n",
                [['attribute-dropped', '/div[1]']],
            ],
            'a comment with a line break in a heading' => [
                "<h2>a <!-- x\ny --> b</h2>",
                "## a  b\n",
                [['element-dropped', '/h2[1]/comment()[2]']],
            ],
        ];
    }

    /**
     * @param string $html
     * @param string $carve
     * @param list<array{string, string}> $rows
     */
    #[DataProvider('shapes')]
    public function testTheImportIsAFixedPoint(string $html, string $carve, array $rows): void
    {
        $result = (new HtmlToCarve())->convertWithReport($html);
        $actual = [];
        foreach ($result->diagnostics as $diagnostic) {
            $actual[] = [$diagnostic->code, $diagnostic->path];
        }

        $this->assertSame($carve, $result->value);
        $this->assertSame($rows, $actual);
        $this->assertSame($carve, CarveConverter::toCarve($carve));
    }
}
