<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Footnote;
use PHPUnit\Framework\TestCase;

final class HoistedNestedFootnoteLeavesEmptyOuterSpanTest extends TestCase
{
    public function testEmptyOuterSpanStopsOnItsDefinitionLine(): void
    {
        foreach (
            [
                ["[^c]: [^d]: para\n          more\n", 16],
                ["[^c0]: [^d0]: p\n            more\n\n", 15],
            ] as [$source, $endOffset]
        ) {
            $document = (new CarveConverter())->getParser();
            $document->enablePositionTracking();
            $footnotes = $document->parse($source)->getChildren();

            self::assertCount(2, $footnotes);
            self::assertInstanceOf(Footnote::class, $footnotes[0]);
            self::assertSame([], $footnotes[0]->getChildren());
            $pos = $footnotes[0]->getPos();
            self::assertNotNull($pos);
            self::assertSame([1, 1, 0, $endOffset], [$pos->startLine, $pos->endLine, $pos->startOffset, $pos->endOffset]);
        }
    }

    public function testBomKeepsTheOpeningLineEndColumn(): void
    {
        $converter = new CarveConverter();
        $converter->getParser()->enablePositionTracking();
        $footnote = $converter->parse("\u{FEFF}[^a]: [^b]: p\n          more\n\n")->getChildren()[0];

        self::assertInstanceOf(Footnote::class, $footnote);
        $pos = $footnote->getPos();
        self::assertNotNull($pos);
        self::assertSame([1, 1, 2, 15, 1, 14], [
            $pos->startLine,
            $pos->endLine,
            $pos->startColumn,
            $pos->endColumn,
            $pos->startOffset,
            $pos->endOffset,
        ]);
    }
}
