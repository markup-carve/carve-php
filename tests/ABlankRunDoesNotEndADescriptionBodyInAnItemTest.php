<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A run of blank lines does not end a description body in a list item; the next content line decides (carve-php#4263). Expected HTML is the spec model's.
 */
final class ABlankRunDoesNotEndADescriptionBodyInAnItemTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'two blanks before a fence (#4263)' => ["- x\n  :: k\n  : d\n    p\n\n\n    ~~~\n", "<ul>\n  <li>x\n    <dl>\n      <dt>k</dt>\n      <dd>\n        <p>d\np</p>\n        <pre><code></code></pre>\n      </dd>\n    </dl>\n  </li>\n</ul>"];
        yield 'two blanks before a paragraph' => ["- x\n  :: k\n  : d\n\n\n    q\n", "<ul>\n  <li>x\n    <dl>\n      <dt>k</dt>\n      <dd>\n        <p>d</p>\n        <p>q</p>\n      </dd>\n    </dl>\n  </li>\n</ul>"];
        yield 'three blanks in a marker-line description' => ["- :: k\n  : d\n\n\n\n    q\n", "<ul>\n  <li>\n    <dl>\n      <dt>k</dt>\n      <dd>\n        <p>d</p>\n        <p>q</p>\n      </dd>\n    </dl>\n  </li>\n</ul>"];
        yield 'a line below the body column leaves the description' => ["- x\n  :: k\n  : d\n    p\n\n\n  q\n", "<ul>\n  <li><p>x</p>\n    <dl>\n      <dt>k</dt>\n      <dd>d\np</dd>\n    </dl>\n    <p>q</p>\n  </li>\n</ul>"];
        yield 'one blank before a sibling description' => ["- x\n  :: k\n  : d\n\n  : e\n", "<ul>\n  <li>x\n    <dl>\n      <dt>k</dt>\n      <dd>d</dd>\n      <dd>e</dd>\n    </dl>\n  </li>\n</ul>"];
        yield 'two blanks before a sibling description' => ["- :: k\n  : d\n\n\n  : e\n", "<ul>\n  <li>\n    <dl>\n      <dt>k</dt>\n      <dd>d</dd>\n      <dd>e</dd>\n    </dl>\n  </li>\n</ul>"];
        yield 'a sibling description past the separator column' => ["- :: k\n  : d\n\n   : e\n", "<ul>\n  <li>\n    <dl>\n      <dt>k</dt>\n      <dd>d</dd>\n      <dd>e</dd>\n    </dl>\n  </li>\n</ul>"];
        yield 'ordered host' => ["1. x\n   :: k\n   : d\n\n\n     q\n", "<ol>\n  <li>x\n    <dl>\n      <dt>k</dt>\n      <dd>\n        <p>d</p>\n        <p>q</p>\n      </dd>\n    </dl>\n  </li>\n</ol>"];
        yield 'nested item host' => ["- a\n  - x\n    :: k\n    : d\n\n\n    : e\n", "<ul>\n  <li>a\n    <ul>\n      <li>x\n        <dl>\n          <dt>k</dt>\n          <dd>d</dd>\n          <dd>e</dd>\n        </dl>\n      </li>\n    </ul>\n  </li>\n</ul>"];
        yield 'document level' => [":: k\n: d\n  p\n\n\n  ~~~\n", "<dl>\n  <dt>k</dt>\n  <dd>\n    <p>d\np</p>\n    <pre><code></code></pre>\n  </dd>\n</dl>"];
    }

    #[DataProvider('documents')]
    public function testTheBodyContinuesPastTheBlankRun(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
