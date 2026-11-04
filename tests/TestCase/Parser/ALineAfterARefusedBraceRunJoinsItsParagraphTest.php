<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A block brace run refused for its continuation columns is paragraph text, and the next line joins it (CARVE-P2-035, carve#5080).
 * Expected output is the spec model's at carve main f4188b43 (carve-php#4583, carve-php#4584).
 */
final class ALineAfterARefusedBraceRunJoinsItsParagraphTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function markers(): iterable
    {
        yield 'a list marker in the item joins the paragraph' => [
            "- item\n    {#i\n    .b\n    }\n  - m\n",
            "<ul>\n  <li>item\n{#i\n.b\n}\n- m</li>\n</ul>",
        ];

        yield 'an ordered marker joins too' => [
            "- item\n    {#i\n    .b\n    }\n  1. m\n",
            "<ul>\n  <li>item\n{#i\n.b\n}\n1. m</li>\n</ul>",
        ];

        yield 'in a nested item' => [
            "- a\n  - item\n      {#i\n      .b}\n    * m\n",
            "<ul>\n  <li>a\n    <ul>\n      <li>item\n{#i\n.b}\n* m</li>\n    </ul>\n  </li>\n</ul>",
        ];

        yield 'in a quoted item' => [
            "> - item\n>     {#i\n>     .b\n>     }\n>   - m\n",
            "<blockquote>\n  <ul>\n    <li>item\n{#i\n.b\n}\n- m</li>\n  </ul>\n</blockquote>",
        ];

        yield 'after a run a plus attached' => [
            "- a\n+\n{x\ny}\n  - c\n",
            "<ul>\n  <li>a\n    {x\ny}\n- c\n  </li>\n</ul>",
        ];

        yield 'after a run a plus attached in a quote' => [
            "> - a\n> +\n> {x\n> y}\n>   - c\n",
            "<blockquote>\n  <ul>\n    <li>a\n      {x\ny}\n- c\n    </li>\n  </ul>\n</blockquote>",
        ];

        yield 'a valid run still leaves the marker its sublist' => [
            "- item\n    {#i\n     .b\n     }\n  - m\n",
            "<ul>\n  <li>item\n    <ul id=\"i\" class=\"b\">\n      <li>m</li>\n    </ul>\n  </li>\n</ul>",
        ];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function descriptions(): iterable
    {
        yield 'an attribute run opened past the body column' => [
            ":: t\n: d\n    {#i\n  .b\n  }\n",
            "<dl>\n  <dt>t</dt>\n  <dd>d\n{#i\n.b\n}</dd>\n</dl>",
        ];

        yield 'a comment run opened past the body column' => [
            ":: t\n: d\n    {% x\n    y %}\n",
            "<dl>\n  <dt>t</dt>\n  <dd>d\n{% x\ny %}</dd>\n</dl>",
        ];

        yield 'the line after the run joins the paragraph' => [
            ":: t\n: d\n    {#i\n  .b}\n  more\n",
            "<dl>\n  <dt>t</dt>\n  <dd>d\n{#i\n.b}\nmore</dd>\n</dl>",
        ];

        yield 'a comment run and a follower past the column' => [
            ":: t\n: d\n    {% x\n    y %}\n    more\n  z\n",
            "<dl>\n  <dt>t</dt>\n  <dd>d\n{% x\ny %}\nmore\nz</dd>\n</dl>",
        ];

        yield 'in a description inside an item' => [
            "- :: t\n  : d\n      {#i\n    .b\n    }\n",
            "<ul>\n  <li>\n    <dl>\n      <dt>t</dt>\n      <dd>d\n{#i\n.b\n}</dd>\n    </dl>\n  </li>\n</ul>",
        ];

        yield 'in a quoted description' => [
            "> :: t\n> : d\n>     {#i\n>   .b\n>   }\n",
            "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>d\n{#i\n.b\n}</dd>\n  </dl>\n</blockquote>",
        ];

        yield 'a valid run past the body column still attaches' => [
            ":: t\n: d\n    {#i\n      .b\n      }\n  x\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>d</p>\n    <p id=\"i\" class=\"b\">x</p>\n  </dd>\n</dl>",
        ];
    }

    #[DataProvider('markers')]
    public function testAMarkerAfterTheRunJoinsInsteadOfOpeningASublist(string $source, string $html): void
    {
        self::assertSame($html, trim((new CarveConverter())->convert($source)));
    }

    #[DataProvider('descriptions')]
    public function testADescriptionBodyKeepsTheRunsColumns(string $source, string $html): void
    {
        self::assertSame($html, trim((new CarveConverter())->convert($source)));
    }
}
