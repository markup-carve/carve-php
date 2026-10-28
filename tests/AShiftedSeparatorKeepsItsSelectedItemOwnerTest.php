<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AShiftedSeparatorKeepsItsSelectedItemOwnerTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function shiftedSeparators(): array
    {
        return [
            'root term after column-zero separator' => [
                "- * :: t\n: a\n    :: sibling\n    tail\n",
                '~<li><dl><dt>t\s*: a</dt><dt>sibling\s*tail</dt></dl></li>~',
            ],
            'quoted list after one-column-shifted separator' => [
                "> - * :: t\n>    : a\n>     - sibling\n>     tail\n",
                '~<li><dl><dt>t\s*: a</dt></dl><ul><li>sibling\s*tail</li></ul></li>~',
            ],
            'list-hosted heading after shifted separator' => [
                "- wrap\n  - * :: t\n   : a\n      # sibling\n      tail\n",
                '~<li><dl><dt>t\s*: a</dt></dl><h1 id="sibling">sibling</h1>\s*tail\s*</li>~',
            ],
            'div-hosted term after shifted separator' => [
                "::: box\n- * :: t\n : a\n    :: sibling\n    tail\n:::\n",
                '~<li><dl><dt>t\s*: a</dt><dt>sibling\s*tail</dt></dl></li>~',
            ],
            'term at authored base after separator at outer item column' => [
                "- * :: t\n  : a\n    :: sibling\n    tail\n",
                '~</ul>\s*: a\s*<dl><dt>sibling\s*tail</dt></dl></li>~',
            ],
            'ordered child releases the later term' => [
                "- 1. :: t\n  : a\n    :: sibling\n    tail\n",
                '~</ol>\s*: a\s*<dl><dt>sibling\s*tail</dt></dl></li>~',
            ],
            'ordered child releases the later list' => [
                "- 1. :: t\n  : a\n    - sibling\n    tail\n",
                '~</ol>\s*: a\s*<ul><li>sibling\s*tail</li></ul></li>~',
            ],
        ];
    }

    #[DataProvider('shiftedSeparators')]
    public function testLaterBlockKeepsTheOwnerSelectedByTheSeparator(string $source, string $expected): void
    {
        $html = CarveConverter::create()->convert($source);
        $compact = preg_replace('/>\s+</', '><', $html);

        self::assertNotNull($compact);
        self::assertMatchesRegularExpression($expected, $compact);
    }
}
