<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 12 §4: a comment fence's span begins at its opening `%`, not in the
 * indentation before it (carve-php#4620).
 */
class AnIndentedCommentFenceSpanStartsAtItsPercentTest extends TestCase
{
    /**
     * @return array<string, array{string, int, int}>
     */
    public static function fences(): array
    {
        return [
            'one space' => [" %%%\n %%%\n", 1, 2],
            'four spaces' => ["    %%%\n     x\n    %%%\n", 4, 5],
            'after a description' => [":: t\n:  x\n  %%%\ny\n  %%%\nz\n", 12, 3],
        ];
    }

    #[DataProvider('fences')]
    public function testTheSpanStartsAtThePercent(string $source, int $startOffset, int $startColumn): void
    {
        $children = (new AstCodec())->encode((new BlockParser(trackPositions: true))->parse($source))['children'] ?? null;
        self::assertIsArray($children);
        $comment = null;
        foreach ($children as $child) {
            if (is_array($child) && ($child['type'] ?? null) === 'comment') {
                $comment = $child;
            }
        }
        self::assertIsArray($comment);
        $pos = $comment['pos'] ?? null;
        self::assertIsArray($pos);
        self::assertSame($startOffset, $pos['startOffset'] ?? null);
        self::assertSame($startColumn, $pos['startColumn'] ?? null);
        self::assertSame('%', $source[$startOffset]);
    }
}
