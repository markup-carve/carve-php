<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RefusedNestedFenceBlankPayloadTest extends TestCase
{
    use RuleATodo;

    public static function cases(): iterable
    {
        $detached = "<ul>\n  <li>a\n    <ul>\n      <li>\n        <pre><code>x\n\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>+\n&gt; q</p>";
        $letter = str_replace('<p>+', '<p>z', $detached);
        $attached = "<ul>\n  <li><p>a</p>\n    <ul>\n      <li>\n        <pre><code>x\n\n</code></pre>\n        <blockquote><p>q</p></blockquote>\n      </li>\n    </ul>\n  </li>\n</ul>";

        yield 'wrong column' => ["- a\n  - ```\n    x\n\n +\n  > q\n", $detached];
        yield 'letter at the same column' => ["- a\n  - ```\n    x\n\n z\n  > q\n", $letter];
        yield 'document column' => ["- a\n  - ```\n    x\n\n+\n  > q\n", $detached];
        yield 'nested marker column' => ["- a\n  - ```\n    x\n\n  +\n  > q\n", $attached];
        yield 'tilde fence' => ["- a\n  - ~~~\n    x\n\n +\n  > q\n", $detached];
        yield 'two payload blanks' => ["- a\n  - ```\n    x\n\n\n +\n  > q\n", str_replace("x\n\n</code>", "x\n\n\n</code>", $detached)];
    }

    #[DataProvider('cases')]
    public function testRefusedMarkerKeepsFencePayload(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
