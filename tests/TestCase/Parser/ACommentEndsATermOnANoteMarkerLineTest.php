<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Expected output is the spec model's at carve main 5a82756e (carve-php#4243).
 */
final class ACommentEndsATermOnANoteMarkerLineTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function notes(): iterable
    {
        yield 'a list-hosted note' => ["- [^n]: :: term\n    %% c\n    x\n\n[^n]\n"];

        yield 'a document note' => ["[^n]: :: term\n  %% c\n  x\n\n[^n]\n"];

        yield 'past the body column' => ["- [^n]: :: term\n      %% c\n      x\n\n[^n]\n"];

        yield 'at the term column' => ["- [^n]: :: term\n          %% c\n          x\n\n[^n]\n"];

        yield 'in a quote' => ["> - [^n]: :: term\n>     %% c\n>     x\n\n[^n]\n"];
    }

    #[DataProvider('notes')]
    public function testTheNoteOwnsTheCommentAndTheLineAfterIt(string $source): void
    {
        $html = (new CarveConverter())->convert($source);

        self::assertStringContainsString('<dt>term</dt>', $html);
        self::assertStringContainsString('<p>x<a href="#fnref1"', $html);
    }

    public function testATermOutsideANoteKeepsItsBelowColumnCommentReading(): void
    {
        self::assertSame(
            "<dl>\n  <dt>term</dt>\n</dl>\n<p>tail</p>",
            trim((new CarveConverter())->convert(":: term\n  %% c\ntail\n")),
        );
    }
}
