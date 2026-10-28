<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FootnoteLazyContinuationRulingTest extends TestCase
{
    public function testOpenFootnoteParagraphTakesAnUnindentedLine(): void
    {
        $html = (new CarveConverter())->convert("see[^n]\n\n[^n]: note\ntail\n");

        self::assertStringContainsString("<p>note\ntail<a href=", $html);
        self::assertStringNotContainsString('<p>tail</p>', $html);
    }

    public function testAnInterruptingHeadingEndsTheFootnoteBody(): void
    {
        $html = (new CarveConverter())->convert("see[^n]\n\n[^n]: note\n# heading\n");

        self::assertStringContainsString('<h1>heading</h1>', $html);
        self::assertStringNotContainsString("note\n# heading", $html);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostedNotes(): iterable
    {
        yield 'list item' => ["- parent\n  [^n]: note\n  tail\n\nsee[^n]\n"];
        yield 'quote' => ["> [^n]: note\n> tail\n\nsee[^n]\n"];
        yield 'description' => [":: term\n:  [^n]: note\n   tail\n\nsee[^n]\n"];
    }

    #[DataProvider('hostedNotes')]
    public function testHostedFootnoteKeepsItsLazyLine(string $source): void
    {
        $html = (new CarveConverter())->convert($source);

        self::assertStringContainsString("<p>note\ntail<a href=", $html);
    }
}
