<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * After the blank that ends an unclosed attached colon block, its host resumes
 * as after an unclosed attached fence (carve#5359, carve#5396).
 */
final class AHostResumesAfterAnEndedAttachedColonTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function hosts(): iterable
    {
        foreach (['tail', '- q', '> q', '# h', '| a |', '1. q'] as $next) {
            yield "top-level item, {$next}" => ["- +\n%s\n\n  {$next}\n", $next];
            yield "nested item, {$next}" => ["- a\n  * +\n  %s\n\n    {$next}\n", $next];
            yield "ordered item, {$next}" => ["1. +\n%s\n\n   {$next}\n", $next];
        }
    }

    #[DataProvider('hosts')]
    public function testTheHostKeepsTheBlockAsAfterAnAttachedFence(string $template, string $next): void
    {
        $render = static fn (string $opener): string => (string)preg_replace(
            '~<aside[^>]*>\s*</aside>|<pre><code></code></pre>~',
            'B',
            (new CarveConverter())->convert(sprintf($template, $opener)),
        );

        self::assertSame($render('```'), $render('::: note'), $next);
    }

    public function testTheTicketReproductions(): void
    {
        $converter = new CarveConverter();

        self::assertSame(
            "<ul>\n  <li>\n    <aside class=\"admonition note\" aria-label=\"Note\">\n\n    </aside>\n    <ul>\n      <li>q</li>\n    </ul>\n  </li>\n</ul>",
            $converter->convert("- +\n::: note\n\n  - q\n"),
        );
        self::assertSame(
            "<ul>\n  <li>a\n    <ul>\n      <li>\n        <aside class=\"admonition note\" aria-label=\"Note\">\n\n        </aside>\n        <ul>\n          <li>q</li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ul>",
            $converter->convert("- a\n  * +\n  ::: note\n\n    - q\n"),
        );
    }
}
