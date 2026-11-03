<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `%%` line that misses a line block ends its stanza without adding a line,
 * and a lazy line folds into a stanza whose last verse line closed a fence-shaped run.
 */
final class ALineBlockStanzaEndsWhereTheModelEndsItTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function stanzaSources(): array
    {
        return [
            'missed comment after an item line block' => [
                "- ::: |\n  x\n%% c\n  y\n  :::\n",
                '<ul><li><div class="line-block"><p>x</p></div></li></ul><p>y</p><div></div>',
            ],
            'lazy line after a verse span' => [
                "- ::: |\n  ```\n  :::\n  ```\nlazy\n",
                "<ul><li><div class=\"line-block\"><p><code>\n:::\n</code><br>\nlazy</p></div></li></ul>",
            ],
            'lazy line in a line block in a box' => [
                "- ::: box\n  ::: |\n  x\nlazy\n",
                "<ul><li><div class=\"box\"><div class=\"line-block\"><p>x<br>\nlazy</p></div></div></li></ul>",
            ],
            'lazy line after a verse span in a box' => [
                "- ::: box\n    ::: |\n    ```\n  :::\n  ```\nlazy\n",
                "<ul><li><div class=\"box\"><div class=\"line-block\"><p><code>\n:::\n</code><br>\nlazy</p></div></div></li></ul>",
            ],
            'missed comment after a quoted verse span' => [
                "> ::: |\n> ```\n> a\n> ```\n%% note\nlazy\n",
                "<blockquote><div class=\"line-block\"><p><code>\na\n</code></p></div></blockquote><p>lazy</p>",
            ],
            'indented lazy line after a quoted verse span' => [
                "> ::: |\n> ```\n> a\n> ```\n lazy\n",
                "<blockquote><div class=\"line-block\"><p><code>\na\n</code><br>\nlazy</p></div></blockquote>",
            ],
            'lazy line after a verse span in a quoted box' => [
                "> - ::: box\n>   ::: |\n>   ```\n>   a\n>   ```\nlazy\n",
                "<blockquote><ul><li><div class=\"box\"><div class=\"line-block\"><p><code>\na\n</code><br>\nlazy</p></div></div></li></ul></blockquote>",
            ],
        ];
    }

    #[DataProvider('stanzaSources')]
    public function testTheStanzaMatchesTheSpecModel(string $source, string $expected): void
    {
        $html = CarveConverter::create()->convert($source);

        self::assertSame($expected, trim((string)preg_replace('/>\s+</', '><', $html)));
    }
}
