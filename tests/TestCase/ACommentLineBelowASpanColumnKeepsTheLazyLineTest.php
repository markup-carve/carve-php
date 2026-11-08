<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A comment line below a nested span's column is span payload, so the paragraph
 * after the span still takes a lazy line (carve#5486, carve#5021). Expected HTML
 * is the spec model's at carve e7897758.
 */
class ACommentLineBelowASpanColumnKeepsTheLazyLineTest extends TestCase
{
    /**
     * @return array<int, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            ["- - |\n    %%%\n  %%%%\n  %%%\n  b\nz\n", "<ul><li><ul><li>|</li></ul>\n    b\nz\n  </li></ul>"],
            ["- - x\n    %%%\n  %%%%\n  %%%\n  b\nz\n", "<ul><li><ul><li>x</li></ul>\n    b\nz\n  </li></ul>"],
            ["- 1. | y\n     %%%\n  %% c\n%%%\n     b\nz\n", "<ul><li><ol><li>| y\n        b\nz\n      </li></ol></li></ul>"],
            ["1. - a b\n     %%%\n     %%\n   %%%%\n%%%\n     b\ny z\n", "<ol><li><ul><li>a b\n        b\ny z\n      </li></ul></li></ol>"],
            ["- - - x\n      %%%%\n%% c\n    %%%%\n    b\nz\n", "<ul><li><ul><li><ul><li>x</li></ul>\n        b\nz\n      </li></ul></li></ul>"],
            ["* 1. - a b\n       %%%\n%%%%\n     %%%\n  b c\nz\n", "<ul><li><ol><li><ul><li>a b</li></ul></li></ol>\n    b c\nz\n  </li></ul>"],
            ["- - > x\n    %%%\n  %%%%\n%%%%\n  %%%\n  b\nz\n", "<ul><li><ul><li><blockquote><p>x</p></blockquote></li></ul>\n    b\nz\n  </li></ul>"],
        ];
    }

    #[DataProvider('cases')]
    public function testTheLineAfterTheSpanFolds(string $source, string $html): void
    {
        $rendered = (new CarveConverter())->convert($source);

        $this->assertSame($html, trim((string)preg_replace('/>\s+</', '><', $rendered)));
    }
}
