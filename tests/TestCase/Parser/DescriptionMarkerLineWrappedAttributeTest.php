<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A wrapped attribute run on a description marker line is measured from the body column (CARVE-P2-035, carve#5753). Expected output is the spec model's at carve main.
 */
final class DescriptionMarkerLineWrappedAttributeTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function shapes(): iterable
    {
        yield 'a valid run opens no paragraph, so a column-0 line leaves the description' => [
            ":: t\n: {.x\n   }\nafter\n",
            "<dl>\n  <dt>t</dt>\n  <dd></dd>\n</dl>\n<p>after</p>",
        ];

        yield 'the run applies to the description\'s next block' => [
            ":: t\n: {.x\n   }\n  after\n",
            "<dl>\n  <dt>t</dt>\n  <dd><p class=\"x\">after</p></dd>\n</dl>",
        ];

        yield 'a run alone leaves the description empty' => [
            ":: t\n: {.x\n   }\n",
            "<dl>\n  <dt>t</dt>\n  <dd></dd>\n</dl>",
        ];

        yield 'a continuation at the brace column stays refused text' => [
            ":: t\n: {.x\n  }\n  after\n",
            "<dl>\n  <dt>t</dt>\n  <dd>{.x\n}\nafter</dd>\n</dl>",
        ];

        yield 'a wider separator measures from its own body column' => [
            ":: t\n:  {#a\n   }\n",
            "<dl>\n  <dt>t</dt>\n  <dd>{#a\n}</dd>\n</dl>",
        ];

        yield 'a quoted description reads the run the same way' => [
            "> :: t\n> : {.x\n>    }\n",
            "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd></dd>\n  </dl>\n</blockquote>",
        ];

        yield 'a description in an item reads the run the same way' => [
            "- :: t\n  : {.x\n     }\n",
            "<ul>\n  <li>\n    <dl>\n      <dt>t</dt>\n      <dd></dd>\n    </dl>\n  </li>\n</ul>",
        ];
    }

    #[DataProvider('shapes')]
    public function testShape(string $source, string $html): void
    {
        $this->assertSame($html, trim((new CarveConverter())->convert($source)));
    }
}
