<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Block\Footnote;
use PHPUnit\Framework\TestCase;

final class LosingFootnotePlusHasNoCommentNodeTest extends TestCase
{
    public function testDuplicateMarkerDoesNotBecomeAComment(): void
    {
        $source = "[^n]: +\n[^n]: +\n\n  para\n\nsee[^n]\n";
        $document = (new CarveConverter())->parse($source);
        $notes = array_values(array_filter($document->getChildren(), static fn ($node): bool => $node instanceof Footnote));

        self::assertCount(1, $notes);
        self::assertCount(1, $notes[0]->getChildren());
        self::assertNotInstanceOf(Comment::class, $notes[0]->getChildren()[0]);
        self::assertSame("see[^n]\n\n[^n]: para\n", CarveConverter::toCarve($source));
    }
}
