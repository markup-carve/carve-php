<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class MarkdownEmptyFootnoteDefinitionTest extends TestCase
{
    public function testEmptyDefinitionHasNoTrailingSpace(): void
    {
        $source = "[^m]: +\n[^n]: +\n[^n]: +\n\n  para\n\nsee[^m][^n]\n";

        self::assertSame("see[^m][^n]\n\n[^m]:\n[^n]: para\n", CarveConverter::markdown()->convert($source));
    }
}
