<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class FootnoteLazyOwnershipTest extends TestCase
{
    public function testAFlushLineFoldsIntoTheOpenNoteParagraph(): void
    {
        $html = (new CarveConverter())->convert("[^f]: note\nmore\n\nsee[^f]\n");

        self::assertStringNotContainsString('<p>more</p>', $html);
        self::assertStringContainsString("<p>note\nmore", $html);
    }

    public function testARefusedPlusStaysLiteralNoteText(): void
    {
        $html = (new CarveConverter())->convert("[^f]: note\n+\n more\n\nsee[^f]\n");

        self::assertStringNotContainsString('<p>+</p>', $html);
        self::assertStringContainsString("<p>note\n+\nmore", $html);
    }

    public function testAListMarkerInTheContainingItemKeepsItsOwner(): void
    {
        $html = (new CarveConverter())->convert("- body\n\n  [^f]: note\n   - child\n\nafter\n");

        self::assertStringContainsString('<li>child</li>', $html);
        self::assertStringNotContainsString("<p>note\n- child", $html);
    }
}
