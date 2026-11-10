<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Parser\BlockParserState;
use MarkupCarve\Carve\Parser\BlockSourceMapper;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RefusedBraceOpenerSourceMapTest extends TestCase
{
    public function testAnOpenerEscapeDoesNotShiftLaterPunctuationOrLines(): void
    {
        $source = "{.x\ny";
        $state = new BlockParserState();
        $state->source->trackPositions = true;
        $state->source->authoredLines = ['{.x', 'y'];
        $state->source->lineStartOffsets = [0, 4];

        $mapper = new BlockSourceMapper(
            $state,
            static fn (): never => throw new RuntimeException('Table parser is not used'),
            static fn (): string => $source,
        );
        $map = $mapper->foldedLinesMap([[0, 0, 3, '{.x'], [1, 0, 1, 'y']], 0, [0 => true]);

        self::assertNotNull($map);
        self::assertSame([1, 1, 2], $map->resolve(2));
        self::assertSame([4, 2, 1], $map->resolve(5));
    }
}
