<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A lazy line of a description's paragraph inside a list item keeps the
 * description open: a blank and a block at the description's body column
 * still belong to it (PART 1 S4), as they do at document level.
 */
class ALazyLineKeepsAnItemsDescriptionOpenPastABlankTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        $item = "<ul>\n<li>x\n<dl>\n<dt>t</dt>\n<dd>\n";
        $end = "</dd>\n</dl>\n</li>\n</ul>";

        return [
            'a lone plus' => ["- x\n  :: t\n  : d\n  +\n\n    y\n", $item . "<p>d\n+</p>\n<p>y</p>\n" . $end],
            'plain text' => ["- x\n  :: t\n  : d\n  z\n\n    y\n", $item . "<p>d\nz</p>\n<p>y</p>\n" . $end],
            'a wider separator' => ["- x\n  :: t\n  :  d\n  z\n\n     y\n", $item . "<p>d\nz</p>\n<p>y</p>\n" . $end],
            'after a body line' => ["- x\n  :: t\n  : d\n    more\n  z\n\n    y\n", $item . "<p>d\nmore\nz</p>\n<p>y</p>\n" . $end],
            'a code block' => [
                "- x\n  :: t\n  : d\n  z\n\n    ```\n    a\n    ```\n",
                $item . "<p>d\nz</p>\n<pre><code>a\n</code></pre>\n" . $end,
            ],
            'a second description' => [
                "- :: s\n  : e\n  :: t\n  : d\n  z\n\n    y\n",
                "<ul>\n<li>\n<dl>\n<dt>s</dt>\n<dd>e</dd>\n<dt>t</dt>\n<dd>\n<p>d\nz</p>\n<p>y</p>\n" . $end,
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testTheBlockStaysInTheDescription(string $source, string $expected): void
    {
        $html = trim(CarveConverter::create()->convert($source));

        $this->assertSame($expected, (string)preg_replace('/^ +/m', '', $html));
    }

    public function testASecondDescriptionAfterATermStillOpensItsBody(): void
    {
        $html = trim(CarveConverter::create()->convert("- :: s\n  : e\n  :: t\n  : d\n\n    y\n"));

        $this->assertStringContainsString("<dd>\n<p>d</p>\n<p>y</p>\n</dd>", (string)preg_replace('/^ +/m', '', $html));
    }
}
