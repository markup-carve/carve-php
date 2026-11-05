<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A brace run a `+` attaches is refused by its continuation's column (CARVE-P2-035): it is visible paragraph text,
 * its follower joins it, and moving it to the marker column keeps that column (carve#5195).
 */
final class ARefusedBraceRunAfterAPlusIsTheAttachedParagraphTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'the reproduction' => ["- a\n+\n{% x\n%}\n", "<ul>\n  <li>a\n    {% x\n%}\n  </li>\n</ul>"];
        yield 'a follower joins the paragraph' => ["- a\n+\n{% x\n%}\n{y}\n more\n", "<ul>\n  <li>a\n    {% x\n%}\n{y}\nmore\n  </li>\n</ul>"];
        yield 'a first-block plus' => ["- +\n{% x\n%}\n# H\n", "<ul>\n  <li>{% x\n%}\n# H</li>\n</ul>"];
        yield 'a comment run stays invisible' => ["- a\n+\n{% x\n %}\n# H\n", "<ul>\n  <li>a\n    <h1 id=\"H\">H</h1>\n  </li>\n</ul>"];
        yield 'an indented host keeps the run refused' => ["  - a\n  +\n  {% x\n  %}\n  # H\n", "<ul>\n  <li>a\n    {% x\n%}\n# H\n  </li>\n</ul>"];
        yield 'an indented host keeps an attribute run refused' => ["  - a\n  +\n  {x\n  }\n  # H\n", "<ul>\n  <li>a\n    {x\n}\n# H\n  </li>\n</ul>"];
        yield 'a quote takes the follower of an attribute run' => ["> a\n+\n{x\n}\n# H\n", "<blockquote>\n  <p>a</p>\n  <p>{x\n}\n# H</p>\n</blockquote>"];
        yield 'a quote takes the follower of a comment run' => ["> a\n+\n{% x\n%}\n{y}\n", "<blockquote>\n  <p>a</p>\n  <p>{% x\n%}\n{y}</p>\n</blockquote>"];
        yield 'an attribute run in an item takes its follower' => ["- a\n+\n{x\n}\n{y}\n more\n", "<ul>\n  <li>a\n    {x\n}\n{y}\nmore\n  </li>\n</ul>"];
    }

    #[DataProvider('documents')]
    public function testTheRunIsTheAttachedParagraph(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
