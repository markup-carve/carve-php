<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The byte column a comment's `%%` markup opens on, past any indentation and any
 * container marker it follows (markup-carve/carve#1928, carve#1963).
 *
 * The parser sees the comment line with its container prefix cut off, and that
 * stripped line is a SUFFIX of the source line, so the prefix width plus the
 * stripped line's leading run is where the markup opens. A whole-line search for
 * `%%` would instead match one inside the prefix - a `[^%%]:` footnote label.
 * When the stripped line is not a suffix (the parser rewrote it), the prefix
 * width is unknown and the source line's own leading run is the fallback.
 */
class CommentMarkerColumnTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function markerColumns(): array
    {
        return [
            'flush top-level comment' => ['%% c', '%% c', 0],
            'one-space indent' => [' %% c', ' %% c', 1],
            'stripped list marker' => ['- %% x', '%% x', 2],
            'stripped quote marker' => ['> %% c', '%% c', 2],
            'percent inside a footnote label' => ['[^%%]: %% c', '%% c', 7],
            // The stripped line is NOT a suffix of the source (the parser
            // rewrote it), so the prefix width is unknown: fall back to the
            // source line's own leading run rather than a guessed marker.
            'rewritten line falls back to leading run' => ['   %% c', 'REWRITTEN', 3],
        ];
    }

    #[DataProvider('markerColumns')]
    public function testItOpensOnTheMarkup(string $sourceText, string $strippedLine, int $expected): void
    {
        $method = new ReflectionMethod(BlockParser::class, 'commentMarkerColumn');

        $this->assertSame($expected, $method->invoke(new BlockParser(), $sourceText, $strippedLine));
    }
}
