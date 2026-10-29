<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 10 I2 (markup-carve/carve#3759): a standalone `@code` directive is a code
 * include by its spelling, so an unknown or malformed option leaves it literal on its own line.
 */
final class ACodeIncludeLineIsReadByItsSpellingTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function lines(): iterable
    {
        yield 'unknown key' => ['{{ data/p2.csv @code:csv @lins:2-1 }}'];
        yield 'unknown bare key' => ['{{ a.csv @code @foo }}'];
        yield 'key glued to a range' => ['  {{ data/p2.csv @code:csv @lines01-2 }}'];
        yield 'malformed language' => ['{{ data/p2.csv @code::csv @liness:0-2 }}'];
        yield 'section beside it' => ['{{ a.csv #sec @code:csv }}'];
        yield 'valid but unresolved' => ['{{ a.csv @code:js }}'];
    }

    #[DataProvider('lines')]
    public function testTheLineIsLiteralAndEndsItsParagraph(string $line): void
    {
        $this->assertSame(
            '<p>' . trim($line) . "</p>\n<p>more</p>",
            (new CarveConverter())->convert($line . "\nmore\n"),
        );
    }

    public function testALazyLineInAnItemIsTheNextParagraph(): void
    {
        $this->assertSame(
            "<ul>\n  <li>{{ a.csv @code @foo }}\n    ^ cap\n  </li>\n</ul>",
            (new CarveConverter())->convert("- {{ a.csv @code @foo }}\n^ cap\n"),
        );
    }

    public function testABareCodeDirectiveStaysLiteralInsideASentence(): void
    {
        $this->assertSame(
            '<p>See {{ a.csv @code @foo }} here.</p>',
            (new CarveConverter())->convert("See {{ a.csv @code @foo }} here.\n"),
        );
    }
}
