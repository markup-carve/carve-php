<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#5303: a `%%%` span opened inside a div's body pairs with its closer at
 * any column (CARVE-P0-005), so the closer is not a new span hiding the `:::`.
 */
final class AnIndentedSpanInADivBodyEndsAtItsColumnZeroCloserTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideShapes(): array
    {
        $note = "<aside class=\"admonition note\" aria-label=\"Note\">\n  <ul>\n    <li>b</li>\n  </ul>\n%s</aside>";

        return [
            'ticket' => ["::: note\n- b\n  %%%\n  n\n%%%\n:::\n", sprintf($note, '')],
            'empty span' => ["::: note\n- b\n  %%%\n%%%\n:::\n", sprintf($note, '')],
            'blank before the span' => ["::: note\n- b\n\n  %%%\n  n\n%%%\n:::\n", sprintf($note, '')],
            'paragraph after the span' => ["::: note\n- b\n  %%%\n  n\n%%%\n\nafter\n:::\n", sprintf($note, "  <p>after</p>\n")],
            'colon run inside the span' => ["::: note\n- b\n  %%%\n  :::\n%%%\n:::\n", sprintf($note, '')],
        ];
    }

    #[DataProvider('provideShapes')]
    public function testTheColumnZeroRunClosesTheSpanAndTheColonFenceClosesTheDiv(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
