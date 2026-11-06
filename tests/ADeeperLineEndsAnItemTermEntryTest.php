<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A term holds no body, so a deeper line ends its entry and a later `: ` line is paragraph text (PART 0 S4, carve#5289, carve#5317).
 */
class ADeeperLineEndsAnItemTermEntryTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function documents(): array
    {
        return [
            'marker-line term, deeper line, then a description-shaped line (carve#5317)' => [
                "- :: t\n   a\n  : x\n: z\n",
                "<ul>\n  <li>\n    <dl>\n      <dt>t</dt>\n    </dl>\n    a\n: x\n  </li>\n</ul>\n<p>: z</p>\n",
            ],
            'no term: the line folds as before' => [
                "- u\n  : x\n: z\n",
                "<ul>\n  <li>u\n: x\n: z</li>\n</ul>\n",
            ],
            'description right after the term still ends the item' => [
                "- :: t\n  : x\n: z\n",
                "<ul>\n  <li>\n    <dl>\n      <dt>t</dt>\n      <dd>x</dd>\n    </dl>\n  </li>\n</ul>\n<p>: z</p>\n",
            ],
        ];
    }

    #[DataProvider('documents')]
    public function testRendersAsTheClauseReads(string $source, string $expected): void
    {
        $this->assertSame(trim($expected), trim((new CarveConverter())->convert($source)));
    }
}
