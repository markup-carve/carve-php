<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `+` in a nested description attaches the fence below it whole, read at
 * the marker's column: blank lines inside the fence stay in it, and the fence
 * ends at its own closer (PART 9 section 17).
 */
class AnIndentedPlusTakesItsWholeFenceTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        $head = "<dl>\n<dt>t</dt>\n<dd>\n<p>d</p>\n<dl>\n<dt>term</dt>\n<dd>\n<p>detail</p>\n";

        return [
            'a blank first' => [
                ":: t\n:  d\n   :: term\n   :  detail\n   +\n   ```\n\n   code\n   ```\n",
                $head . "<pre><code>\ncode\n</code></pre>\n</dd>\n</dl>\n</dd>\n</dl>",
            ],
            'a blank inside' => [
                ":: t\n:  d\n   :: term\n   :  detail\n   +\n   ~~~\n   a\n\n   b\n   ~~~\n",
                $head . "<pre><code>a\n\nb\n</code></pre>\n</dd>\n</dl>\n</dd>\n</dl>",
            ],
            'a fence after the closer' => [
                ":: t\n:  d\n   :: term\n   :  detail\n   +\n   ```\n   code\n   ```\n   ```\n",
                $head . "<pre><code>code\n</code></pre>\n</dd>\n</dl>\n<pre><code></code></pre>\n</dd>\n</dl>",
            ],
            'an attribute above the fence' => [
                ":: t\n:  d\n   :: term\n   :  detail\n   +\n   {.foo}\n   ```\n\n   code\n   ```\n",
                $head . "<pre class=\"foo\"><code>\ncode\n</code></pre>\n</dd>\n</dl>\n</dd>\n</dl>",
            ],
            'in a list item' => [
                "- :: t\n  :  d\n     :: term\n     :  detail\n     +\n     ```\n\n     code\n     ```\n",
                "<ul>\n<li>\n" . $head . "<pre><code>\ncode\n</code></pre>\n</dd>\n</dl>\n</dd>\n</dl>\n</li>\n</ul>",
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testTheFenceStaysInTheNestedDescription(string $source, string $expected): void
    {
        $html = trim(CarveConverter::create()->convert($source));

        $this->assertSame($expected, (string)preg_replace('/^ +/m', '', $html));
    }

    public function testALineBelowTheMarkerEndsTheSearchForACloser(): void
    {
        $html = trim(CarveConverter::create()->convert(":: t\n:  d\n   :: term\n   :  detail\n   +\n   ```\n   code\n:: sibling\n:  other\n   ```\n"));

        $this->assertStringContainsString("<dt>sibling</dt>\n<dd>\n<p>other</p>", (string)preg_replace('/^ +/m', '', $html));
    }

    public function testACloserBelowTheMarkerLeavesTheFenceOpen(): void
    {
        $html = trim(CarveConverter::create()->convert(":: t\n:  d\n    :: term\n    :  detail\n    +\n    ```\n    code\n     ```\n```\n"));

        $this->assertStringEndsWith("</dl>\n<pre><code></code></pre>", (string)preg_replace('/^ +/m', '', $html));
    }
}
