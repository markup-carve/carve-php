<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#5432: a caption at a marker-line `+` host's content column captions the quote the `+` took.
 */
final class ACaptionAtThePlusHostColumnCaptionsTheAttachedQuoteTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function hosts(): iterable
    {
        yield 'item' => ["- +\n> q\n  ^ cap\n", "<li>\n    <figure>\n      <blockquote><p>q</p></blockquote>\n      <figcaption>cap</figcaption>\n    </figure>\n  </li>"];

        yield 'footnote' => ["[^1]: +\n> q\n  ^ cap\n\n[^1]\n", "<li id=\"fn1\">\n      <figure>\n        <blockquote><p>q</p></blockquote>\n        <figcaption>cap</figcaption>\n      </figure>"];

        yield 'description' => [":: t\n: +\n> q\n  ^ cap\n", "<dd>\n    <figure>\n      <blockquote><p>q</p></blockquote>\n      <figcaption>cap</figcaption>\n    </figure>\n  </dd>"];
    }

    #[DataProvider('hosts')]
    public function testTheCaptionCaptionsTheQuote(string $source, string $expected): void
    {
        self::assertStringContainsString($expected, (new CarveConverter())->convert($source));
    }

    public function testACaptionPastTheContentColumnFoldsIntoTheQuote(): void
    {
        self::assertStringContainsString("<blockquote><p>q\n^ cap</p></blockquote>", (new CarveConverter())->convert("- +\n> q\n   ^ cap\n"));
    }
}
