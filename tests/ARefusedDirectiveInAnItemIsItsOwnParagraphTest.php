<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A refused directive ends at its own line, so the next line is a second paragraph
 * and the list goes loose (PART 10 I2, §17 L2, carve#4619).
 */
final class ARefusedDirectiveInAnItemIsItsOwnParagraphTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'a caption-shaped line' => ["- {{ _x_ @code:js }}\n  ^ c\n", '<ul><li><p>{{ _x_ @code:js }}</p><p>^ c</p></li></ul>'];
        yield 'plain text' => ["1. {{ _x_ @code:js }}\n   q\n", '<ol><li><p>{{ _x_ @code:js }}</p><p>q</p></li></ol>'];
        yield 'a lone directive stays tight' => ["- {{ _x_ @code:js }}\n", '<ul><li>{{ _x_ @code:js }}</li></ul>'];
        yield 'a paragraph above it stays tight' => ["- x\n  {{ _x_ @code:js }}\n", "<ul><li>x\n{{ <u>x</u><span class=\"mention\"><strong>@code</strong></span>:js }}</li></ul>"];
    }

    #[DataProvider('documents')]
    public function testRender(string $source, string $expected): void
    {
        $html = (string)preg_replace('/>\s+</', '><', (new CarveConverter())->convert($source));

        self::assertSame($expected, trim($html), $source);
    }
}
