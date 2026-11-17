<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Test;

use MarkupCarve\Carve2\Carve2;
use PHPUnit\Framework\TestCase;

final class Candidate3Test extends TestCase
{
    public function testC2I021RawFormatNameStartsWithALetter(): void
    {
        $this->assertSame('<p><code>x</code>{=_a}</p>', Carve2::toHtml('`x`{=_a}'));
        $this->assertSame('<p><code>x</code>{=-a}</p>', Carve2::toHtml('`x`{=-a}'));
        $this->assertSame('<p>x</p>', Carve2::toHtml('`x`{=html}'));
        $this->assertSame('<p></p>', Carve2::toHtml('`x`{=h_1-}'));
    }

    public function testC2H040LoneBlockImageInDescriptionStandsOnItsOwnLine(): void
    {
        $expected = "<dl>\n  <dt>T</dt>\n  <dd>\n    <img src=\"x.png\" alt=\"a\">\n  </dd>\n</dl>";
        $this->assertSame($expected, Carve2::toHtml(":: T\n: ![a](x.png)"));
        $expected = "<dl>\n  <dt>T</dt>\n  <dd><p class=\"c\"><img src=\"x.png\" alt=\"a\"></p></dd>\n</dl>";
        $this->assertSame($expected, Carve2::toHtml(":: T\n: {.c}\n  ![a](x.png)"));
    }

    public function testC2H133UnexpandedIncludeIsLaidOutAsAParagraph(): void
    {
        $this->assertSame("<ul>\n  <li>{{ a.crv }}</li>\n  <li>b</li>\n</ul>", Carve2::toHtml("- {{ a.crv }}\n- b"));
        $this->assertSame('<blockquote><p>{{ a.crv }}</p></blockquote>', Carve2::toHtml('> {{ a.crv }}'));
        $this->assertSame("<dl>\n  <dt>T</dt>\n  <dd>{{ a.crv }}</dd>\n</dl>", Carve2::toHtml(":: T\n: {{ a.crv }}"));
        $this->assertSame("<ul>\n  <li>a\n    <p>{{ a.crv }}</p>\n  </li>\n</ul>", Carve2::toHtml("- a\n\n  {{ a.crv }}"));
    }

    public function testC2B226QuotedEmptyLastCellKeepsItsRow(): void
    {
        $html = Carve2::toHtml("{table}\n```csv\na\n\"\"\n```");
        $this->assertStringContainsString('<tr><td></td></tr>', $html);
        $html = Carve2::toHtml("{table}\n```csv\na\nb\n\n```");
        $this->assertStringNotContainsString('<td></td>', $html);
    }

    public function testC2H116ReservedAuthoredAttributeIsDroppedWithoutRoundtrip(): void
    {
        $result = Carve2::renderWithDiagnostics('[<b>]{data-carve-raw=html}');
        $this->assertSame('<p><span>&lt;b&gt;</span></p>', $result->html);
        $this->assertContains('carrier-attribute-dropped', $result->codes());
        $this->assertSame('<p><span data-carve-other="1">x</span></p>', Carve2::toHtml('[x]{data-carve-other=1}'));
    }

    public function testC2N074CaptionOfExpandedIncludeIsSkippedByResolution(): void
    {
        $options = ['includes' => ['resolver' => static fn (array $r): array => ['text' => "code\n", 'id' => $r['path']]]];
        $source = "{{ a.txt @code }}\n^ Listing #: x^[note] [s]{#q}";
        $html = Carve2::toHtml($source, $options);
        $this->assertSame(1, substr_count($html, '<li id="fn'));
        $this->assertSame(1, substr_count($html, 'id="q"'));
        $this->assertStringContainsString('Listing 1: x<a id="fnref1"', $html);
        $tree = json_decode(Carve2::toJson($source, $options), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('number', $tree['children'][0]['caption']['children'][0]['children'][3]);
        $this->assertSame(1, $tree['children'][0]['children'][0]['caption']['children'][0]['children'][3]['number']);
    }
}
