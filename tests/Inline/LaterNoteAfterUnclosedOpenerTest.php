<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Inline;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

final class LaterNoteAfterUnclosedOpenerTest extends TestCase
{
    public function testLaterNoteUsesItsOwnClosingBracket(): void
    {
        $tree = json_decode(Carve::toJson('^[^[][,^[]'), true, 512, JSON_THROW_ON_ERROR);
        $children = $tree['children'][0]['children'];
        self::assertSame(['text', 'inline_footnote', 'text', 'inline_footnote'], array_column($children, 'type'));
        self::assertSame('^[', $children[0]['value']);
        self::assertSame([], $children[1]['children']);
        self::assertSame('[,', $children[2]['value']);
        self::assertSame([], $children[3]['children']);
    }
}
