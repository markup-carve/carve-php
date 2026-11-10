<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A marker-glued attribute has zero item-column width but retains its authored
 * width for the brace run that follows it (CARVE-P2-035, carve#5788).
 */
final class AMarkerGluedBraceUsesSourceColumnTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function runs(): array
    {
        return [
            'bullet at the brace column' => ["-{.x} {.x\n      .y}\n  # h\n", "<ul>\n  <li class=\"x\">{.x\n.y}\n# h</li>\n</ul>"],
            'bullet left of the brace' => ["-{.x} {.x\n     .y}\n  # h\n", "<ul>\n  <li class=\"x\">{.x\n.y}\n# h</li>\n</ul>"],
            'bullet right of the brace' => ["-{.x} {.x\n       .y}\n  # h\n", "<ul>\n  <li class=\"x\">\n    <h1 class=\"x y\" id=\"h\">h</h1>\n  </li>\n</ul>"],
            'ordered marker' => ["1.{.x} {.x\n      .y}\n   # h\n", "<ol>\n  <li class=\"x\">{.x\n.y}\n# h</li>\n</ol>"],
            'different metadata text' => ["-{.a} {.x\n      .y}\n  # h\n", "<ul>\n  <li class=\"a\">{.x\n.y}\n# h</li>\n</ul>"],
        ];
    }

    #[DataProvider('runs')]
    public function testTheWrittenBraceColumnDecidesTheRun(string $source, string $html): void
    {
        self::assertSame($html, (new CarveConverter())->convert($source));
    }
}
