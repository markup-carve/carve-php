<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An unclosed `%%%` span written below its host's content column runs to the end of the frame its line reaches (PART 0 S1, carve#4907 F1, carve-php#4513).
 */
final class AnUnclosedCommentSpanBelowTheHostColumnBelongsToTheFrameItReachesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'item, a line at column 0' => [
            "- a\n %%%\n  x\ny\n",
            '<ul><li>a</li></ul>',
        ];

        yield 'item, a sibling marker' => [
            "- a\n %%%\n  x\n- c\n",
            '<ul><li>a</li></ul>',
        ];

        yield 'ordered item' => [
            "1. a\n  %%%\n   x\ny\n",
            '<ol><li>a</li></ol>',
        ];

        yield 'footnote' => [
            "[^n]: a\n %%%\n  x\ny\n",
            '',
        ];

        yield 'a closed span still follows its next visible line' => [
            "- a\n %%%\n  x\n %%%\n  b\n",
            "<ul><li>a\n    b\n  </li></ul>",
        ];

        yield 'a closed span before a column-0 line' => [
            "- a\n %%%\n  x\n %%%\nb\n",
            '<ul><li>a</li></ul><p>b</p>',
        ];
    }

    #[DataProvider('documents')]
    public function testRender(string $source, string $expected): void
    {
        $html = (string)preg_replace('/>\s+</', '><', (new CarveConverter())->convert($source));

        self::assertSame($expected, trim($html), $source);
    }
}
