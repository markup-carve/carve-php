<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Comment;
use PHPUnit\Framework\TestCase;

final class OwnedCommentPayloadIndentTest extends TestCase
{
    public function testCommentBodyRetainsSpaceBeyondItsReachedItemPrefix(): void
    {
        foreach (
            [
                ["- +\n   %%%\n   c\n   %%%\ntext\n", ' c'],
                ["- - ::: |\n    %%%\n   %%%%\n    %%%\n    b\nz\n", ' %%%%'],
            ] as [$source, $expected]
        ) {
            $document = (new CarveConverter())->parse($source);
            $pending = [$document];
            $comments = [];
            while ($pending !== []) {
                $node = array_pop($pending);
                if ($node instanceof Comment && $node->getFenceLength() !== null) {
                    $comments[] = $node;
                }
                array_push($pending, ...$node->getChildren());
            }

            self::assertCount(1, $comments);
            self::assertSame($expected, $comments[0]->getContent());
        }
    }
}
