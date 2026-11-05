<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

final class AttachedFencedCommentPositionTest extends TestCase
{
    public function testContinuationMarkerKeepsOneFencedComment(): void
    {
        $comments = self::comments("- +\n%%%\nc\n%%%\n\ntext\n");
        self::assertCount(1, $comments);
        self::assertSame('c', $comments[0]['content'] ?? null);
        self::assertSame(true, $comments[0]['block'] ?? null);
        self::assertSame(['startOffset' => 4, 'endOffset' => 13], self::offsets($comments[0]));
    }

    public function testNoteBodyKeepsItsEmptyFencedComment(): void
    {
        $comments = self::comments("- [^n]: t\n  %%%\n  %%%\n\n    ~~~\n    e\n]\n");
        self::assertCount(1, $comments);
        self::assertSame('', $comments[0]['content'] ?? null);
        self::assertSame(true, $comments[0]['block'] ?? null);
        self::assertSame(['startOffset' => 12, 'endOffset' => 21], self::offsets($comments[0]));
    }

    /**
     * @param array<string, mixed> $comment
     *
     * @return array{startOffset: mixed, endOffset: mixed}
     */
    private static function offsets(array $comment): array
    {
        $pos = $comment['pos'] ?? null;
        self::assertIsArray($pos);

        return ['startOffset' => $pos['startOffset'] ?? null, 'endOffset' => $pos['endOffset'] ?? null];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function comments(string $source): array
    {
        $parser = new BlockParser();
        $parser->enablePositionTracking();
        $ast = (new AstCodec())->encode($parser->parse($source));
        $found = [];
        $walk = static function (mixed $node) use (&$walk, &$found): void {
            if (!is_array($node)) {
                return;
            }
            if (($node['type'] ?? null) === 'comment') {
                $found[] = $node;
            }
            foreach ($node as $child) {
                $walk($child);
            }
        };
        $walk($ast);

        return $found;
    }
}
