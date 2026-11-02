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
                '~<ul><li><ul><li><dl><dt>t</dt></dl></li></ul></li></ul><p>:\s*a</p><dl><dt>sibling</dt></dl><p>tail</p>~',
            ],
            'quoted list after one-column-shifted separator' => [
                "> - * :: t\n>    : a\n>     - sibling\n>     tail\n",
                '~<blockquote><ul><li><ul><li><dl><dt>t</dt></dl></li></ul>\s*:\s*a\s*<ul><li>sibling\s*tail</li></ul></li></ul></blockquote>~',
            ],
            'list-hosted heading after shifted separator' => [
                "- wrap\n  - * :: t\n   : a\n      # sibling\n      tail\n",
                '~<ul><li>wrap\s*<ul><li><ul><li><dl><dt>t</dt></dl></li></ul></li></ul>\s*:\s*a\s*<h1\s*id="sibling">sibling</h1>\s*tail\s*</li></ul>~',
            ],
            'div-hosted term after shifted separator' => [
                "::: box\n- * :: t\n : a\n    :: sibling\n    tail\n:::\n",
                '~<ul><li><ul><li><dl><dt>t</dt></dl></li></ul></li></ul><p>:\s*a</p><dl><dt>sibling</dt></dl><p>tail</p>~',
            ],
            'term at authored base after separator at outer item column' => [
                "- * :: t\n  : a\n    :: sibling\n    tail\n",
                '~<ul><li><ul><li><dl><dt>t</dt></dl></li></ul>\s*:\s*a\s*<dl><dt>sibling</dt></dl>\s*tail\s*</li></ul>~',
            ],
            'ordered child releases the later term' => [
                "- 1. :: t\n  : a\n    :: sibling\n    tail\n",
                '~<ul><li><ol><li><dl><dt>t</dt></dl></li></ol>\s*:\s*a\s*<dl><dt>sibling</dt></dl>\s*tail\s*</li></ul>~',
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
