<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A quote's + adds no separator line inside a fence its attached item left open (CARVE-P9-067). Expected HTML is the spec model's.
 */
final class AQuotePlusAddsNoLineToAnOpenFenceTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'reproduction' => ["> +\n- ``` js\n> q\n", "<blockquote>\n  <ul>\n    <li>\n      <pre><code class=\"language-js\"></code></pre>\n    </li>\n  </ul>\n  <p>q</p>\n</blockquote>"];
        yield 'tilde fence' => ["> +\n- ~~~ js\n> q\n", "<blockquote>\n  <ul>\n    <li>\n      <pre><code class=\"language-js\"></code></pre>\n    </li>\n  </ul>\n  <p>q</p>\n</blockquote>"];
        yield 'ordered item' => ["> +\n1. ``` js\n> q\n", "<blockquote>\n  <ol>\n    <li>\n      <pre><code class=\"language-js\"></code></pre>\n    </li>\n  </ol>\n  <p>q</p>\n</blockquote>"];
        yield 'nested item' => ["> +\n- - ``` js\n> q\n", "<blockquote>\n  <ul>\n    <li>\n      <ul>\n        <li>\n          <pre><code class=\"language-js\"></code></pre>\n        </li>\n      </ul>\n    </li>\n  </ul>\n  <p>q</p>\n</blockquote>"];
        yield 'plus on its own line' => ["> a\n+\n- ``` js\n> q\n", "<blockquote>\n  <p>a</p>\n  <ul>\n    <li>\n      <pre><code class=\"language-js\"></code></pre>\n    </li>\n  </ul>\n  <p>q</p>\n</blockquote>"];
        yield 'indented follower stays payload' => ["> +\n- ``` js\n>   q\n", "<blockquote>\n  <ul>\n    <li>\n      <pre><code class=\"language-js\">q\n</code></pre>\n    </li>\n  </ul>\n</blockquote>"];
        yield 'sibling item after the fence' => ["> +\n- ``` js\n> - q\n", "<blockquote>\n  <ul>\n    <li>\n      <pre><code class=\"language-js\"></code></pre>\n    </li>\n    <li>q</li>\n  </ul>\n</blockquote>"];
        yield 'paragraph item keeps its separator' => ["> +\n- x\n> q\n", "<blockquote>\n  <ul>\n    <li>x</li>\n  </ul>\n  <p>q</p>\n</blockquote>"];
    }

    #[DataProvider('documents')]
    public function testTheFenceKeepsItsPayload(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
