<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 11 §1 on the raw writer: S1 erases comment and one-line definition
 * lines (carve#5012), so the blanks and boundaries they stood between must
 * still hold without them (corpus 945, 447-24).
 */
class TheWriterKeepsWhatErasedLinesPartTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'comment run in a loose item' => ["- a\n\n  %% y\n%% z\n- c\n", "- a\n\n  %% y\n  %% z\n\n- c\n"],
            'comment run in a quoted loose item' => ["> - a\n>\n>   %% y\n> %% z\n> - c\n", "> - a\n>\n>   %% y\n>   %% z\n>\n> - c\n"],
            'definition between item paragraphs' => ["- a\n+\n[r]: /a\n[r]: /b\nmore\n\nSee [r][].\n", "- a\n+\n[r]: /b\nmore\n\nSee [r][].\n"],
        ];
    }

    #[DataProvider('cases')]
    public function testTheWrittenDocumentRendersTheSameAndIsStable(string $source, string $expected): void
    {
        $written = $this->write($source);
        $this->assertSame($expected, $written);
        $this->assertSame((new CarveConverter())->convert($source), (new CarveConverter())->convert($written));
        $this->assertSame($written, $this->write($written));
    }

    private function write(string $source): string
    {
        return CarveConverter::carve(new BlockParser(false, false, false, true))->convert($source);
    }
}
