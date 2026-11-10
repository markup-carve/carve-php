<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AQuotedDescriptionRunAcceptsWrittenColumnTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'unprefixed continuation' => [
            "> :: t\n> :   {.x\n        .y}\n> : z\n",
            "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>{.x\n.y}</dd>\n    <dd>z</dd>\n  </dl>\n</blockquote>",
        ];

        yield 'nested quote continuation' => [
            "> > :: t\n> > :   {.x\n          .y}\n> > : z\n",
            "<blockquote>\n  <blockquote>\n    <dl>\n      <dt>t</dt>\n      <dd>{.x\n.y}</dd>\n      <dd>z</dd>\n    </dl>\n  </blockquote>\n</blockquote>",
        ];

        yield 'nested quote valid run leaves no lazy paragraph' => [
            "> > :: t\n> > :  {.x\n        .y}\nplain\n",
            "<blockquote>\n  <blockquote>\n    <dl>\n      <dt>t</dt>\n      <dd>{.x\n.y}\nplain</dd>\n    </dl>\n  </blockquote>\n</blockquote>",
        ];

        yield 'unprefixed middle and quoted closer' => [
            "> :: t\n> :   {.x\n        .y\n>        }\nplain\n",
            "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>{.x\n.y\n}\nplain</dd>\n  </dl>\n</blockquote>",
        ];

        yield 'continuation at brace column is refused' => [
            "> :: t\n> :   {.x\n      .y}\n> : z\n",
            "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>{.x\n.y}</dd>\n    <dd>z</dd>\n  </dl>\n</blockquote>",
        ];

        yield 'refused run keeps a following heading as text' => [
            "> :: t\n> :  {.x\n    .y}\n> # H\n",
            "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>{.x\n.y}</dd>\n  </dl>\n  <h1 id=\"H\">H</h1>\n</blockquote>",
        ];

        yield 'refused mixed run keeps a following heading as text' => [
            "> :: t\n> :  {.x\n    .y\n>     }\n> # H\n",
            "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>{.x\n.y\n}</dd>\n  </dl>\n  <h1 id=\"H\">H</h1>\n</blockquote>",
        ];

        yield 'shallower quoted heading leaves a refused description' => [
            "> > :: t\n> > :  {.x\n    .y}\n> # H\n",
            "<blockquote>\n  <blockquote>\n    <dl>\n      <dt>t</dt>\n      <dd>{.x\n.y}</dd>\n    </dl>\n  </blockquote>\n  <h1 id=\"H\">H</h1>\n</blockquote>",
        ];

        yield 'description shaped text inside code stays code' => [
            "> ```\n> :   {.x\n        .y}\n> ```\n",
            "<blockquote>\n  <pre><code>:   {.x\n</code></pre>\n</blockquote>\n<p>.y}</p>\n<blockquote>\n  <pre><code></code></pre>\n</blockquote>",
        ];

        yield 'colon without a term stays paragraph text' => [
            "> : {.x\n      .y}\nplain\n",
            "<blockquote><p>: {.x\n.y}\nplain</p></blockquote>",
        ];

        yield 'colon paragraph does not hold a following heading' => [
            "> : {.x\n  .y}\n> # H\n",
            "<blockquote>\n  <p>: {.x\n.y}</p>\n  <h1 id=\"H\">H</h1>\n</blockquote>",
        ];
    }

    #[DataProvider('documents')]
    public function testTheWrittenColumnDecidesTheRun(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
