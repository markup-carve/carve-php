<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class ANoteOwnedLazyLineDoesNotLoosenItsItemTest extends TestCase
{
    public function testNoteOwnedLazyLineLeavesTheFirstVisibleParagraphTight(): void
    {
        $source = "r[^n]\n\n- [^n]: x\n  Body.\n\n  Two.\n";
        $html = trim((new CarveConverter())->convert($source));

        self::assertStringContainsString("<ul>\n  <li>Two.</li>\n</ul>", $html);
        self::assertStringContainsString("<p>x\nBody.", $html);
    }

    public function testVisibleItemTextBeforeTheBlankStillLoosens(): void
    {
        $source = "r[^n]\n\n- Intro.\n  [^n]: x\n  Body.\n\n  Two.\n";
        $html = trim((new CarveConverter())->convert($source));

        self::assertStringContainsString('<li><p>Intro.</p>', $html);
        self::assertStringContainsString('<p>Two.</p>', $html);
    }
}
