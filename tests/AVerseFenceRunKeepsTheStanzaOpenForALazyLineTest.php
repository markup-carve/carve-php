<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A fence-shaped verse line is stanza text even when a later run could pair with it,
 * so the lazy line after it folds into the stanza (carve#5488).
 */
final class AVerseFenceRunKeepsTheStanzaOpenForALazyLineTest extends TestCase
{
    public function testTheQuotedNestedItemReproduction(): void
    {
        $html = (new CarveConverter())->convert("> - x\n>   - ::: |\n>     ```\n>   :\n>   ```\n");

        self::assertSame(
            "<blockquote>\n  <ul>\n    <li>x\n      <ul>\n        <li>\n          <div class=\"line-block\">\n"
            . "            <p><code>\n:</code></p>\n          </div>\n        </li>\n      </ul>\n"
            . "      <pre><code></code></pre>\n    </li>\n  </ul>\n</blockquote>",
            trim($html),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function laterRuns(): iterable
    {
        foreach (['```', '````', '~~~'] as $verse) {
            foreach (['```', '````', '~~~'] as $last) {
                yield "$verse-$last" => ["- ::: |\n  $verse\n:\n$last\n"];
            }
        }
    }

    #[DataProvider('laterRuns')]
    public function testTheLazyLineFoldsWhateverRunFollows(string $source): void
    {
        $html = (new CarveConverter())->convert($source);

        self::assertMatchesRegularExpression('~<div class="line-block">\n\s*<p>[^\n]*\n:</(code|p)>~', $html, $source);
        self::assertStringNotContainsString('<p>:</p>', $html, $source);
    }
}
