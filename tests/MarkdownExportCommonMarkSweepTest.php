<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MarkdownExportCommonMarkSweepTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideDocuments(): array
    {
        return [
            'break under a quote in a tight item' => ["- a\n  > q\n   ---\n", "- a\n  > q\n  ---\n"],
            'break under a heading in a tight item' => ["- a\n  # h\n  ---\n", "- a\n  # h\n  ---\n"],
            'paragraph under a break in a tight item' => ["- x\n  a\n  ---\n  c\n", "- x\n  a\n  ***\n  c\n"],
            'reference to a heading of an unreferenced footnote' => ["[^n]: # H\n</#H>\n", "</#H>\n\n[^n]: # H\n"],
            'reference above the unreferenced footnote' => ["x </#H> y\n\n[^n]: # H\n", "x </#H> y\n\n[^n]: # H\n"],
            'unreferenced footnote heading takes no slug' => ["</#H>\n\n[^n]: # H\n\n[^m]: # H\n\ny[^m]\n", "[H](#h)\n\ny[^m]\n\n[^n]: # H\n[^m]: # H\n"],
        ];
    }

    #[DataProvider('provideDocuments')]
    public function testMarkdown(string $carve, string $markdown): void
    {
        self::assertSame($markdown, CarveConverter::markdown()->convert($carve));
    }
}
