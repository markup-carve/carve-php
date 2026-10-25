<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MarkdownCaptionFootnoteTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function captions(): array
    {
        return [
            'figure' => ["![a](u)\n^ cap[^n]\n\n[^n]: Body\n", "![a](u)\ncap[^n]\n\n[^n]: Body\n"],
            'table' => ["| h |\n|---|\n| x |\n^ cap[^n]\n\n[^n]: Body\n", "| h |\n| --- |\n| x |\n\ncap[^n]\n\n[^n]: Body\n"],
            'figure repeated' => ["![a](u)\n^ cap[^n] and [^n]\n\n[^n]: Body\n", "![a](u)\ncap[^n] and [^n]\n\n[^n]: Body\n"],
            'table repeated' => ["| h |\n|---|\n| x |\n^ cap[^n] and [^n]\n\n[^n]: Body\n", "| h |\n| --- |\n| x |\n\ncap[^n] and [^n]\n\n[^n]: Body\n"],
        ];
    }

    #[DataProvider('captions')]
    public function testCaptionReferencesStayActive(string $source, string $expected): void
    {
        $converter = CarveConverter::markdown();
        $report = $converter->convertWithReport($source);
        self::assertSame($expected, $report->value);
        self::assertSame(['markdown-caption-flattened'], array_column($report->losses, 'code'));
    }
}
