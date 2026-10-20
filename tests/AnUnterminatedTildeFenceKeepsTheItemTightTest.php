<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An unterminated fence at an item's content column opens no block and owns
 * the blank below it, so the item stays tight. A tilde run reads like a
 * backtick run (markup-carve/carve-php#3623).
 */
class AnUnterminatedTildeFenceKeepsTheItemTightTest extends TestCase
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
    public function testBlankBelowAnUnterminatedRunDoesNotLoosenTheItem(string $run): void
    {
        $html = CarveConverter::create()->convert("- q\n  {$run}\n\n  tail\n");

        $this->assertStringNotContainsString('<p>', $html);
    }

    public function testFmtKeepsTheLooseKeyWhereTheBlankCannotSayIt(): void
    {
        $source = "- outer\n  - head\n     ~~~\n     a\n ~~~\n\n    tail\n";

        $formatted = CarveConverter::create(null, new CarveRenderer())->convert($source);

        $this->assertStringContainsString("  {loose}\n  - head", $formatted);
    }
}
