<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A code line left of its fence opener is measured from the authored base
 * (CARVE-P0-004, carve#3133), and a nested item that is only a heading leaves
 * no paragraph open (CARVE-P0-014, carve#3132).
 */
class ALeftOfBaseCodeLineAndANestedHeadingTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideCases(): array
    {
        return [
            'left of base in a box' => [
                "- ::: box\n     ```\n    x\n     y\n  :::\nafter\n",
                "<pre><code>x\ny\n</code></pre>",
            ],
            'left of base in an item after a blank' => [
                "- a\n\n     ```\n    x\n     y\n     ```\n",
                "<pre><code>x\ny\n</code></pre>",
            ],
            'right of base keeps its residue' => [
                "- ::: box\n     ```\n       z\n  :::\nafter\n",
                "<pre><code>  z\n</code></pre>",
            ],
            'an unclosed fence after a nested heading' => [
                "- a\n    - # h\n  ~~~\nafter\n",
                "<pre><code></code></pre>\n  </li>\n</ul>\n<p>after</p>",
            ],
            'a column-0 line after a nested heading is not lazy' => [
                "- a\n    - # h\nlazy\n",
                "</ul>\n<p>lazy</p>",
            ],
            'an open child paragraph still takes a lazy line' => [
                "- a\n    - b\nlazy\n",
                "<li>b\nlazy</li>",
            ],
        ];
    }

    #[DataProvider('provideCases')]
    public function testTheLayout(string $source, string $fragment): void
    {
        $this->assertStringContainsString($fragment, (new CarveConverter())->convert($source));
    }
}
