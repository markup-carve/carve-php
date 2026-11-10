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
            "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd></dd>\n    <dd>z</dd>\n  </dl>\n</blockquote>",
        ];

        yield 'nested quote continuation' => [
            "> > :: t\n> > :   {.x\n          .y}\n> > : z\n",
            "<blockquote>\n  <blockquote>\n    <dl>\n      <dt>t</dt>\n      <dd></dd>\n      <dd>z</dd>\n    </dl>\n  </blockquote>\n</blockquote>",
        ];

        yield 'nested quote valid run leaves no lazy paragraph' => [
            "> > :: t\n> > :  {.x\n        .y}\nplain\n",
            "<blockquote>\n  <blockquote>\n    <dl>\n      <dt>t</dt>\n      <dd></dd>\n    </dl>\n  </blockquote>\n</blockquote>\n<p>plain</p>",
        ];

        yield 'unprefixed middle and quoted closer' => [
            "> :: t\n> :   {.x\n        .y\n>        }\nplain\n",
            "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd></dd>\n  </dl>\n</blockquote>\n<p>plain</p>",
        ];

        yield 'continuation at brace column is refused' => [
            "> :: t\n> :   {.x\n      .y}\n> : z\n",
            "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>{.x\n.y}</dd>\n    <dd>z</dd>\n  </dl>\n</blockquote>",
        ];

        yield 'refused run keeps a following heading as text' => [
            "> :: t\n> :  {.x\n    .y}\n> # H\n",
            "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>{.x\n.y}\n# H</dd>\n  </dl>\n</blockquote>",
        ];

        yield 'refused mixed run keeps a following heading as text' => [
            "> :: t\n> :  {.x\n    .y\n>     }\n> # H\n",
            "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>{.x\n.y\n}\n# H</dd>\n  </dl>\n</blockquote>",
        ];
    }

    #[DataProvider('documents')]
    public function testTheWrittenColumnDecidesTheRun(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
