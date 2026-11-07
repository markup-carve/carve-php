<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#5410: a fence line at a footnote or definition body column does not close a fence a marker-line `+` opened at the marker column.
 */
final class AnAttachedFenceClosesOnlyAtItsHostColumnTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function hosts(): iterable
    {
        yield 'item' => ["- +\n```\na\n\n  ```\n  c\n", "<pre><code>a\n</code></pre>\n    <pre><code>c\n</code></pre>"];
        yield 'definition' => [":: t\n: +\n```\na\n\n  ```\n  c\n", "<pre><code>a\n</code></pre>\n    <pre><code>c\n</code></pre>"];
        yield 'footnote' => ["[^1]: +\n```\na\n\n  ```\n  c\n\n[^1]\n", "<pre><code>a\n</code></pre>\n      <pre><code>c\n\n</code></pre>"];
    }

    #[DataProvider('hosts')]
    public function testTheIndentedFenceLineOpensASecondFence(string $source, string $expected): void
    {
        self::assertStringContainsString($expected, (new CarveConverter())->convert($source));
    }

    public function testAFootnoteCloserAtTheMarkerColumnKeepsTheBlank(): void
    {
        self::assertStringContainsString(
            "<pre><code>a\n\n</code></pre>\n      <p><a",
            (new CarveConverter())->convert("[^1]: +\n```\na\n\n```\nc\n\n[^1]\n"),
        );
    }
}
