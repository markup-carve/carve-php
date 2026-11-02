<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A fence opened in a sublist written on its parent's marker line ends at the
 * first line that misses the sublist (CARVE-P0-014, PART 0 S1).
 */
class AFenceInAMarkerLeadSublistEndsAtALineThatMissesItTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function sources(): array
    {
        return [
            'a tilde run below the sublist' => [
                "- - a\n    ```\n  ~~~\n    ```\n",
                "<ul><li><ul><li>a\n        <pre><code></code></pre></li></ul><pre><code>  ```\n</code></pre></li></ul>",
            ],
            'a backtick run below the sublist' => [
                "- - a\n      ```\n  ```\n    ```\n",
                "<ul><li><ul><li>a\n        <pre><code></code></pre></li></ul><pre><code>  ```\n</code></pre></li></ul>",
            ],
            'a closer at the opener column' => [
                "- - a\n      ```\n  ~~~\n      ```\n",
                "<ul><li><ul><li>a\n        <pre><code></code></pre></li></ul><pre><code>    ```\n</code></pre></li></ul>",
            ],
            'in a quote' => [
                "> - - a\n>       ```\n>   ~~~\n>     ```\n- tail\n",
                "<blockquote><ul><li><ul><li>a\n          <pre><code></code></pre></li></ul><pre><code>  ```\n</code></pre></li></ul></blockquote><ul><li>tail</li></ul>",
            ],
            'an ordered lead' => [
                "- 1. a\n     ```\n  ~~~\n     ```\n",
                "<ul><li><ol><li>a\n        <pre><code></code></pre></li></ol><pre><code>   ```\n</code></pre></li></ul>",
            ],
        ];
    }

    #[DataProvider('sources')]
    public function testTheFenceStaysInTheSublist(string $source, string $expected): void
    {
        $html = (string)preg_replace('/>\s+</', '><', (new CarveConverter())->convert($source));

        $this->assertSame($expected, trim($html));
    }
}
