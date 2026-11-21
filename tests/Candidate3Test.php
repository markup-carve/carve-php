<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Html\HtmlRenderer;
use MarkupCarve\Carve\Json\TreeError;
use MarkupCarve\Carve\Json\TreeReader;
use MarkupCarve\Carve\Options;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Candidate3Test extends TestCase
{
    public function testC2I021RawFormatNameStartsWithALetter(): void
    {
        $this->assertSame('<p><code>x</code>{=_a}</p>', Carve::toHtml('`x`{=_a}'));
        $this->assertSame('<p><code>x</code>{=-a}</p>', Carve::toHtml('`x`{=-a}'));
        $this->assertSame('<p>x</p>', Carve::toHtml('`x`{=html}'));
        $this->assertSame('<p></p>', Carve::toHtml('`x`{=h_1-}'));
    }

    public function testC2H040LoneBlockImageInDescriptionStandsOnItsOwnLine(): void
    {
        $expected = "<dl>\n  <dt>T</dt>\n  <dd>\n    <img src=\"x.png\" alt=\"a\">\n  </dd>\n</dl>";
        $this->assertSame($expected, Carve::toHtml(":: T\n: ![a](x.png)"));
        $expected = "<dl>\n  <dt>T</dt>\n  <dd>\n    <img src=\"x.png\" alt=\"a\" class=\"c\">\n  </dd>\n</dl>";
        $this->assertSame($expected, Carve::toHtml(":: T\n: {.c}\n  ![a](x.png)"));
    }

    public function testC2H133UnexpandedIncludeIsLaidOutAsAParagraph(): void
    {
        $this->assertSame("<ul>\n  <li>{{ a.crv }}</li>\n  <li>b</li>\n</ul>", Carve::toHtml("- {{ a.crv }}\n- b"));
        $this->assertSame('<blockquote><p>{{ a.crv }}</p></blockquote>', Carve::toHtml('> {{ a.crv }}'));
        $this->assertSame("<dl>\n  <dt>T</dt>\n  <dd>{{ a.crv }}</dd>\n</dl>", Carve::toHtml(":: T\n: {{ a.crv }}"));
        $this->assertSame("<ul>\n  <li>a\n    <p>{{ a.crv }}</p>\n  </li>\n</ul>", Carve::toHtml("- a\n\n  {{ a.crv }}"));
    }

    public function testC2B226QuotedEmptyLastCellKeepsItsRow(): void
    {
        $html = Carve::toHtml("{table}\n```csv\na\n\"\"\n```");
        $this->assertStringContainsString('<tr><td></td></tr>', $html);
        $html = Carve::toHtml("{table}\n```csv\na\nb\n\n```");
        $this->assertStringNotContainsString('<td></td>', $html);
    }

    public function testC2H116ReservedAuthoredAttributeIsDroppedWithoutRoundtrip(): void
    {
        $result = Carve::renderWithDiagnostics('[<b>]{data-carve-raw=html}');
        $this->assertSame('<p><span>&lt;b&gt;</span></p>', $result->html);
        $this->assertContains('carrier-attribute-dropped', $result->codes());
        $this->assertSame('<p><span data-carve-other="1">x</span></p>', Carve::toHtml('[x]{data-carve-other=1}'));
    }

    public function testC2N074CaptionOfExpandedIncludeIsSkippedByResolution(): void
    {
        $options = ['includes' => ['resolver' => static fn (array $r): array => ['text' => "code\n", 'id' => $r['path']]]];
        $source = "{{ a.txt @code }}\n^ Listing #: x^[note] [s]{#q}";
        $html = Carve::toHtml($source, $options);
        $this->assertSame(1, substr_count($html, '<li id="fn'));
        $this->assertSame(1, substr_count($html, 'id="q"'));
        $this->assertStringContainsString('Listing 1: x<a id="fnref1"', $html);
        $tree = json_decode(Carve::toJson($source, $options), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('number', $tree['children'][0]['caption']['children'][0]['children'][3]);
        $this->assertSame(1, $tree['children'][0]['children'][0]['caption']['children'][0]['children'][3]['number']);
    }

    public function testC2A135RendererResolvesASuppliedTreeAgain(): void
    {
        $tree = json_decode(Carve::toJson("# Title\n\na[^n] [t][zz]\n\n[^n]: x\n"), true, 512, JSON_THROW_ON_ERROR);
        $tree['children'][0]['generatedId'] = 'custom';
        $tree['children'][1]['children'][1]['number'] = 5;
        $tree['children'][1]['children'][3]['href'] = '/stale';
        $html = HtmlRenderer::render(TreeReader::read((string)json_encode($tree)), Options::from([]));
        $this->assertStringContainsString('<section id="Title">', $html);
        $this->assertStringContainsString('<sup>1</sup>', $html);
        $this->assertStringContainsString('[t][zz]', $html);
        $this->assertStringNotContainsString('/stale', $html);
        $this->assertStringNotContainsString('custom', $html);
    }

    public function testC2H009DirectionIsWrittenBesideTheLanguage(): void
    {
        $json = '{"type":"document","astVersion":"2.0","children":['
            . '{"type":"paragraph","dir":"rtl","children":[{"type":"text","value":"x"}]},'
            . '{"type":"paragraph","lang":"ar","dir":"rtl","attrs":{"keyValues":[["dir","ltr"],["k","v"]]},'
            . '"children":[{"type":"emphasis","dir":"ltr","children":[{"type":"text","value":"y"}]}]}]}';
        $html = HtmlRenderer::render(TreeReader::read($json), Options::from([]));
        $this->assertSame("<p dir=\"rtl\">x</p>\n<p lang=\"ar\" dir=\"rtl\" k=\"v\"><em dir=\"ltr\">y</em></p>", $html);
    }

    public function testC2H013DirectionInATightItemKeepsItsParagraph(): void
    {
        $json = '{"type":"document","astVersion":"2.0","children":[{"type":"list","marker":{"kind":"bullet","char":"-"},'
            . '"spacing":"tight","children":[{"type":"list_item","children":['
            . '{"type":"paragraph","dir":"rtl","children":[{"type":"text","value":"x"}]}]}]}]}';
        $html = HtmlRenderer::render(TreeReader::read($json), Options::from([]));
        $this->assertSame("<ul>\n  <li><p dir=\"rtl\">x</p></li>\n</ul>", $html);
    }

    public function testC2H200CellOfBlocksIsLaidOutAsAnItem(): void
    {
        $p = '{"type":"paragraph","children":[{"type":"text","value":"%s"}]}';
        $list = '{"type":"list","marker":{"kind":"bullet","char":"-"},"spacing":"tight","children":[{"type":"list_item","children":['
            . sprintf($p, 'a') . ']}]}';
        $cell = '{"type":"table_cell","header":false,"children":[%s]}';
        $json = '{"type":"document","astVersion":"2.0","children":[{"type":"table","columns":[{"type":"table_column"},'
            . '{"type":"table_column"},{"type":"table_column"}],"children":[{"type":"table_body","children":[{"type":"table_row",'
            . '"children":['
            . sprintf($cell, $list . ',' . sprintf($p, 'b')) . ',' . sprintf($cell, sprintf($p, 'c')) . ','
            . sprintf($cell, sprintf($p, 'd') . ',' . sprintf($p, 'e')) . ']}]}]}]}';
        $expected = "<table>\n  <tbody>\n    <tr><td>\n      <ul>\n        <li>a</li>\n      </ul>\n      <p>b</p>\n    </td>"
            . "<td><p>c</p></td><td><p>d</p>\n      <p>e</p>\n    </td></tr>\n  </tbody>\n</table>";
        $this->assertSame($expected, HtmlRenderer::render(TreeReader::read($json), Options::from([])));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function notAKey(): array
    {
        return ['space' => ['x onclick'], 'empty' => [''], 'quote' => ['a"b'], 'equals' => ['a=b']];
    }

    #[DataProvider('notAKey')]
    public function testC2A035ReaderRefusesAKeyThatIsNoKey(string $key): void
    {
        $json = '{"type":"document","astVersion":"2.0","children":[{"type":"paragraph","attrs":{"keyValues":[['
            . json_encode($key) . ',"1"]]},"children":[{"type":"text","value":"x"}]}]}';
        $this->expectException(TreeError::class);
        TreeReader::read($json);
    }

    public function testC2H162CssImageFunctionsBlankTheStyle(): void
    {
        foreach (['image(x.png)', 'src(x)', 'IMAGE-SET(x 1x)', 'image\\2d set(x)', 'url(x)'] as $value) {
            $this->assertSame('<p><span style="">a</span></p>', Carve::toHtml('[a]{style="b: ' . $value . '"}'), $value);
        }
        $this->assertSame('<p><span style="color: red">a</span></p>', Carve::toHtml('[a]{style="color: red"}'));
    }

    public function testC2H161BackgroundIsAUrlListAttribute(): void
    {
        $html = Carve::toHtml('[a]{background="javascript:x"} [b]{background="https://x/y.png"}');
        $this->assertSame('<p><span background="">a</span> <span background="https://x/y.png">b</span></p>', $html);
        $html = Carve::toHtml('[b]{background="https://x/y.png"}', ['urlPolicy' => ['allow' => ['mailto']]]);
        $this->assertSame('<p><span background="">b</span></p>', $html);
    }
}
