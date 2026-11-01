<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A payload line is inline content whatever it looks like, so an open stanza
 * takes a lazy line in every host (carve#4748).
 */
final class LineBlockLazyLineInEveryHostTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function foldingSources(): iterable
    {
        foreach (['x', '{#i}', '{.k}', '~~~', '[x]: /u'] as $payload) {
            yield "quote-item-$payload" => ["> - ::: |\n>   $payload\nb\n"];
            yield "description-quote-item-$payload" => [":: t\n: > - ::: |\n  >   $payload\nb\n"];
            yield "quote-quote-$payload" => [":: t\n: > > ::: |\n  > > $payload\nb\n"];
        }
    }

    #[DataProvider('foldingSources')]
    public function testTheLazyLineJoinsTheStanza(string $source): void
    {
        $html = (new CarveConverter())->convert($source);

        self::assertStringContainsString("<br>\nb</p>", $html, $source);
        self::assertStringNotContainsString('<p>b</p>', $html, $source);
    }

    public function testABlankPayloadLineEndsTheStanza(): void
    {
        $html = (new CarveConverter())->convert("- ::: |\n  a\n\nb\n");

        self::assertStringEndsWith("</ul>\n<p>b</p>", trim($html));
    }

    public function testACloserLeftOfTheOpenerColumnClosesTheLineBlock(): void
    {
        $html = (new CarveConverter())->convert("> - a\n>\n>     ::: |\n>     x\n>   :::\n- tail\n");

        self::assertStringEndsWith("</blockquote>\n<ul>\n  <li>tail</li>\n</ul>", trim($html));
    }
}
