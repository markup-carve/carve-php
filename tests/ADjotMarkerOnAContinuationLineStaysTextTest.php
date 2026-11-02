<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A Djot list item cannot interrupt a paragraph, so a marker on a continuation line is text
 * (carve#4951); a marker that starts a block widens at every marker of its chain (carve#4910).
 */
class ADjotMarkerOnAContinuationLineStaysTextTest extends TestCase
{
    /**
     * @return array<array{string}>
     */
    public static function continuations(): array
    {
        return [
            ["x\na. y\n"],
            ["x\n(a) y\n"],
            ["x\nb. c. y\n"],
            ["x\n1. a. y\n"],
            ["x\nJ. R. T\n"],
            ["x\n  a. y\n"],
            ["> x\n> a. y\n"],
        ];
    }

    #[DataProvider('continuations')]
    public function testAContinuationLineKeepsItsMarkerBytes(string $djot): void
    {
        self::assertSame($djot, (new DjotToCarve())->convert($djot));
    }

    public function testADescriptionBodyLineStaysText(): void
    {
        self::assertStringContainsString("\n   a. y\n", (new DjotToCarve())->convert(": t\n\n  d\n  a. y\n"));
    }

    /**
     * @return array<array{string, string}>
     */
    public static function blockStarts(): array
    {
        return [
            ["x\n\na. y\n", "x\n\na.  y\n"],
            ["# h\na. y\n", "# h\na.  y\n"],
            ["- x\na. y\n", "- x\n\na.  y\n"],
            ["- x\n> a. y\n", "- x\n> a.  y\n"],
            ["(a) y\n", "a.  y\n"],
            ["- a. x\n", "- a.  x\n"],
            ["1. a. x\n", "1. a.  x\n"],
            ["> - a. x\n", "> - a.  x\n"],
            ["J. R. Tolkien\n", "J.  R.  Tolkien\n"],
            ["a. b. x\n", "a.  b.  x\n"],
            ["a. `c` x\n", "a.  `c` x\n"],
            ["a. . x\n", "a.  \\. x\n"],
            ["foo.  a. x\n", "foo.  a. x\n"],
            [": t\n\n  - a. y\n", "{loose}\n:: t\n\n:  - a.  y\n"],
        ];
    }

    #[DataProvider('blockStarts')]
    public function testAMarkerThatStartsABlockIsRewritten(string $djot, string $carve): void
    {
        self::assertSame($carve, (new DjotToCarve())->convert($djot));
    }
}
