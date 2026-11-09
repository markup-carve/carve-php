<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ARefusedFenceFollowerEndsAtItsOwnerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'root fence after a quote (#4980)' => [
            "- +\n  {.x\n  .y}\n  ```\n  wa\n> q\n  ```\n",
            "<ul>\n  <li>{.x\n.y}\n<code>\nwa</code></li>\n</ul>\n<blockquote><p>q</p></blockquote>\n<pre><code></code></pre>",
        ];

        yield 'section fence after a heading (#4981)' => [
            "- +\n  {.x\n  .y}\n  ```\n  wa\n# h\n  ```\n",
            "<ul>\n  <li>{.x\n.y}\n<code>\nwa</code></li>\n</ul>\n<section id=\"h\">\n  <h1>h</h1>\n  <pre><code></code></pre>\n</section>",
        ];
    }

    #[DataProvider('cases')]
    public function testFenceAnswersToTheSurvivingOwner(string $source, string $html): void
    {
        self::assertSame($html, trim((new CarveConverter())->convert($source)));
    }
}
