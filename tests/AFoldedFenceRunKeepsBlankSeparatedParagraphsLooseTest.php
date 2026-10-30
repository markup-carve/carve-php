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

    /**
     * An unterminated run opens a fence that holds the blank and the tail (carve#4032).
     */
    #[DataProvider('runs')]
    public function testBlankBelowAnUnterminatedRunStaysInTheFence(string $run): void
    {
        $html = CarveConverter::create()->convert("- q\n  {$run}\n\n  tail\n");

        $this->assertSame(0, substr_count($html, '<p>'));
        $this->assertStringContainsString("<pre><code>\ntail\n</code></pre>", $html);
    }

    public function testFmtKeepsTheUnterminatedRunAFence(): void
    {
        $source = "- outer\n  - head\n     ~~~\n     a\n ~~~\n\n    tail\n";

        $formatted = CarveConverter::create(null, new CarveRenderer())->convert($source);

        $this->assertStringNotContainsString('{loose}', $formatted);
        $this->assertSame("- outer\n  - head\n    ```\n    a\n    ```\n\n```\n\n   tail\n```\n", $formatted);
    }
}
