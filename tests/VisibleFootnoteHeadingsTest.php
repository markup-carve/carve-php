<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VisibleFootnoteHeadingsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function visibleReferences(): iterable
    {
        yield 'implicit' => ["x[^n]\n\n[^n]: # H\n\n[H][]\n", '<a href="#H">H</a>'];
        yield 'explicit' => ["x[^n]\n\n[^n]: # H\n\n</#H>\n", '<a href="#H">H</a>'];
        yield 'nested note' => ["x[^a]\n\n[^a]: see[^b]\n\n[^b]: # H\n\n[H][]\n", '<a href="#H">H</a>'];
    }

    #[DataProvider('visibleReferences')]
    public function testAUsedNoteSuppliesHeadingTargets(string $source, string $expected): void
    {
        self::assertStringContainsString($expected, (new CarveConverter())->convert($source));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function hiddenReferences(): iterable
    {
        yield 'implicit' => ["[^n]: # H\n\n[H][]\n", '<p>[H][]</p>'];
        yield 'explicit' => ["[^n]: # H\n\n</#H>\n", '<p>&lt;/#H&gt;</p>'];
    }

    #[DataProvider('hiddenReferences')]
    public function testAnUnusedNoteSuppliesNoHeadingTarget(string $source, string $expected): void
    {
        self::assertStringContainsString($expected, (new CarveConverter())->convert($source));
    }

    public function testHeadingIdsFollowFirstReferenceOrder(): void
    {
        $source = "x[^b] y[^a]\n\n[^a]: # H\n\n[^b]: # H\n\n[H][]\n";
        $html = (new CarveConverter())->convert($source);

        self::assertStringContainsString('<a href="#H">H</a>', $html);
        self::assertSame(1, substr_count($html, '<h1 id="H">'));
        self::assertSame(1, substr_count($html, '<h1 id="H-2">'));
    }
}
