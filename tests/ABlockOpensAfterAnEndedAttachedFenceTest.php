<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A line between the host's marker and content columns after the blank that ends
 * an unclosed attached fence opens its block, as after the colon form (carve#5401).
 */
final class ABlockOpensAfterAnEndedAttachedFenceTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function hosts(): iterable
    {
        foreach (['# h', '> q', '| a |', '::: x', 'tail', '- q'] as $next) {
            yield "top-level item, {$next}" => ["* +\n%s\n\n {$next}\n"];
            yield "nested item, {$next}" => ["- a\n  * +\n  %s\n\n   {$next}\n"];
            yield "quoted item, {$next}" => ["> * +\n> %s\n>\n>  {$next}\n"];
            yield "quoted nested item, {$next}" => ["> - a\n>   * +\n>   %s\n>\n>    {$next}\n"];
        }
    }

    #[DataProvider('hosts')]
    public function testTheLineReadsAsAfterAnEndedColonBlock(string $template): void
    {
        $render = static fn (string $opener): string => (string)preg_replace(
            '~<aside[^>]*>\s*</aside>|<div>\s*</div>|<pre><code></code></pre>~',
            'B',
            (new CarveConverter())->convert(sprintf($template, $opener)),
        );

        self::assertSame($render(':::'), $render('```'));
    }

    public function testTheTicketReproduction(): void
    {
        self::assertSame(
            "<ul>\n  <li>\n    <pre><code></code></pre>\n  </li>\n</ul>\n<section id=\"q\">\n  <h1>q</h1>\n</section>",
            (new CarveConverter())->convert("* +\n```\n\n # q\n"),
        );
    }

    public function testANestedQuoteOpensInTheOuterItem(): void
    {
        self::assertSame(
            "<ul>\n  <li><p>a</p>\n    <ul>\n      <li>\n        <pre><code></code></pre>\n      </li>\n    </ul>\n    <blockquote><p>q</p></blockquote>\n  </li>\n</ul>",
            (new CarveConverter())->convert("- a\n  * +\n  ```\n\n   > q\n"),
        );
    }

    public function testAClosedFenceKeepsItsInteriorBlankAndLine(): void
    {
        self::assertStringContainsString(
            "<pre><code>\n # q\n</code></pre>",
            (new CarveConverter())->convert("* +\n```\n\n # q\n```\n"),
        );
    }
}
