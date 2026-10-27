<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\HeadingIdTracker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PairedQuotationHeadingIdTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function pairedMarks(): array
    {
        return [
            'German double' => ['„word“'],
            'German single' => ['‚word‘'],
            'French double' => ['«word»'],
            'reversed double' => ['‟word”'],
            'reversed single' => ['‛word’'],
        ];
    }

    #[DataProvider('pairedMarks')]
    public function testBothMarksAreRemovedBeforeSlugging(string $quoted): void
    {
        self::assertSame('q-word', (new HeadingIdTracker())->normalizeId('q ' . $quoted));
    }

    public function testGermanQuotesRenderWithAnAsciiFreeAutomaticId(): void
    {
        self::assertSame(
            "<section id=\"q-2-active\">\n  <h1>q „2 active“</h1>\n</section>",
            CarveConverter::create()->convert("# q „2 active“\n"),
        );
    }
}
