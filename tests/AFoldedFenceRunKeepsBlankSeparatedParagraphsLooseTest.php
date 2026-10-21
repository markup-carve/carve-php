<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AFoldedFenceRunKeepsBlankSeparatedParagraphsLooseTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function runs(): array
    {
        return [
            'backtick' => ['```'],
            'tilde' => ['~~~'],
            'long tilde' => ['~~~~'],
        ];
    }

    #[DataProvider('runs')]
    public function testBlankBelowAnUnterminatedRunLoosensTheItem(string $run): void
    {
        $html = CarveConverter::create()->convert("- q\n  {$run}\n\n  tail\n");

        $this->assertSame(2, substr_count($html, '<p>'));
    }

    public function testFmtUsesTheBlankToExpressLooseness(): void
    {
        $source = "- outer\n  - head\n     ~~~\n     a\n ~~~\n\n    tail\n";

        $formatted = CarveConverter::create(null, new CarveRenderer())->convert($source);

        $this->assertStringNotContainsString('{loose}', $formatted);
        $this->assertStringContainsString("\n\n    tail\n", $formatted);
    }
}
