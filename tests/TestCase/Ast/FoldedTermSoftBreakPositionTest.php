<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Node\Inline\SoftBreak;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FoldedTermSoftBreakPositionTest extends TestCase
{
    /**
     * @return array<string, array{string, list<list<int>>}>
     */
    public static function commentDocuments(): array
    {
        return [
            'bare' => ['', [[4, 7, 1, 5, 2, 3], [14, 15, 2, 10, 3, 1]]],
            'quoted' => ['-2', [[6, 11, 1, 7, 2, 5], [18, 21, 2, 12, 3, 3]]],
            'item' => ['-3', [[14, 19, 3, 7, 4, 5], [26, 29, 4, 12, 5, 3]]],
            'description' => ['-4', [[15, 20, 3, 7, 4, 5], [27, 30, 4, 12, 5, 3]]],
            'fenced' => ['-5', [[4, 7, 1, 5, 2, 3], [25, 26, 4, 6, 5, 1]]],
            'quoted fenced' => ['-6', [[6, 11, 1, 7, 2, 5], [33, 36, 4, 8, 5, 3]]],
            'item fenced' => ['-7', [[14, 19, 3, 7, 4, 5], [41, 44, 6, 8, 7, 3]]],
            'description fenced' => ['-8', [[15, 20, 3, 7, 4, 5], [42, 45, 6, 8, 7, 3]]],
            'nested fenced' => ['-22', [[23, 28, 5, 7, 6, 5], [51, 54, 9, 8, 10, 3]]],
            'nested comment' => ['-24', [[13, 18, 3, 7, 4, 5], [25, 28, 4, 12, 5, 3]]],
        ];
    }

    /**
     * @return array<string, array{string, list<list<int>>}>
     */
    public static function unchangedDocuments(): array
    {
        return [
            '-10' => ['-10', [[14, 17, 3, 7, 4, 3]]],
            '-11' => ['-11', [[22, 25, 5, 7, 6, 3]]],
            '-12' => ['-12', [[23, 26, 5, 7, 6, 3]]],
            '-13' => ['-13', [[11, 12, 3, 5, 4, 1]]],
            '-14' => ['-14', [[13, 16, 3, 7, 4, 3]]],
            '-15' => ['-15', [[21, 24, 5, 7, 6, 3]]],
            '-16' => ['-16', [[22, 25, 5, 7, 6, 3]]],
            '-17' => ['-17', []],
            '-18' => ['-18', []],
            '-19' => ['-19', []],
            '-20' => ['-20', []],
            '-21' => ['-21', []],
            '-23' => ['-23', [[6, 9, 1, 7, 2, 3]]],
            '-25' => ['-25', [[23, 26, 5, 7, 6, 3], [34, 37, 6, 11, 7, 3]]],
            '-9' => ['-9', [[12, 13, 3, 5, 4, 1]]],
        ];
    }

    /**
     * @param string $suffix
     * @param list<list<int>> $expected
     */
    #[DataProvider('commentDocuments')]
    #[DataProvider('unchangedDocuments')]
    public function testFoldedCommentBreaksHaveExactSpans(string $suffix, array $expected): void
    {
        $path = dirname(__DIR__, 2) . '/spec/tests/corpus/'
            . '504-a-comment-or-a-definition-under-a-definition-term-folds-at-every-depth' . $suffix . '.crv';
        $source = file_get_contents($path);
        $this->assertIsString($source);
        $document = (new BlockParser(trackPositions: true))->parse($source);
        $actual = [];
        foreach ($this->breaks($document) as $break) {
            $pos = $break->getPos();
            $this->assertNotNull($pos);
            $actual[] = [$pos->startOffset, $pos->endOffset, $pos->startLine, $pos->startColumn, $pos->endLine, $pos->endColumn];
        }
        $this->assertSame($expected, $actual);
    }

    /**
     * @return array<string, array{string, list<list<int>>}>
     */
    public static function edgeCases(): array
    {
        return [
            'CRLF and Unicode' => [
                ":: é\r\n  %% 注\r\n  more\r\n",
                [[4, 8, 1, 5, 2, 3], [12, 14, 2, 7, 3, 1]],
            ],
            'consecutive comments' => [
                ":: c\n  %% a\n  %% b\n  more\n",
                [[4, 7, 1, 5, 2, 3], [11, 14, 2, 7, 3, 3], [18, 19, 3, 7, 4, 1]],
            ],
            'escaped term ending' => [
                ":: foo\\*\n  %% note\n  more\n",
                [[8, 11, 1, 9, 2, 3], [18, 19, 2, 10, 3, 1]],
            ],
        ];
    }

    /**
     * @param string $source
     * @param list<list<int>> $expected
     */
    #[DataProvider('edgeCases')]
    public function testPartBoundariesUseOriginalSourceCoordinates(string $source, array $expected): void
    {
        $document = (new BlockParser(trackPositions: true))->parse($source);
        $actual = [];
        foreach ($this->breaks($document) as $break) {
            $pos = $break->getPos();
            $this->assertNotNull($pos);
            $actual[] = [$pos->startOffset, $pos->endOffset, $pos->startLine, $pos->startColumn, $pos->endLine, $pos->endColumn];
        }
        $this->assertSame($expected, $actual);
    }

    public function testTrackingCanRemainDisabled(): void
    {
        $document = (new BlockParser())->parse(":: c\n  %% note\n  more\n");
        $breaks = $this->breaks($document);
        $this->assertCount(2, $breaks);
        foreach ($breaks as $break) {
            $this->assertNull($break->getPos());
        }
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $node
     *
     * @return list<\MarkupCarve\Carve\Node\Inline\SoftBreak>
     */
    private function breaks(Node $node): array
    {
        $breaks = [];
        foreach ($node->getChildren() as $child) {
            if ($child instanceof SoftBreak) {
                $breaks[] = $child;
            }
            array_push($breaks, ...$this->breaks($child));
        }

        return $breaks;
    }
}
