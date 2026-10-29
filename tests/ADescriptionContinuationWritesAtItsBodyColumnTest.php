<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ADescriptionContinuationWritesAtItsBodyColumnTest extends TestCase
{
    use RuleATodo;

    /**
     * Folded description lines and their written forms.
     *
     * @return array<string, array{string, string}>
     */
    public static function foldedOpeners(): array
    {
        return [
            'quote' => ['> quote', '\\> quote'],
            'heading' => ['# H', '\\# H'],
            'two-hash heading' => ['## H', '\\#\\# H'],
            'six-hash heading' => ['###### H', '\\#\\#\\#\\#\\#\\# H'],
            'bare quote' => ['>', '\\>'],
            'double quote' => ['>> q', '>> q'],
            'table row' => ['| a |', '\\| a |'],
            'non-row pipe' => ['| a', '| a'],
            'tight table row' => ['|a|b|', '\\|a|b|'],
            'plain hash' => ['#tag', '#tag'],
        ];
    }

    #[DataProvider('foldedOpeners')]
    public function testFoldedOpenersAreEscapedAtTheBodyColumn(string $sourceLine, string $written): void
    {
        $formatted = CarveConverter::toCarve(":: term\n:  definition\n $sourceLine\n");
        self::assertSame(":: term\n: definition\n  $written\n", $formatted);
        self::assertSame($formatted, CarveConverter::toCarve($formatted));
    }

    public function testClosedDescriptionHeadingLeavesTheNextItemLineAtItsContentColumn(): void
    {
        $source = "- intro\n\n  :: term\n  :  definition\n      # H\n  tail\n";
        $expected = "- intro\n  :: term\n  : definition\n\n    # H\n  tail\n";
        self::assertSame($expected, CarveConverter::toCarve($source));
        self::assertSame($expected, CarveConverter::toCarve($expected));
    }
}
