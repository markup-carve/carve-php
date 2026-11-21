<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Test\Support\Linearity;
use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\TestCase;

final class FormatLinearityTest extends TestCase
{
    public function testFormattingDistinctParagraphsScalesLinearly(): void
    {
        $small = self::document(32);
        $large = self::document(256);
        $excess = Linearity::excess(
            static fn () => Writer::formatWithDiagnostics($small),
            static fn () => Writer::formatWithDiagnostics($large),
            8,
        );

        $this->assertLessThanOrEqual(Linearity::LIMIT, $excess);
    }

    private static function document(int $paragraphs): string
    {
        $source = '';
        for ($i = 0; $i < $paragraphs; $i++) {
            $source .= 'Paragraph ' . $i . ' has *bold* and [link](https://example.test/' . $i . ").\n\n";
        }

        return $source;
    }
}
