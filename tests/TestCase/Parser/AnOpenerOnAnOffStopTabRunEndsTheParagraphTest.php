<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 0 S2 measures a tab after each open frame is stripped, so ` <TAB>- z` under a
 * two-column item lands past the nested item's body column. S3 still opens the block
 * there; it does not fold into the paragraph above (carve-php#5051).
 */
class AnOpenerOnAnOffStopTabRunEndsTheParagraphTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function shiftedOpeners(): array
    {
        return [
            'after an attribute paragraph' => ["- a\n  - a\n  \t- a\n    {.x}\n  \ta\n  \t- z\n", '<ul><li>z</li></ul>'],
            'after a blank' => ["- a\n  - a\n    - a\n\n    a\n  \t- z\n", '<ul><li>z</li></ul>'],
            'ordered marker' => ["- a\n  - a\n  \t- a\n    {.x}\n  \ta\n  \t1. z\n", '<ol><li>z</li></ol>'],
            'quoted host' => ["> - a\n>   - a\n>     - a\n>     {.x}\n>     a\n>   \t- z\n", '<ul><li>z</li></ul>'],
        ];
    }

    #[DataProvider('shiftedOpeners')]
    public function testTheOpenerOpensItsBlock(string $source, string $block): void
    {
        $html = (string)preg_replace('/>\s+</', '><', (new CarveConverter())->convert($source));

        $this->assertMatchesRegularExpression('#<p(?: class="x")?>a</p>' . preg_quote($block, '#') . '#', $html);
    }
}
