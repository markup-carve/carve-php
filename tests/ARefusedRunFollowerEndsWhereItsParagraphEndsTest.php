<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A refused brace run keeps its fence-shaped follower's closer only inside the paragraph (CARVE-P2-035, PART 0 S2).
 */
final class ARefusedRunFollowerEndsWhereItsParagraphEndsTest extends TestCase
{
    /**
     * @var string
     */
    private const ITEM = "<ul>\n  <li>{.x\n.y}\n    <pre><code>wa\n</code></pre>\n  </li>\n</ul>\n";

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function examples(): iterable
    {
        yield 'quote after the item' => [
            "- +\n  {.x\n  .y}\n  ```\n  wa\n> q\n  ```\n",
            self::ITEM . "<blockquote><p>q</p></blockquote>\n<pre><code></code></pre>",
        ];

        yield 'heading after the item' => [
            "- +\n  {.x\n  .y}\n  ```\n  wa\n# h\n  ```\n",
            self::ITEM . "<section id=\"h\">\n  <h1>h</h1>\n  <pre><code></code></pre>\n</section>",
        ];

        yield 'table after the item' => [
            "- +\n  {.x\n  .y}\n  ```\n  wa\n| a |\n  ```\n",
            self::ITEM . "<table>\n  <tbody>\n    <tr><td>a</td></tr>\n  </tbody>\n</table>\n<pre><code></code></pre>",
        ];

        yield 'description marker after a first-block description' => [
            ":: t\n: +\n{.x\n.y}\n```\nwa\n: d\n```\n",
            "<dl>\n  <dt>t</dt>\n  <dd>{.x\n.y}</dd>\n</dl>\n<pre><code>wa\n: d\n</code></pre>",
        ];

        yield 'description marker right after the follower' => [
            ":: t\n: +\n{.x\n.y}\n```\n: d\n```\n",
            "<dl>\n  <dt>t</dt>\n  <dd>{.x\n.y}</dd>\n</dl>\n<pre><code>: d\n</code></pre>",
        ];
    }

    #[DataProvider('examples')]
    public function testTheFollowerSCloserStaysInsideTheParagraph(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
