<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Node\Inline\SoftBreak;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FoldedTermSoftBreakPositionTest extends TestCase
{
    use RuleATodo;

    /**
     * @return array<string, array{string, list<list<int>>}>
     */
    public static function commentDocuments(): array
    {
        return [
            'bare' => ['', []],
            'quoted' => ['-2', []],
            'item' => ['-3', []],
            'description' => ['-4', []],
            'fenced' => ['-5', []],
            'quoted fenced' => ['-6', []],
            'item fenced' => ['-7', []],
            'description fenced' => ['-8', []],
            'nested fenced' => ['-22', []],
            'nested comment' => ['-24', []],
        ];
    }

    /**
     * @return array<string, array{string, list<list<int>>}>
     */
    public static function unchangedDocuments(): array
    {
        return [
            '-10' => ['-10', []],
            '-11' => ['-11', []],
            '-12' => ['-12', []],
            '-13' => ['-13', []],
            '-14' => ['-14', []],
            '-15' => ['-15', []],
            '-16' => ['-16', []],
            '-17' => ['-17', []],
            '-18' => ['-18', []],
            '-19' => ['-19', []],
            '-20' => ['-20', []],
            '-21' => ['-21', []],
            '-23' => ['-23', []],
            '-25' => ['-25', []],
            '-9' => ['-9', []],
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
                [],
            ],
            'consecutive comments' => [
                ":: c\n  %% a\n  %% b\n  more\n",
                [],
            ],
            'escaped term ending' => [
                ":: foo\\*\n  %% note\n  more\n",
                [],
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
        $this->assertCount(0, $breaks);
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
