<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A verse run in a quote pairs only before the first line that leaves the quote, so a lazy
 * line there folds into the open stanza and closes nothing (carve#5062, CARVE-P0-006).
 */
final class AQuotedVerseRunPairsOnlyInsideItsQuoteTest extends TestCase
{
    public function testTheIssueReproduction(): void
    {
        $html = (new CarveConverter())->convert("> - ::: |\n>   ```\nz\n>   ```\n");

        self::assertSame(
            "<blockquote>\n  <ul>\n    <li>\n      <div class=\"line-block\">\n        <p><code>\nz\n</code></p>\n"
            . "      </div>\n    </li>\n  </ul>\n</blockquote>",
            trim($html),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hosts(): iterable
    {
        yield 'nested quote' => ["> > ::: |\n> > ```\nz\n> > ```\n"];
        yield 'shallower quote line' => ["> - > ::: |\n>   > ```\n> z\n> ```\n"];
        yield 'quote in an item' => ["- > ::: |\n  > ```\nz\n  > ```\n"];
    }

    #[DataProvider('hosts')]
    public function testTheLazyLineJoinsTheStanza(string $source): void
    {
        $html = (new CarveConverter())->convert($source);

        self::assertMatchesRegularExpression('~<div class="line-block">\n\s*<p><code>\nz~', $html, $source);
        self::assertStringNotContainsString('<p>z</p>', $html, $source);
    }
}
